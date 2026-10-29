<?php

namespace Tests\Feature\Clinical;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Surgery;
use App\Models\SurgeryChecklistStep;
use App\Models\SusAihFile;
use App\Models\SusBpaFile;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

/**
 * Step 6b.8: the whole SUS journey on a brand-new client, only through the HTTP routes people use (ADR-0011): the
 * network admin imports the SIGTAP and fills the unit's, the professionals' and the patient's SUS data → a SUS
 * consultation with its CID (BPA) → an emergency that becomes an admission with a surgery, its AIH request report, the
 * AIH number and the discharge with the CID → the BPA and AIH files of the competence → the manager's return, the AIH
 * rejected, reopened, closed again and presented in the next competence → the manager's report by competence, with no
 * patient name nor clinical text in the audit.
 */
class SusJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    /** The emailed password link sets the password and confirms the address; here both are applied directly. */
    private function confirmed(string $email): User
    {
        $user = User::where('email', $email)->sole();
        $user->forceFill(['email_verified_at' => now(), 'password' => 'password'])->save();

        return $user;
    }

    /** The official ZIP of the SIGTAP (the real 10/2026 cut in the fixtures), as the 11/2026 competence. */
    private function sigtapZip(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sigtap').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach (glob(base_path('tests/Fixtures/sigtap/*.txt')) ?: [] as $file) {
            $zip->addFromString(basename($file), str_contains($file, '_layout') ? (string) file_get_contents($file) : str_replace('202610', '202611', (string) file_get_contents($file)));
        }
        $zip->close();

        return new UploadedFile($path, 'TabelaUnificada_202611.zip', 'application/zip', null, true);
    }

    public function test_the_sus_journey_on_a_new_client_presents_returns_and_reports(): void
    {
        Queue::fake();
        Notification::fake();
        Storage::fake('local');
        $this->at('2026-11-16 10:00');

        // 1. Provisioning and structure: the SIGTAP of the competence, the consulting rooms, the emergency room and a
        // surgical ward with its SUS bed specialty, a bed, and the doctor's function with its CBO.
        $this->artisan('tenant:provision', ['name' => 'Rede SUS', '--admin-email' => 'admin@sus.example', '--admin-name' => 'Admin SUS'])->assertSuccessful();
        $admin = $this->confirmed('admin@sus.example');
        $this->actingAs($admin)->post(route('sigtap.store'), ['file' => $this->sigtapZip()])->assertSessionHasNoErrors();
        $this->post(route('units.store'), ['name' => 'Hospital Municipal Sul', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo'])->assertSessionHasNoErrors();
        $unit = Unit::withoutTenantScope()->sole();
        foreach (['Consultórios', 'Pronto-socorro', 'Clínica cirúrgica'] as $sector) {
            $this->post(route('units.sectors.store', $unit), ['name' => $sector])->assertSessionHasNoErrors();
        }
        $ward = Sector::withoutTenantScope()->where('name', 'Clínica cirúrgica')->sole();
        $this->put(route('units.sectors.update', [$unit, $ward]), ['name' => 'Clínica cirúrgica', 'sus_bed_specialty' => '01'])->assertSessionHasNoErrors();
        $this->post(route('beds.store', $unit), ['sector_id' => $ward->id, 'room' => '2', 'code' => 'CC-2', 'type' => 'ward'])->assertSessionHasNoErrors();
        $bed = Bed::withoutTenantScope()->sole();
        $this->post(route('job-functions.store'), ['name' => 'Médico', 'attends_patients' => true, 'cbo' => '225125'])->assertSessionHasNoErrors();
        $function = JobFunction::withoutTenantScope()->sole();

        // 2. The catalogs and the billable items with their SIGTAP codes: the consultation (BPA), the umbilical hernia repair (an
        // AIH principal); the emergency consultation is in the AIH's package.
        $this->post(route('surgical-procedures.store'), ['name' => 'Herniorrafia umbilical', 'duration_minutes' => 60, 'size' => 'medium', 'code' => '31009204'])->assertSessionHasNoErrors();
        $this->post(route('operating-rooms.store'), ['unit_id' => $unit->id, 'name' => 'Sala 1'])->assertSessionHasNoErrors();
        [$procedure, $room] = [DB::table('surgical_procedures')->value('id'), DB::table('operating_rooms')->value('id')];
        foreach ([
            ['kind' => 'consultation', 'name' => 'Consulta em consultório', 'sigtap_code' => '0301010072'],
            ['kind' => 'emergency_consultation', 'name' => 'Consulta em pronto-socorro', 'sigtap_code' => '0301010072'],
            ['kind' => 'procedure', 'reference_id' => $procedure, 'name' => 'Herniorrafia umbilical', 'sigtap_code' => '0407040129'],
        ] as $item) {
            $this->post(route('billing-items.store'), $item)->assertSessionHasNoErrors();
        }

        // 3. People by invitation; the professionals with CRM and the Cartão Nacional de Saúde, qualified in the unit.
        $users = [];
        foreach (['recepcao' => 'reception', 'leitos' => 'bed_management', 'centro' => 'surgical_center', 'faturamento' => 'billing', 'gestao' => 'unit_manager'] as $key => $role) {
            $this->actingAs($admin)->post(route('people.store'), ['name' => ucfirst($key), 'email' => "{$key}@sus.example", 'role' => $role, 'unit_id' => $unit->id])->assertSessionHasNoErrors();
            $users[$key] = $this->confirmed("{$key}@sus.example");
        }
        $staff = [];
        foreach (['medico' => ['Dr. Davi', '52222', '700123456780900', ['Consultórios', 'Pronto-socorro', 'Clínica cirúrgica']], 'cirurgia' => ['Dra. Íris', '58888', '700000000000005', ['Clínica cirúrgica']]] as $key => [$name, $number, $cns, $sectors]) {
            $this->actingAs($admin)->post(route('professionals.store'), [
                'name' => $name, 'base_unit_id' => $unit->id, 'council' => 'CRM', 'council_number' => $number, 'council_uf' => 'SP',
                'external_id' => strtoupper($key), 'email' => "{$key}@sus.example", 'phone' => '', 'cpf' => '', 'cns' => $cns,
            ])->assertSessionHasNoErrors();
            $professional = Professional::withoutTenantScope()->where('name', $name)->sole();
            $this->post(route('professionals.qualifications.store', $professional), [
                'unit_id' => $unit->id, 'job_function_id' => $function->id, 'valid_from' => '2026-01-01', 'valid_until' => '',
                'sector_ids' => Sector::withoutTenantScope()->whereIn('name', $sectors)->pluck('id')->all(),
            ])->assertSessionHasNoErrors();
            $this->post(route('qualifications.transition', Qualification::withoutTenantScope()->where('professional_id', $professional->id)->sole()), ['status' => 'approved'])->assertSessionHasNoErrors();
            $this->post(route('professionals.invite', $professional))->assertSessionHasNoErrors();
            $staff[$key] = [$professional, $this->confirmed("{$key}@sus.example")];
        }
        [[$doctor, $doctorUser], [$surgeon, $surgeonUser]] = [$staff['medico'], $staff['cirurgia']];

        // 4. The unit's SUS data: the BPA header (CNES, CNPJ, acronym, the manager) and the AIH's (municipality, issuer,
        // clinical director).
        $this->actingAs($admin)->put(route('units.update', $unit), [
            'name' => 'Hospital Municipal Sul', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo', 'cnes' => '2345678', 'cnpj' => '12345678000190', 'sus_acronym' => 'HMS',
            'sus_manager_name' => 'Secretaria Municipal de Saúde', 'sus_manager_level' => 'M', 'municipality' => '3550308', 'aih_issuer' => 'M355030001', 'clinical_director_id' => $doctor->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($users['gestao'])->post(route('schedules.rules.store', $unit), ['professional_id' => $doctor->id, 'weekday' => 1, 'start' => '08:00', 'end' => '12:00', 'slot_minutes' => 20])->assertSessionHasNoErrors();

        // 5. The patient with the SUS registration: CNS, race/color, nationality and the address of the AIH.
        $this->actingAs($users['recepcao'])->post(route('patients.store'), [
            'registered_unit_id' => $unit->id, 'name' => 'Rosalina Prates Quintela', 'birth_date' => '1962-04-09', 'sex' => 'female', 'cns' => '123456789010000',
            'mother_name' => 'Benedita Prates', 'phone' => '(11) 97654-3210', 'race_color' => '01', 'nationality' => '010', 'address_street_type' => '081',
            'address_street' => 'dos Ipês', 'address_number' => '45', 'address_district' => 'Jardim Sul', 'address_municipality' => '3550308', 'address_zip' => '04567000',
        ])->assertSessionHasNoErrors();
        $patient = Patient::withoutTenantScope()->sole();

        // 6. The SUS consultation, closed with its CID: its account goes to the BPA.
        $this->post(route('appointments.store', $unit), ['professional_id' => $doctor->id, 'patient_id' => $patient->id, 'starts_at' => '2026-11-16T08:20'])->assertSessionHasNoErrors();
        $this->at('2026-11-16 11:10');
        $appointment = Appointment::withoutTenantScope()->sole();
        $this->post(route('appointments.arrive', $appointment), ['modality' => 'sus'])->assertSessionHasNoErrors();
        $this->actingAs($doctorUser)->post(route('visits.open', $appointment))->assertRedirect();
        $this->post(route('visits.close', Visit::withoutTenantScope()->sole()), ['cid_principal' => 'K42.9'])->assertSessionHasNoErrors();
        $outpatient = BillingAccount::withoutTenantScope()->whereNotNull('appointment_id')->sole();

        // 7. The emergency that becomes an admission: the AIH request report signed by the responsible doctor, the
        // surgery and the discharge with the CID.
        $this->actingAs($users['recepcao'])->post(route('emergency.arrive', $unit), ['patient_id' => $patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($doctorUser)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Abaulamento umbilical doloroso.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->post(route('visits.close', Visit::withoutTenantScope()->whereNotNull('emergency_episode_id')->sole()), ['cid_principal' => 'K42.9', 'outcome' => 'admission_requested', 'requested_sector_id' => $ward->id, 'responsible_professional_id' => $doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->sole();
        $this->actingAs($users['leitos'])->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();
        $this->actingAs($doctorUser)->post(route('ward.aih-request.store', $admission), [
            'procedure_code' => '0407040129', 'cid_principal' => 'K42.9', 'justification' => 'Hérnia umbilical encarcerada redutível, dor progressiva.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $this->post(route('surgery-orders.admission.store', $admission), [
            'surgical_procedure_id' => $procedure, 'laterality' => 'right', 'urgency' => 'elective', 'desired_date' => '2026-11-17',
            'indication' => 'Hérnia umbilical encarcerada redutível.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $surgery = Surgery::withoutTenantScope()->sole();
        $this->actingAs($users['centro'])->post(route('surgery-schedule.schedule', $surgery), ['operating_room_id' => $room, 'start' => '2026-11-17T08:00', 'duration_minutes' => 60, 'surgeon_id' => $surgeon->id])->assertSessionHasNoErrors();
        $this->at('2026-11-17 11:00');
        $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('termo.pdf', self::PDF)])->assertSessionHasNoErrors();
        foreach (['sign_in', 'time_out', 'sign_out'] as $step) {
            $answers = collect(SurgeryChecklistStep::STEPS[$step]['items'])->map(fn (array $item) => $item[1][0])->all();
            $this->post(route('surgery-checklist.confirm', ['surgery' => $surgery, 'step' => $step]), ['answers' => $answers])->assertSessionHasNoErrors();
        }
        $this->actingAs($surgeonUser)->post(route('surgery-description.store', $surgery), [
            'findings' => 'Hérnia indireta.', 'technique' => 'Lichtenstein com tela.', 'complications' => 'Nenhuma.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $this->at('2026-11-18 14:00');
        $this->actingAs($doctorUser)->post(route('ward.discharge', $admission), ['cid_principal' => 'K42.9', 'type' => 'improved', 'summary' => 'Ferida limpa, deambulando.', 'password' => 'password'])->assertSessionHasNoErrors();
        $stay = BillingAccount::withoutTenantScope()->whereNotNull('emergency_episode_id')->sole();

        // 8. Billing: the AIH number authorized by the manager, nothing pending, both accounts closed.
        $this->actingAs($users['faturamento'])->post(route('billing-accounts.aih-number', $stay), ['aih_number' => '3526100000012', 'aih_authorizer_cns' => '700123456780900'])->assertSessionHasNoErrors();
        foreach ([$outpatient, $stay] as $account) {
            $this->get(route('billing-accounts.show', $account))->assertInertia(fn (Assert $page) => $page->where('susPending', []));
            $this->post(route('billing-accounts.close', $account))->assertSessionHasNoErrors();
        }
        $price = fn (BillingAccount $account) => (int) DB::table('billing_account_items')->where('billing_account_id', $account->id)->sum(DB::raw('quantity * coalesce(unit_price_cents, 0)'));
        [$bpaValue, $aihValue] = [$price($outpatient), $price($stay)];
        $this->assertGreaterThan(0, $bpaValue);
        $this->assertGreaterThan(0, $aihValue);

        // 9. The files of the competence: the BPA with the consultation, the AIH with the stay, each downloaded as generated.
        $this->at('2026-12-02 13:00');
        $this->post(route('sus-bpa.store'), ['unit_id' => $unit->id, 'competence' => '2026-11'])->assertSessionHasNoErrors();
        $this->post(route('sus-aih.store'), ['unit_id' => $unit->id, 'competence' => '2026-11'])->assertSessionHasNoErrors();
        [$bpa, $aih] = [SusBpaFile::withoutTenantScope()->sole(), SusAihFile::withoutTenantScope()->sole()];
        $this->assertSame([1, 1], [$bpa->account_count, $aih->account_count]);
        $aihText = (string) Storage::disk('local')->get($aih->path);
        $this->assertSame(['3526100000012', '0407040129', 'K429'], [substr($aihText, 43, 13), substr($aihText, 160, 10), substr($aihText, 249, 4)]);
        $this->assertStringContainsString('123456789010000', (string) Storage::disk('local')->get($bpa->path));
        $this->get(route('sus-bpa.download', $bpa))->assertOk();
        $this->get(route('sus-aih.download', $aih))->assertOk();

        // 10. The manager's return: the BPA line approved; the AIH rejected, reopened, closed again and presented in the
        // next competence, where it is approved.
        $line = (int) DB::table('sus_bpa_file_lines')->where('sus_bpa_file_id', $bpa->id)->value('id');
        $this->post(route('sus-bpa.returns.store', $bpa), ['line_id' => $line, 'approved' => '1'])->assertSessionHasNoErrors();
        $presentation = (int) DB::table('sus_aih_file_accounts')->where('sus_aih_file_id', $aih->id)->value('id');
        $this->post(route('sus-aih.returns.store', $aih), ['presentation_id' => $presentation, 'approved' => '0', 'reason' => 'Laudo sem o CID secundário K42.9 justificado.'])->assertSessionHasNoErrors();
        $this->post(route('billing-accounts.reopen', $outpatient))->assertSessionHasErrors('reopen', null, 'reopen');
        $this->post(route('billing-accounts.reopen', $stay))->assertSessionHasNoErrors();
        $this->post(route('billing-accounts.close', $stay))->assertSessionHasNoErrors();
        $this->post(route('sus-aih.store'), ['unit_id' => $unit->id, 'competence' => '2026-12'])->assertSessionHasNoErrors();
        $again = SusAihFile::withoutTenantScope()->where('competence', '2026-12-01')->sole();
        $this->assertSame('3526100000012', substr((string) Storage::disk('local')->get($again->path), 43, 13));
        $this->post(route('sus-aih.returns.store', $again), ['presentation_id' => (int) DB::table('sus_aih_file_accounts')->where('sus_aih_file_id', $again->id)->value('id'), 'approved' => '1'])->assertSessionHasNoErrors();
        $this->post(route('billing-accounts.reopen', $stay))->assertSessionHasErrors('reopen', null, 'reopen');

        // 11. The manager's report by competence: November with the approved BPA and the rejected AIH and its reason,
        // December with the AIH approved.
        $report = fn (string $competence) => $this->actingAs($users['gestao'])->get(route('billing-reports.index', ['unit' => $unit->id, 'competence' => $competence]));
        $report('2026-11')->assertInertia(fn (Assert $page) => $page->where('sus.competences', ['2026-12', '2026-11'])
            ->where('sus.bpa.presented', ['quantity' => 1, 'totalCents' => $bpaValue])->where('sus.bpa.approved', ['quantity' => 1, 'totalCents' => $bpaValue])
            ->where('sus.aih.presented', ['quantity' => 1, 'totalCents' => $aihValue])->where('sus.aih.rejected', ['quantity' => 1, 'totalCents' => $aihValue])
            ->where('sus.rejections', [['label' => 'AIH 3526100000012', 'quantity' => 1, 'totalCents' => $aihValue, 'reason' => 'Laudo sem o CID secundário K42.9 justificado.']]));
        $report('2026-12')->assertInertia(fn (Assert $page) => $page->where('sus.bpa.presented.quantity', 0)
            ->where('sus.aih.approved', ['quantity' => 1, 'totalCents' => $aihValue])->where('sus.rejections', []));

        // 12. Billing never reads the record; the audit keeps ids only: no patient name, CID, procedure nor reason.
        $this->actingAs($users['faturamento'])->get(route('visits.show', Visit::withoutTenantScope()->whereNotNull('appointment_id')->sole()))->assertForbidden();
        $this->assertTrue(DB::table('clinical_reads')->where('user_id', $users['gestao']->id)->where('reason', 'billing')->exists());
        $trail = AuditEvent::query()->pluck('context')->toJson();
        foreach (['Rosalina', 'Quintela', 'K42', 'K429', '0407040129', 'Laudo sem', 'encarcerada', 'Lichtenstein', 'deambulando'] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $trail);
        }
    }
}
