<?php

namespace Tests\Feature\Clinical;

use App\Billing\SigtapImport;
use App\Billing\SusRules;
use App\Models\Admission;
use App\Models\AihRequest;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use ZipArchive;

/**
 * Step 6b.5a (ADR-0011): the AIH request report of a SUS admission, signed with the password by the responsible
 * doctor (procedure registered as the AIH's principal in the SIGTAP, compatible CID), kept by version and printed
 * for whoever attends; billing never reads it.
 */
class AihRequestTest extends ClinicalTestCase
{
    private Membership $wardNurse;

    private Sector $ward;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->ward = Sector::query()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->wardNurse = Membership::factory()->for($this->unit->tenant)->create();
        $this->wardNurse->grant(Role::Professional, $this->unit);
        $professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Enf. Carla']);
        $professional->forceFill(['membership_id' => $this->wardNurse->id])->save();
        Qualification::request($professional, $this->unit, ['job_function_id' => JobFunction::query()->firstOrFail()->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$this->ward->id])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);
    }

    /** The real 10/2026 cut of the SIGTAP, as this month's competence. */
    private function importSigtap(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sigtap').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach (glob(base_path('tests/Fixtures/sigtap/*.txt')) ?: [] as $file) {
            $zip->addFromString(basename($file), str_contains($file, '_layout') ? (string) file_get_contents($file) : str_replace('202610', '202611', (string) file_get_contents($file)));
        }
        $zip->close();
        app(CurrentTenant::class)->set($this->unit->tenant);
        SigtapImport::read($path)->apply($this->unit->tenant_id, 'TabelaUnificada_202611.zip', str_repeat('a', 64), $this->manager->user);
        app(CurrentTenant::class)->set(null);
    }

    private function admitted(string $modality = 'sus', string $bed = 'A-1'): Admission
    {
        $bedManager = Membership::factory()->for($this->unit->tenant)->create();
        $bedManager->grant(Role::BedManagement, $this->unit);
        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $this->ward->id, 'room' => '1', 'code' => $bed, 'type' => 'ward'])->assertSessionHasNoErrors();
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => $modality]);
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()), ['cid_principal' => 'J18.9', 'outcome' => 'admission_requested', 'requested_sector_id' => $this->ward->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => Bed::withoutGlobalScopes()->where('code', $bed)->sole()->id])->assertSessionHasNoErrors();

        return $admission->fresh();
    }

    public function test_the_responsible_doctor_signs_the_report_and_whoever_attends_prints_it(): void
    {
        $admission = $this->admitted();
        $sign = fn (array $data) => $this->actingAs($this->doctorAccount->user)->post(route('ward.aih-request.store', $admission), [
            'procedure_code' => '0303140151', 'cid_principal' => 'J18.9', 'justification' => 'Pneumonia com hipoxemia, sem resposta ao tratamento oral.', 'password' => 'password', ...$data,
        ]);

        // Without the SIGTAP, the panel says so and nothing is signed.
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page
            ->where('aihRequest', ['current' => null, 'canSign' => true, 'sigtap' => false]));
        $sign([])->assertSessionHasErrors('procedure_code');
        $this->importSigtap();

        // A consultation is not an AIH principal procedure; the CID must fit the procedure; one secondary CID.
        $sign(['procedure_code' => '0301010072'])->assertSessionHasErrors('procedure_code');
        $sign(['cid_principal' => 'I10'])->assertSessionHasErrors(['cid_principal' => 'O CID I10 não é compatível com 0303140151 na SIGTAP.']);
        $sign(['cid_secondary' => 'I10 E11.9'])->assertSessionHasErrors('cid_secondary');
        $sign(['justification' => ''])->assertSessionHasErrors('justification');
        $sign(['password' => 'wrong'])->assertSessionHasErrors(['password' => 'Senha incorreta. O laudo não foi assinado.']);
        $this->assertSame(0, DB::table('aih_requests')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'aih_request.sign_failed')->count());

        $sign(['procedure_code' => '03.03.14.015-1', 'cid_secondary' => 'I10'])->assertRedirect(route('ward.show', $admission));
        $sign(['justification' => 'Piora: saturação 88% em ar ambiente.'])->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertSame([[1, '0303140151', 'J189', 'I10'], [2, '0303140151', 'J189', null]], DB::table('aih_requests')->orderBy('id')->get()
            ->map(fn ($row) => [$row->version, $row->procedure_code, $row->cid_code, $row->cid_secondary])->all());
        app(CurrentTenant::class)->set(null);
        // The audit keeps ids only: never the procedure, the CID nor the justification.
        $audit = AuditEvent::query()->where('action', 'aih_request.signed')->pluck('context')->toJson();
        $this->assertStringNotContainsString('0303140151', $audit);
        $this->assertStringNotContainsString('J189', $audit);
        $this->assertStringNotContainsString('Piora', $audit);

        $this->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page
            ->where('aihRequest.current.version', 2)->where('aihRequest.current.cid', 'J189')
            ->where('aihRequest.current.procedure', fn (string $procedure) => str_starts_with($procedure, '0303140151 · TRATAMENTO DE PNEUMONIAS')));

        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page
            ->where('aihRequest.canSign', false)->where('aihRequest.current.version', 2));
        // The ward team prints it (with the read trail); billing, without the record, does not.
        $latest = AihRequest::withoutTenantScope()->where('version', 2)->sole();
        $pdf = $this->actingAs($this->wardNurse->user)->get(route('aih-requests.pdf', $latest))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->assertSame(1, AuditEvent::query()->where('action', 'aih_request.printed')->count());
        // Only the responsible doctor signs.
        $this->post(route('ward.aih-request.store', $admission), ['procedure_code' => '0303140151', 'cid_principal' => 'J18.9', 'justification' => 'x', 'password' => 'password'])->assertForbidden();
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $this->actingAs($billing->user)->get(route('aih-requests.pdf', $latest))->assertForbidden();

        // The database keeps every version as signed.
        app(CurrentTenant::class)->set($this->unit->tenant);
        foreach ([fn () => DB::table('aih_requests')->update(['justification' => 'x']), fn () => DB::table('aih_requests')->delete()] as $change) {
            try {
                DB::transaction($change);
                $this->fail('The database accepted a change to an AIH request.');
            } catch (QueryException) {
            }
        }
    }

    public function test_outside_the_sus_there_is_no_report_and_the_search_finds_aih_procedures(): void
    {
        $this->importSigtap();
        $admission = $this->admitted('private');
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->where('aihRequest', null));
        $this->post(route('ward.aih-request.store', $admission), ['procedure_code' => '0303140151', 'cid_principal' => 'J18.9', 'justification' => 'x', 'password' => 'password'])->assertForbidden();

        // AIH principal procedures only, by code or by words without accents.
        $this->getJson(route('sigtap.procedures', ['q' => 'pneumonia gripe']))->assertOk()->assertJsonPath('0.code', '0303140151')->assertJsonCount(1);
        $this->getJson(route('sigtap.procedures', ['q' => '03.03.14']))->assertJsonPath('0.code', '0303140151');
        $this->getJson(route('sigtap.procedures', ['q' => '0407']))->assertJsonPath('0.code', '0407040129');
        $this->getJson(route('sigtap.procedures', ['q' => '0301']))->assertExactJson([]);
        $this->getJson(route('sigtap.procedures', ['q' => ['0303']]))->assertOk()->assertExactJson([]);
        // Not for an account without a professional record.
        $this->actingAs($this->manager->user)->getJson(route('sigtap.procedures', ['q' => '0303']))->assertForbidden();
    }

    /**
     * Step 6b.5a2 (user's decision, 28/10/2026): after the discharge, the doctor who signed still prints the report and
     * the auditor reads every report of the network, each read in the trail; the lists carry no clinical content.
     */
    public function test_after_the_discharge_the_signer_and_the_auditor_print_the_report(): void
    {
        $this->importSigtap();
        $admission = $this->admitted();
        $this->actingAs($this->doctorAccount->user)->post(route('ward.aih-request.store', $admission), [
            'procedure_code' => '0303140151', 'cid_principal' => 'J18.9', 'justification' => 'Pneumonia com hipoxemia.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $this->post(route('ward.discharge', $admission), ['cid_principal' => 'J18.9', 'type' => 'improved', 'summary' => 'Estável.', 'password' => 'password'])->assertSessionHasNoErrors();
        $report = AihRequest::withoutTenantScope()->sole();

        // The ward team no longer reads it; the signer does, as a read of the admission.
        $this->actingAs($this->wardNurse->user)->get(route('aih-requests.pdf', $report))->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->get(route('aih-requests.pdf', $report))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('aih-requests.index'))->assertInertia(fn (Assert $page) => $page->component('aih-requests/index')->where('auditor', false)
            ->where('reports.0.id', $report->id)->has('reports', 1)->where('reports.0', fn ($row) => ! collect($row)->keys()->intersect(['procedure', 'cid', 'justification'])->count()));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('canViewAihRequests', true));
        $this->actingAs($this->wardNurse->user)->get(route('aih-requests.index'))->assertInertia(fn (Assert $page) => $page->has('reports', 0));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('canViewAihRequests', false));

        // The auditor of the network lists and prints it, with the reason "audit" in the trail; billing does not.
        $auditor = Membership::factory()->for($this->unit->tenant)->withRole(Role::Auditor)->create();
        $this->actingAs($auditor->user)->get(route('aih-requests.index'))->assertInertia(fn (Assert $page) => $page
            ->where('auditor', true)->where('reports.0.signer', 'Dra. Ana'));
        $this->get(route('aih-requests.pdf', $report))->assertOk();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertTrue(DB::table('clinical_reads')->where(['user_id' => $this->doctorAccount->user_id, 'reason' => 'admission', 'admission_id' => $admission->id])->exists());
        $this->assertSame($report->id, DB::table('clinical_reads')->where('reason', 'audit')->value('aih_request_id'));
        app(CurrentTenant::class)->set(null);
        $this->get(route('clinical-access.index'))->assertInertia(fn (Assert $page) => $page->where('reads', fn ($reads) => collect($reads)->contains('reason', 'audit')));
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $this->actingAs($billing->user)->get(route('aih-requests.pdf', $report))->assertForbidden();
        $this->get(route('aih-requests.index'))->assertForbidden();

        // An auditor of another network does not even find it.
        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->withRole(Role::Auditor)->create();
        $this->actingAs($stranger->user)->get(route('aih-requests.pdf', $report))->assertNotFound();
    }

    /**
     * Step 6b.5b1: billing records the authorized AIH's number on the SUS admission's account (13 digits, once in the
     * network, corrected while open); without it the account shows the pending item and does not close.
     */
    public function test_billing_records_the_aih_number_and_the_account_waits_for_it(): void
    {
        $admission = $this->admitted();
        $account = BillingAccount::withoutTenantScope()->where('emergency_episode_id', $admission->emergency_episode_id)->sole();
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $missing = 'Sem o número da AIH autorizada: registre-o quando o gestor devolver o laudo autorizado.';
        $this->actingAs($billing->user)->get(route('billing-accounts.show', $account))->assertInertia(fn (Assert $page) => $page
            ->where('aih', ['number' => null, 'canRecord' => true, 'principal' => null])->where('susPending', fn ($pending) => collect($pending)->contains($missing)));
        $record = fn (string $number) => $this->post(route('billing-accounts.aih-number', $account), ['aih_number' => $number]);

        $record('123456789012')->assertSessionHasErrors(['aih_number' => 'O número da AIH tem 13 dígitos.']);
        $record('3526 1000 0001-2')->assertSessionHasNoErrors();
        $this->assertSame('3526100000012', $account->fresh()->aih_number);
        $record('3526100000013')->assertSessionHasNoErrors();
        $this->assertSame('3526100000013', $account->fresh()->aih_number);
        $this->get(route('billing-accounts.show', $account))->assertInertia(fn (Assert $page) => $page
            ->where('aih.number', '3526100000013')->where('susPending', fn ($pending) => ! collect($pending)->contains($missing)));
        $this->assertSame([false, true], AuditEvent::query()->where('action', 'billing_account.aih_number_recorded')->orderBy('id')->pluck('context')->map(fn ($context) => $context['replaced'])->all());
        $this->assertStringNotContainsString('3526100000013', AuditEvent::query()->pluck('context')->toJson());

        // Another admission's account cannot take the same number; a consultation's account takes none.
        $this->post(route('ward.discharge', $admission))->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->post(route('ward.discharge', $admission), ['cid_principal' => 'J18.9', 'type' => 'improved', 'summary' => 'Estável.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->travelTo(now()->addHour());
        $second = $this->admitted('sus', 'A-2');
        $other = BillingAccount::withoutTenantScope()->where('emergency_episode_id', $second->emergency_episode_id)->sole();
        $this->actingAs($billing->user)->post(route('billing-accounts.aih-number', $other), ['aih_number' => '3526100000013'])
            ->assertSessionHasErrors(['aih_number' => 'Este número de AIH já está em outra conta.']);
        $appointment = $this->arrived($this->doctor, $this->unit, '11:20');
        $consultation = BillingAccount::withoutTenantScope()->where('appointment_id', $appointment->id)->sole();
        $this->actingAs($billing->user)->post(route('billing-accounts.aih-number', $consultation), ['aih_number' => '3526100000099'])
            ->assertSessionHasErrors(['aih_number' => 'Só a conta de uma internação SUS recebe o número da AIH.']);

        // Billing of another unit does not reach it.
        $outsider = Membership::factory()->for($this->unit->tenant)->create();
        $outsider->grant(Role::Billing, $this->other);
        $this->actingAs($outsider->user)->post(route('billing-accounts.aih-number', $other), ['aih_number' => '3526100000098'])->assertForbidden();
    }

    /**
     * Step 6b.5b2: the AIH's principal procedure starts as the report's and is billed (SH + SP of the admission day's
     * competence); billing changes it with a reason, the previous item compensated; afterwards the report no longer
     * leads; a care record that already billed it makes the principal item go.
     */
    public function test_the_principal_procedure_follows_the_report_until_billing_changes_it(): void
    {
        $this->importSigtap();
        $admission = $this->admitted();
        $account = BillingAccount::withoutTenantScope()->where('emergency_episode_id', $admission->emergency_episode_id)->sole();
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $show = fn () => $this->actingAs($billing->user)->get(route('billing-accounts.show', $account));
        $show()->assertInertia(fn (Assert $page) => $page->where('aih.principal', null)->where('canChangePrincipal', true)
            ->where('susPending', fn ($pending) => collect($pending)->contains(fn ($problem) => str_starts_with($problem, 'Sem procedimento principal'))));

        $sign = fn (string $code) => $this->actingAs($this->doctorAccount->user)->post(route('ward.aih-request.store', $admission), [
            'procedure_code' => $code, 'cid_principal' => $code === '0407040129' ? 'K42.9' : 'J18.9', 'justification' => 'Pneumonia com hipoxemia.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        $sign('0303140151');
        app(CurrentTenant::class)->set($this->unit->tenant);
        $price = fn (string $code) => SusRules::price(SusRules::procedure($this->unit->tenant_id, $code, '2026-11-13'), 'aih');
        [$pneumonia, $hernia] = [$price('0303140151'), $price('0407040129')];
        app(CurrentTenant::class)->set(null);
        $show()->assertInertia(fn (Assert $page) => $page->where('aih.principal.fromReport', true)
            ->where('aih.principal.procedure', fn ($procedure) => str_starts_with($procedure, '0303140151'))
            ->where('items', fn ($items) => collect($items)->where('origin', 'aih_principal')->where('code', '0303140151')->where('unitPriceCents', $pneumonia)->count() === 1)
            ->where('susPending', fn ($pending) => ! collect($pending)->contains(fn ($problem) => str_starts_with($problem, 'Sem procedimento principal'))));
        // A new version of the report with the same procedure changes nothing.
        $sign('0303140151');
        $this->assertSame(1, DB::table('aih_principals')->count());

        // A new version with another procedure leads too: the previous item compensated by its signer.
        $sign('0407040129');
        $show()->assertInertia(fn (Assert $page) => $page->where('aih.principal.procedure', fn ($procedure) => str_starts_with($procedure, '0407040129'))
            ->where('items', fn ($items) => collect($items)->where('origin', 'compensation')->where('code', '0303140151')->where('reason', 'Novo laudo da AIH (versão 3).')->count() === 1
                && collect($items)->where('origin', 'aih_principal')->where('code', '0407040129')->where('unitPriceCents', $hernia)->count() === 1));

        // Billing changes it with a reason: not a consultation, not the same, not without a reason.
        $change = fn (array $data) => $this->actingAs($billing->user)->post(route('billing-accounts.aih-principal', $account), $data);
        $change(['procedure_code' => '0301010072', 'reason' => 'x'])->assertSessionHasErrorsIn('principal', 'procedure_code');
        $change(['procedure_code' => '0407040129', 'reason' => 'x'])->assertSessionHasErrorsIn('principal', ['procedure_code' => 'Este já é o procedimento principal.']);
        $change(['procedure_code' => '0303140151'])->assertSessionHasErrorsIn('principal', 'reason');
        $change(['procedure_code' => '0303140151', 'reason' => 'Tratamento clínico, sem cirurgia.'])->assertSessionHasNoErrors();
        $show()->assertInertia(fn (Assert $page) => $page->where('aih.principal.fromReport', false)->where('aih.principal.reason', 'Tratamento clínico, sem cirurgia.')
            ->where('items', fn ($items) => collect($items)->where('origin', 'compensation')->where('code', '0407040129')->where('quantity', -1)->where('reason', 'Tratamento clínico, sem cirurgia.')->count() === 1
                && collect($items)->where('origin', 'aih_principal')->where('code', '0303140151')->count() === 2));
        // From now on the report no longer leads.
        $sign('0407040129');
        $show()->assertInertia(fn (Assert $page) => $page->where('aih.principal.procedure', fn ($procedure) => str_starts_with($procedure, '0303140151')));
        $this->assertStringNotContainsString('0303140151', AuditEvent::query()->where('action', 'billing_account.aih_principal_changed')->pluck('context')->toJson());

        // A care record billed it already: the principal item goes (compensated), the total counts it once.
        app(CurrentTenant::class)->set($this->unit->tenant);
        DB::table('billing_account_items')->insert(['tenant_id' => $account->tenant_id, 'billing_account_id' => $account->id, 'origin_type' => 'surgery', 'origin_id' => 999999,
            'code' => '0303140151', 'name' => 'Tratamento de pneumonias', 'quantity' => 1, 'unit_price_cents' => $pneumonia, 'needs_authorization' => false, 'performed_on' => '2026-11-13', 'created_at' => now()]);
        $account->generate();
        $this->assertSame($pneumonia, (int) DB::table('billing_account_items')->where('billing_account_id', $account->id)->sum(DB::raw('quantity * unit_price_cents')));
        $this->assertSame('O procedimento principal já está na conta pelo registro assistencial.', DB::table('billing_account_items')->where('origin_type', 'compensation')->orderByDesc('id')->value('reason'));
        try {
            DB::transaction(fn () => DB::table('aih_principals')->update(['reason' => 'x']));
            $this->fail('The database accepted a change to a principal procedure.');
        } catch (QueryException) {
        }
        app(CurrentTenant::class)->set(null);

        // Billing of another unit does not reach it; a consultation's account has no principal.
        $outsider = Membership::factory()->for($this->unit->tenant)->create();
        $outsider->grant(Role::Billing, $this->other);
        $this->actingAs($outsider->user)->post(route('billing-accounts.aih-principal', $account), ['procedure_code' => '0303140151', 'reason' => 'x'])->assertForbidden();
        $this->getJson(route('sigtap.procedures', ['q' => '0303']))->assertOk()->assertJsonPath('0.code', '0303140151');
    }

    /** Review finding (6b.5b2): a principal without a value in the admission's competence keeps the account open. */
    public function test_a_principal_without_its_competence_value_is_pending(): void
    {
        $this->importSigtap();
        $admission = $this->admitted();
        $this->actingAs($this->doctorAccount->user)->post(route('ward.aih-request.store', $admission), [
            'procedure_code' => '0303140151', 'cid_principal' => 'J18.9', 'justification' => 'Pneumonia.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set($this->unit->tenant);
        DB::table('sigtap_competences')->delete();
        $account = BillingAccount::withoutTenantScope()->where('emergency_episode_id', $admission->emergency_episode_id)->sole();
        app(CurrentTenant::class)->set(null);
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $this->actingAs($billing->user)->get(route('billing-accounts.show', $account))->assertInertia(fn (Assert $page) => $page
            ->where('items', fn ($items) => collect($items)->where('origin', 'aih_principal')->isEmpty())
            ->where('susPending', fn ($pending) => collect($pending)->contains('O procedimento principal 0303140151 não tem valor na SIGTAP de 11/2026 (competência da internação): importe a competência ou troque o procedimento.')));
    }
}
