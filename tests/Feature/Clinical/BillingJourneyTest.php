<?php

namespace Tests\Feature\Clinical;

use App\Billing\TissLotXml;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\BillingItem;
use App\Models\BillingLot;
use App\Models\EmergencyEpisode;
use App\Models\ExamOrderItem;
use App\Models\Insurer;
use App\Models\InsurerContract;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PriceTable;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\StockLot;
use App\Models\Surgery;
use App\Models\SurgeryChecklistStep;
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

/**
 * Step 6.7: the whole billing journey on a brand-new client, only through the HTTP routes people use (ADR-0010): the
 * network admin's prices, insurer and contract → an insurance consultation with an imaging exam → an emergency that
 * becomes an admission with a surgery, its material and the discharge → the authorization, the closing, the two TISS
 * lots (SP/SADT and hospitalization summary) valid against the schema → the insurer's return with a glosa, its appeal
 * and answer → a private consultation received with its receipt → the manager's report reconciling it all, with no
 * patient name in the files, the audit nor the report.
 */
class BillingJourneyTest extends TestCase
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

    private function confirmStep(Surgery $surgery, string $step): void
    {
        $answers = collect(SurgeryChecklistStep::STEPS[$step]['items'])->map(fn (array $item) => $item[1][0])->all();
        $this->post(route('surgery-checklist.confirm', ['surgery' => $surgery, 'step' => $step]), ['answers' => $answers])->assertSessionHasNoErrors();
    }

    public function test_the_billing_journey_on_a_new_client_reconciles(): void
    {
        Queue::fake();
        Notification::fake();
        Storage::fake('local');
        $this->at('2026-11-16 10:00');

        // 1. Provisioning and structure: a unit with its CNES, the consulting rooms, the emergency room and a ward bed.
        $this->artisan('tenant:provision', ['name' => 'Rede Faturamento', '--admin-email' => 'admin@fx.example', '--admin-name' => 'Admin Fx'])->assertSuccessful();
        $admin = $this->confirmed('admin@fx.example');
        $this->actingAs($admin)->post(route('units.store'), ['name' => 'Hospital Leste', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo'])->assertSessionHasNoErrors();
        $unit = Unit::withoutTenantScope()->sole();
        $this->put(route('units.update', $unit), ['name' => 'Hospital Leste', 'cnes' => '7654321'])->assertSessionHasNoErrors();
        foreach (['Consultórios', 'Pronto-socorro', 'Clínica cirúrgica'] as $sector) {
            $this->post(route('units.sectors.store', $unit), ['name' => $sector])->assertSessionHasNoErrors();
        }
        $ward = Sector::withoutTenantScope()->where('name', 'Clínica cirúrgica')->sole();
        $this->post(route('beds.store', $unit), ['sector_id' => $ward->id, 'room' => '3', 'code' => 'CC-3', 'type' => 'ward'])->assertSessionHasNoErrors();
        $bed = Bed::withoutTenantScope()->sole();
        $this->post(route('job-functions.store'), ['name' => 'Médico', 'attends_patients' => true, 'cbo' => '225125'])->assertSessionHasNoErrors();
        $function = JobFunction::withoutTenantScope()->sole();

        // 2. The care catalogs: an imaging exam, a surgical procedure, a room and a material.
        $this->post(route('exams.store'), ['type' => 'imaging', 'name' => 'Raio X de tórax', 'specimen' => 'Tórax', 'preparation' => '', 'code' => '40805018', 'analytes' => []])->assertSessionHasNoErrors();
        $this->post(route('surgical-procedures.store'), ['name' => 'Herniorrafia inguinal', 'duration_minutes' => 60, 'size' => 'medium', 'code' => '31009204'])->assertSessionHasNoErrors();
        $this->post(route('operating-rooms.store'), ['unit_id' => $unit->id, 'name' => 'Sala 1'])->assertSessionHasNoErrors();
        $this->post(route('materials.store'), ['name' => 'Tela de polipropileno', 'stock_unit' => 'unidade'])->assertSessionHasNoErrors();
        [$exam, $procedure, $room, $mesh] = [DB::table('exams')->value('id'), DB::table('surgical_procedures')->value('id'), DB::table('operating_rooms')->value('id'), DB::table('materials')->value('id')];

        // 3. The billable items, the price tables (the private one and the insurer's), the insurer and its contract,
        // which asks for the insurer's authorization of the ward daily rate.
        foreach ([
            ['kind' => 'consultation', 'name' => 'Consulta em consultório', 'code' => '10101012'],
            ['kind' => 'emergency_consultation', 'name' => 'Consulta em pronto-socorro', 'code' => '10101039'],
            ['kind' => 'exam', 'reference_id' => $exam, 'name' => 'Raio X de tórax', 'code' => '40805018'],
            ['kind' => 'procedure', 'reference_id' => $procedure, 'name' => 'Herniorrafia inguinal', 'code' => '31009204'],
            ['kind' => 'material', 'reference_id' => $mesh, 'name' => 'Tela de polipropileno', 'code' => '70012345'],
            ['kind' => 'daily', 'bed_type' => 'ward', 'name' => 'Diária de enfermaria', 'code' => '60000775'],
        ] as $item) {
            $this->post(route('billing-items.store'), $item)->assertSessionHasNoErrors();
        }
        $items = BillingItem::withoutTenantScope()->pluck('id', 'kind');
        $this->post(route('price-tables.store'), ['name' => 'Particular'])->assertSessionHasNoErrors();
        $this->post(route('price-tables.store'), ['name' => 'Convênios'])->assertSessionHasNoErrors();
        [$private, $insurance] = [PriceTable::withoutTenantScope()->where('name', 'Particular')->sole(), PriceTable::withoutTenantScope()->where('name', 'Convênios')->sole()];
        $this->post(route('price-tables.private', $private))->assertSessionHasNoErrors();
        $this->post(route('price-tables.prices.store', $private), ['billing_item_id' => $items['consultation'], 'price' => '250,00', 'valid_from' => '2026-01-01'])->assertSessionHasNoErrors();
        foreach (['consultation' => '150,00', 'emergency_consultation' => '180,00', 'exam' => '60,00', 'procedure' => '1.200,00', 'material' => '300,00', 'daily' => '400,00'] as $kind => $price) {
            $this->post(route('price-tables.prices.store', $insurance), ['billing_item_id' => $items[$kind], 'price' => $price, 'valid_from' => '2026-01-01'])->assertSessionHasNoErrors();
        }
        $this->post(route('insurers.store'), ['name' => 'Saúde Leste', 'ans_code' => '654321'])->assertSessionHasNoErrors();
        $insurer = Insurer::withoutTenantScope()->sole();
        $this->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $insurance->id, 'provider_code' => '00098765'])->assertSessionHasNoErrors();
        $this->put(route('insurer-contracts.authorizations', InsurerContract::withoutTenantScope()->sole()), ['billing_item_ids' => [$items['daily']]])->assertSessionHasNoErrors();

        // 4. People by invitation: reception, bed management, surgical center, pharmacy, billing and the manager.
        $users = [];
        foreach (['recepcao' => 'reception', 'leitos' => 'bed_management', 'centro' => 'surgical_center', 'farmacia' => 'pharmacy',
            'faturamento' => 'billing', 'gestao' => 'unit_manager'] as $key => $role) {
            $this->actingAs($admin)->post(route('people.store'), ['name' => ucfirst($key), 'email' => "{$key}@fx.example", 'role' => $role, 'unit_id' => $unit->id])->assertSessionHasNoErrors();
            $users[$key] = $this->confirmed("{$key}@fx.example");
        }

        // 5. Professionals with CRM, qualified in the unit with the CBO the TISS asks for: the clinician and the surgeon.
        $staff = [];
        foreach (['medico' => ['Dr. Caio', '51111', ['Consultórios', 'Pronto-socorro']], 'cirurgia' => ['Dra. Lia', '57777', ['Clínica cirúrgica']]] as $key => [$name, $number, $sectors]) {
            $this->actingAs($admin)->post(route('professionals.store'), [
                'name' => $name, 'base_unit_id' => $unit->id, 'council' => 'CRM', 'council_number' => $number, 'council_uf' => 'SP',
                'external_id' => strtoupper($key), 'email' => "{$key}@fx.example", 'phone' => '', 'cpf' => '',
            ])->assertSessionHasNoErrors();
            $professional = Professional::withoutTenantScope()->where('name', $name)->sole();
            $this->post(route('professionals.qualifications.store', $professional), [
                'unit_id' => $unit->id, 'job_function_id' => $function->id, 'valid_from' => '2026-01-01', 'valid_until' => '',
                'sector_ids' => Sector::withoutTenantScope()->whereIn('name', $sectors)->pluck('id')->all(),
            ])->assertSessionHasNoErrors();
            $this->post(route('qualifications.transition', Qualification::withoutTenantScope()->where('professional_id', $professional->id)->sole()), ['status' => 'approved'])->assertSessionHasNoErrors();
            $this->post(route('professionals.invite', $professional))->assertSessionHasNoErrors();
            $staff[$key] = [$professional, $this->confirmed("{$key}@fx.example")];
        }
        [[$doctor, $doctorUser], [$surgeon, $surgeonUser]] = [$staff['medico'], $staff['cirurgia']];
        // The clinician also reports the imaging (a report is a CRM doctor's, step 5).
        $this->actingAs($admin)->post(route('people.roles.store', Membership::withoutGlobalScopes()->where('user_id', $doctorUser->id)->sole()), ['role' => 'imaging', 'unit_id' => $unit->id])->assertSessionHasNoErrors();
        $this->actingAs($users['gestao'])->post(route('schedules.rules.store', $unit), ['professional_id' => $doctor->id, 'weekday' => 1, 'start' => '08:00', 'end' => '12:00', 'slot_minutes' => 20])->assertSessionHasNoErrors();

        // 6. The insurance consultation with an imaging exam, reported before the visit's account is closed.
        $card = ['modality' => 'insurance', 'insurer_id' => $insurer->id, 'insurance_card_number' => '0088 7766', 'insurance_card_valid_until' => '2027-12-31'];
        $this->actingAs($users['recepcao'])->post(route('patients.store'), ['registered_unit_id' => $unit->id, 'name' => 'Teodora Bastos Vilela', 'birth_date' => '1970-02-11', 'sex' => 'female'])->assertSessionHasNoErrors();
        $patient = Patient::withoutTenantScope()->sole();
        $this->post(route('appointments.store', $unit), ['professional_id' => $doctor->id, 'patient_id' => $patient->id, 'starts_at' => '2026-11-16T08:20'])->assertSessionHasNoErrors();
        $this->at('2026-11-16 11:10');
        $this->post(route('appointments.arrive', Appointment::withoutTenantScope()->sole()), $card)->assertSessionHasNoErrors();
        $this->actingAs($doctorUser)->post(route('visits.open', Appointment::withoutTenantScope()->sole()))->assertRedirect();
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('exam-orders.visit.store', $visit), ['exam_ids' => [$exam], 'priority' => 'routine', 'indication' => 'Tosse persistente.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->post(route('visits.close', $visit))->assertSessionHasNoErrors();
        $xray = ExamOrderItem::withoutTenantScope()->sole();
        $this->actingAs($doctorUser)->post(route('exam-queue.collect', $xray))->assertSessionHasNoErrors();
        $this->post(route('exam-results.release', $xray), ['version' => 1, 'report' => 'Pulmões sem consolidação.', 'file' => UploadedFile::fake()->createWithContent('rx.pdf', self::PDF), 'password' => 'password'])->assertRedirect()->assertSessionHasNoErrors();
        $outpatient = BillingAccount::withoutTenantScope()->whereNotNull('appointment_id')->sole();

        // 7. The emergency that becomes an admission, a surgery with its material, and the discharge two days later.
        $this->actingAs($users['recepcao'])->post(route('emergency.arrive', $unit), ['patient_id' => $patient->id, ...$card])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($doctorUser)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Abaulamento inguinal doloroso.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->post(route('visits.close', Visit::withoutTenantScope()->whereNotNull('emergency_episode_id')->sole()), ['outcome' => 'admission_requested', 'requested_sector_id' => $ward->id, 'responsible_professional_id' => $doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->sole();
        $this->actingAs($users['leitos'])->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();
        $this->actingAs($users['farmacia'])->post(route('stock.entries.store'), ['unit_id' => $unit->id, 'material_id' => $mesh, 'lot_code' => 'TP-1', 'expires_on' => '2028-06-30', 'quantity' => 10])->assertSessionHasNoErrors();
        $lot = StockLot::withoutTenantScope()->sole();
        $this->actingAs($doctorUser)->post(route('surgery-orders.admission.store', $admission), [
            'surgical_procedure_id' => $procedure, 'laterality' => 'right', 'urgency' => 'elective', 'desired_date' => '2026-11-17',
            'indication' => 'Hérnia inguinal encarcerada redutível.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $surgery = Surgery::withoutTenantScope()->sole();
        $this->actingAs($users['centro'])->post(route('surgery-schedule.schedule', $surgery), ['operating_room_id' => $room, 'start' => '2026-11-17T08:00', 'duration_minutes' => 60, 'surgeon_id' => $surgeon->id])->assertSessionHasNoErrors();
        $this->at('2026-11-17 11:00');
        $this->post(route('surgery-checklist.consent', $surgery), ['file' => UploadedFile::fake()->createWithContent('termo.pdf', self::PDF)])->assertSessionHasNoErrors();
        foreach (['sign_in', 'time_out', 'sign_out'] as $step) {
            $this->confirmStep($surgery, $step);
        }
        $this->post(route('surgery-materials.store', $surgery), ['stock_lot_id' => $lot->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->actingAs($surgeonUser)->post(route('surgery-description.store', $surgery), [
            'findings' => 'Hérnia indireta.', 'technique' => 'Lichtenstein com tela.', 'complications' => 'Nenhuma.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $this->at('2026-11-18 14:00');
        $this->actingAs($doctorUser)->post(route('ward.discharge', $admission), ['type' => 'improved', 'summary' => 'Ferida limpa, deambulando.', 'password' => 'password'])->assertSessionHasNoErrors();
        $stay = BillingAccount::withoutTenantScope()->whereNotNull('emergency_episode_id')->sole();

        // 8. Billing: the stay's accounts as generated, the insurer's password for the daily rates, then both closed.
        $this->actingAs($users['faturamento'])->get(route('billing-accounts.show', $outpatient))->assertInertia(fn (Assert $page) => $page->where('account.totalCents', 21000));
        $this->get(route('billing-accounts.show', $stay))->assertInertia(fn (Assert $page) => $page->has('items', 5));
        $this->post(route('billing-accounts.close', $stay))->assertSessionHasErrorsIn('close', 'close');
        $dailies = DB::table('billing_account_items')->where('billing_account_id', $stay->id)->where('billing_item_id', $items['daily'])->pluck('id')->all();
        $this->post(route('billing-accounts.authorizations.store', $stay), ['item_ids' => $dailies, 'status' => 'authorized', 'number' => 'SL-2026-0042', 'valid_until' => '2026-12-31'])->assertSessionHasNoErrors();
        foreach ([$outpatient, $stay] as $account) {
            $this->post(route('billing-accounts.close', $account))->assertSessionHasNoErrors();
        }
        // 2 daily rates (16 to 18), the emergency consultation, the surgery and the mesh.
        $stayTotal = 2 * 40000 + 18000 + 120000 + 30000;
        $this->get(route('billing-accounts.show', $stay))->assertInertia(fn (Assert $page) => $page->where('account.totalCents', $stayTotal));

        // 9. The two TISS lots of the competence, each file valid against the schema and carrying the card, not the name.
        foreach (['sp_sadt', 'hospitalization'] as $type) {
            $this->post(route('billing-lots.store'), ['unit_id' => $unit->id, 'insurer_id' => $insurer->id, 'competence' => '2026-11', 'guide_type' => $type])->assertSessionHasNoErrors();
            $lot = BillingLot::withoutTenantScope()->where('guide_type', $type)->sole();
            $this->post(route('billing-lots.file', $lot))->assertSessionHasNoErrors();
            $xml = (string) Storage::disk('local')->get($lot->fresh()->file_path);
            $this->assertSame([], TissLotXml::schemaErrors($xml));
            $this->assertStringContainsString('0088 7766', $xml);
            $this->assertStringNotContainsString('Teodora', $xml);
        }
        [$consultationLot, $stayLot] = [BillingLot::withoutTenantScope()->where('guide_type', 'sp_sadt')->sole(), BillingLot::withoutTenantScope()->where('guide_type', 'hospitalization')->sole()];
        $this->assertSame([21000, $stayTotal], [$consultationLot->total_cents, $stayLot->total_cents]);
        $this->assertStringContainsString('SL-2026-0042', (string) Storage::disk('local')->get($stayLot->fresh()->file_path));

        // 10. The insurer's returns: the exam glossed (value above the contract), appealed and partly recovered; the stay paid.
        $this->at('2026-12-10 13:00');
        $examItem = DB::table('billing_account_items')->where('billing_account_id', $outpatient->id)->where('billing_item_id', $items['exam'])->value('id');
        $this->post(route('billing-lots.return.store', $consultationLot), ['glosas' => [$examItem => ['amount' => '20,00', 'code' => '1705', 'reason' => 'Acima da tabela.']]])->assertSessionHasNoErrors();
        $this->post(route('billing-lots.return.store', $stayLot), [])->assertSessionHasNoErrors();
        $this->post(route('billing-lots.appeals.store', $consultationLot), ['protocol' => '202612001', 'items' => [$examItem => ['amount' => '', 'justification' => 'Valor da tabela do contrato vigente.']]])->assertSessionHasNoErrors();
        $appeal = DB::table('billing_glosa_appeals')->sole();
        $this->assertSame([], TissLotXml::schemaErrors((string) Storage::disk('local')->get($appeal->file_path)));
        $this->post(route('billing-lots.appeals.results', $consultationLot), ['results' => [$examItem => '15,00']])->assertSessionHasNoErrors();
        $this->get(route('billing-lots.index', ['unit' => $unit->id]))->assertInertia(fn (Assert $page) => $page
            ->where('lots.1.return', ['paidCents' => 19000, 'glossedCents' => 2000, 'appealingCents' => 0, 'recoveredCents' => 1500])
            ->where('lots.0.return', ['paidCents' => $stayTotal, 'glossedCents' => 0, 'appealingCents' => 0, 'recoveredCents' => 0]));

        // 11. A private consultation, closed and received, with its receipt.
        $this->actingAs($users['recepcao'])->post(route('appointments.store', $unit), ['professional_id' => $doctor->id, 'patient_id' => $patient->id, 'starts_at' => '2026-12-14T09:00'])->assertSessionHasNoErrors();
        $this->at('2026-12-14 11:50');
        $privateAppointment = Appointment::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('appointments.arrive', $privateAppointment), ['modality' => 'private'])->assertSessionHasNoErrors();
        $this->actingAs($doctorUser)->post(route('visits.open', $privateAppointment))->assertRedirect();
        $this->post(route('visits.close', Visit::withoutTenantScope()->where('appointment_id', $privateAppointment->id)->sole()))->assertSessionHasNoErrors();
        $privateAccount = BillingAccount::withoutTenantScope()->where('appointment_id', $privateAppointment->id)->sole();
        $this->actingAs($users['faturamento'])->post(route('billing-accounts.close', $privateAccount))->assertSessionHasNoErrors();
        $this->post(route('billing-accounts.payment.store', $privateAccount), ['method' => 'pix'])->assertSessionHasNoErrors();
        $this->get(route('billing-accounts.receipt', $privateAccount))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // 12. The manager's report reconciles the period: production by modality, nothing pending, glosas and received.
        $this->actingAs($users['gestao'])->get(route('billing-reports.index', ['unit' => $unit->id, 'from' => '2026-11-01', 'to' => '2026-12-31']))->assertInertia(fn (Assert $page) => $page
            ->where('production', [
                ['group' => 'Convênio · Saúde Leste', 'accounts' => 2, 'items' => 7, 'totalCents' => 21000 + $stayTotal, 'pricelessItems' => 0],
                ['group' => 'Particular', 'accounts' => 1, 'items' => 1, 'totalCents' => 25000, 'pricelessItems' => 0],
            ])
            ->where('pending', fn ($pending) => collect($pending)->sum('accounts') === 0)
            ->where('glosas', [['code' => '1705', 'term' => 'VALOR APRESENTADO A MAIOR', 'items' => 1, 'glossedCents' => 2000, 'appealingCents' => 0, 'recoveredCents' => 1500]])
            ->where('received', [
                ['group' => 'Particular', 'label' => 'Pagamentos recebidos', 'count' => 1, 'totalCents' => 25000],
                ['group' => 'Convênio · Saúde Leste', 'label' => 'Pago no retorno (lotes)', 'count' => 2, 'totalCents' => 19000 + $stayTotal],
                ['group' => 'Convênio · Saúde Leste', 'label' => 'Recuperado em recurso (itens)', 'count' => 1, 'totalCents' => 1500],
            ]));
        $csv = $this->get(route('billing-reports.csv', ['unit' => $unit->id, 'from' => '2026-11-01', 'to' => '2026-12-31']))->assertOk()->streamedContent();

        // 13. Reconciliation: billed = paid + glossed for each lot; billing never reads the notes; no patient name nor
        // clinical text in the audit, the report or the TISS files.
        foreach ([$consultationLot, $stayLot] as $each) {
            $this->assertSame($each->total_cents, (int) DB::table('billing_item_returns')->where('billing_lot_id', $each->id)->sum(DB::raw('paid_cents + glossed_cents')));
        }
        $this->actingAs($users['faturamento'])->get(route('visits.show', $visit))->assertForbidden();
        $this->assertTrue(DB::table('clinical_reads')->where('user_id', $users['faturamento']->id)->where('reason', 'billing')->exists());
        $files = collect(Storage::disk('local')->allFiles('tiss'))->map(fn (string $path) => (string) Storage::disk('local')->get($path))->implode('');
        $trail = AuditEvent::query()->pluck('context')->toJson().$csv.$files;
        foreach (['Teodora', 'Vilela', 'Tosse', 'consolidação', 'Abaulamento', 'encarcerada', 'Lichtenstein', 'deambulando'] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $trail);
        }
    }
}
