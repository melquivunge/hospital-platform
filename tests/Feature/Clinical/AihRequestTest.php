<?php

namespace Tests\Feature\Clinical;

use App\Billing\SigtapImport;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
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

    private function admitted(string $modality = 'sus'): Admission
    {
        $bedManager = Membership::factory()->for($this->unit->tenant)->create();
        $bedManager->grant(Role::BedManagement, $this->unit);
        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $this->ward->id, 'room' => '1', 'code' => 'A-1', 'type' => 'ward'])->assertSessionHasNoErrors();
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => $modality]);
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()), ['cid_principal' => 'J18.9', 'outcome' => 'admission_requested', 'requested_sector_id' => $this->ward->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => Bed::withoutGlobalScopes()->where('code', 'A-1')->sole()->id])->assertSessionHasNoErrors();

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
        $pdf = $this->actingAs($this->wardNurse->user)->get(route('ward.aih-request.pdf', $admission))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->assertSame(1, AuditEvent::query()->where('action', 'aih_request.printed')->count());
        // Only the responsible doctor signs.
        $this->post(route('ward.aih-request.store', $admission), ['procedure_code' => '0303140151', 'cid_principal' => 'J18.9', 'justification' => 'x', 'password' => 'password'])->assertForbidden();
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $this->actingAs($billing->user)->get(route('ward.aih-request.pdf', $admission))->assertForbidden();

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
        $this->get(route('ward.aih-request.pdf', $admission))->assertNotFound();

        // AIH principal procedures only, by code or by words without accents.
        $this->getJson(route('sigtap.procedures', ['q' => 'pneumonia gripe']))->assertOk()->assertJsonPath('0.code', '0303140151')->assertJsonCount(1);
        $this->getJson(route('sigtap.procedures', ['q' => '03.03.14']))->assertJsonPath('0.code', '0303140151');
        $this->getJson(route('sigtap.procedures', ['q' => '0407']))->assertJsonPath('0.code', '0407040129');
        $this->getJson(route('sigtap.procedures', ['q' => '0301']))->assertExactJson([]);
        $this->getJson(route('sigtap.procedures', ['q' => ['0303']]))->assertOk()->assertExactJson([]);
        // Not for an account without a professional record.
        $this->actingAs($this->manager->user)->getJson(route('sigtap.procedures', ['q' => '0303']))->assertForbidden();
    }
}
