<?php

namespace Tests\Feature\Clinical;

use App\Http\Controllers\AdmissionController;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\ClinicalDocument;
use App\Models\ClinicalNote;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Prescription;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 4.4b (ADR-0004): the responsible doctor and the team of the bed's sector read and write the record
 * of an inpatient while the patient is there; nobody else does.
 */
class WardTest extends ClinicalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private Sector $ward;

    private Sector $icu;

    private Membership $bedManager;

    private Membership $wardNurse;

    private Membership $icuNurse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->ward = Sector::query()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->icu = Sector::factory()->for($this->unit)->create(['name' => 'UTI']);
        app(CurrentTenant::class)->set(null);
        $this->bedManager = Membership::factory()->for($this->unit->tenant)->create();
        $this->bedManager->grant(Role::BedManagement, $this->unit);
        $this->wardNurse = $this->teamMember('Enf. Carla', $this->ward);
        $this->icuNurse = $this->teamMember('Enf. Davi', $this->icu);
    }

    /**
     * A professional with a linked account and an approved qualification for one sector of the unit.
     */
    private function teamMember(string $name, Sector $sector): Membership
    {
        $account = Membership::factory()->for($this->unit->tenant)->create();
        $account->grant(Role::Professional, $this->unit);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => $name]);
        $professional->forceFill(['membership_id' => $account->id])->save();
        Qualification::request($professional, $this->unit, ['job_function_id' => JobFunction::query()->firstOrFail()->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$sector->id])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);

        return $account;
    }

    /**
     * Arrival, classification, emergency visit with a document, admission request; admitted if a bed is given.
     */
    private function admission(?Bed $bed = null): Admission
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $visit = Visit::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('clinical-documents.store', $visit), ['file' => UploadedFile::fake()->createWithContent('raio-x.pdf', self::PDF)])->assertSessionHasNoErrors();
        $this->post(route('visits.close', $visit), ['outcome' => 'admission_requested', 'requested_sector_id' => $this->ward->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->latest('id')->firstOrFail();
        if ($bed !== null) {
            $this->actingAs($this->bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();
        }

        return $admission->fresh();
    }

    private function bed(Sector $sector, string $code): Bed
    {
        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $sector->id, 'room' => '1', 'code' => $code, 'type' => 'ward'])->assertSessionHasNoErrors();

        return Bed::withoutGlobalScopes()->where('code', $code)->sole();
    }

    public function test_the_ward_team_and_the_responsible_doctor_write_the_inpatient_record(): void
    {
        $admission = $this->admission($this->bed($this->ward, 'E-1'));

        // The sector's nurse finds the patient, reads the record (logged as an admission read) and writes.
        $this->actingAs($this->wardNurse->user)->get(route('ward.index'))->assertInertia(fn (Assert $page) => $page->component('ward/index')
            ->has('admissions', 1)->where('admissions.0.mine', false));
        $this->get(route('clinical-record.show', $this->patient))->assertRedirect(route('ward.show', $admission));
        $this->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->component('ward/show')->has('documents', 1));
        $this->assertSame(1, DB::table('clinical_reads')->where('reason', 'admission')->where('admission_id', $admission->id)->count());
        $this->get(route('clinical-documents.download', ClinicalDocument::withoutTenantScope()->sole()))->assertOk();

        $this->post(route('ward.notes.store', $admission), ['body' => 'Sem febre no plantão.'])->assertSessionHasNoErrors();
        $this->post(route('ward.notes.store', $admission), ['body' => 'De novo.'])->assertSessionHasErrors('body');
        $nurseNote = ClinicalNote::withoutTenantScope()->where('admission_id', $admission->id)->sole();
        // The responsible doctor writes too, with a draft of their own; nobody touches another author's draft.
        $this->actingAs($this->doctorAccount->user)->post(route('ward.notes.store', $admission), ['body' => 'Mantém antibiótico.'])->assertSessionHasNoErrors();
        $this->put(route('clinical-notes.update', $nurseNote), ['body' => 'Alterado.'])->assertForbidden();
        $this->actingAs($this->wardNurse->user)->post(route('clinical-notes.sign', $nurseNote), ['body' => 'Sem febre no plantão.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->where('record.0.body', 'Sem febre no plantão.')->has('drafts', 0));
    }

    public function test_the_ward_team_attaches_and_withdraws_documents_during_the_admission(): void
    {
        $admission = $this->admission($this->bed($this->ward, 'E-1'));
        $upload = fn () => $this->post(route('ward.documents.store', $admission), ['file' => UploadedFile::fake()->createWithContent('gasometria.pdf', self::PDF)]);

        $this->actingAs($this->icuNurse->user);
        $upload()->assertForbidden();
        $this->actingAs($this->wardNurse->user);
        $upload()->assertSessionHasNoErrors();
        $document = ClinicalDocument::withoutTenantScope()->where('admission_id', $admission->id)->sole();
        $this->assertSame([null, $this->patient->id], [$document->visit_id, $document->patient_id]);
        $this->assertSame(1, AuditEvent::where('action', 'clinical_document.attached')->where('context->admission_id', $admission->id)->count());

        // The responsible doctor downloads it (logged); only the uploader withdraws, while still on the team.
        $this->actingAs($this->doctorAccount->user)->get(route('clinical-documents.download', $document))->assertOk();
        $this->assertSame(1, DB::table('clinical_reads')->where('user_id', $this->doctorAccount->user_id)->where('reason', 'admission')->count());
        $this->post(route('clinical-documents.withdraw', $document), ['reason' => 'Não é meu.'])->assertForbidden();
        $this->actingAs($this->wardNurse->user)->post(route('clinical-documents.withdraw', $document), ['reason' => 'Paciente errado.'])->assertSessionHasNoErrors();
        $this->assertNotNull($document->fresh()->withdrawn_at);

        // Step 4b.1c: the bed's team records allergies too; another sector's team does not.
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Látex', 'severity' => 'moderate'])->assertSessionHasNoErrors();
        $this->actingAs($this->icuNurse->user)->post(route('patient-allergies.store', $this->patient), ['substance' => 'Iodo', 'severity' => 'mild'])->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->where('allergies.0.substance', 'Látex')->has('allergyForm'));

        // Step 4b.2a: the responsible doctor (CRM) prescribes for the admission; the ward nurse reads, never prescribes.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->forceFill(['council' => 'CRM', 'council_number' => '99', 'council_uf' => 'SP'])->save();
        app(CurrentTenant::class)->set(null);
        $this->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->where('prescriptions.canPrescribe', true)->where('prescriptions.active', null));
        $this->post(route('ward.prescriptions.start', $admission))->assertRedirect();
        $this->assertSame($admission->id, Prescription::withoutTenantScope()->sole()->admission_id);
        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->where('prescriptions.canPrescribe', false)->where('prescriptions.draftId', null));
        $this->post(route('ward.prescriptions.start', $admission))->assertForbidden();

        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = (array) DB::table('clinical_documents')->where('id', $document->id)->first();
        unset($row['id']);
        try {
            DB::transaction(fn () => DB::table('clinical_documents')->insert([...$row, 'path' => "clinical/{$this->unit->tenant_id}/x.pdf", 'visit_id' => Visit::query()->value('id'), 'withdrawn_at' => null, 'withdrawn_by_user_id' => null, 'withdraw_reason' => null]));
            $this->fail('A document with two origins was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_a_bed_change_moves_the_access_and_keeps_the_history(): void
    {
        $first = $this->bed($this->ward, 'E-1');
        $icuBed = $this->bed($this->icu, 'U-1');
        $admission = $this->admission($first);
        $this->actingAs($this->icuNurse->user)->get(route('ward.show', $admission))->assertForbidden();

        // Only bed management moves; the target must be a free bed, not the current one.
        $this->actingAs($this->wardNurse->user)->post(route('admissions.move', $admission), ['bed_id' => $icuBed->id])->assertForbidden();
        $this->actingAs($this->bedManager->user)->post(route('admissions.move', $admission), ['bed_id' => $first->id])->assertSessionHasErrors('bed_id');
        $this->post(route('admissions.move', $admission), ['bed_id' => $icuBed->id])->assertSessionHasNoErrors();

        $this->assertSame([$icuBed->id, 'occupied', 'cleaning'], [$admission->fresh()->bed_id, $icuBed->fresh()->status, $first->fresh()->status]);
        $this->assertSame(1, AuditEvent::where('action', 'admission.moved')->count());
        // At once: the previous sector's team loses access, the new one gains it.
        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $admission))->assertForbidden();
        $this->actingAs($this->icuNurse->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page
            ->has('history', 2)->where('history.1.text', 'De '.$this->ward->name.' · quarto 1 · leito E-1 para UTI · quarto 1 · leito U-1'));

        // Housekeeping returns the previous bed, which can then be used again.
        $housekeeper = Membership::factory()->for($this->unit->tenant)->create();
        $housekeeper->grant(Role::Housekeeping, $this->unit);
        $this->actingAs($housekeeper->user)->post(route('beds.clean', $first))->assertSessionHasNoErrors();
        $this->assertSame('free', $first->fresh()->status);
        $this->actingAs($this->bedManager->user)->post(route('admissions.move', $admission), ['bed_id' => $icuBed->id])->assertSessionHasErrors('bed_id');
    }

    public function test_the_responsible_doctor_hands_over_and_loses_access_outside_the_team(): void
    {
        $this->bed($this->ward, 'E-1');
        $admission = $this->admission($this->bed($this->icu, 'U-1'));
        $wardNurse = Professional::query()->withoutGlobalScopes()->where('membership_id', $this->wardNurse->id)->sole();

        $this->actingAs($this->icuNurse->user)->post(route('ward.hand-over', $admission), ['professional_id' => $wardNurse->id])->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->post(route('ward.hand-over', $admission), ['professional_id' => $this->nurse->id])->assertSessionHasErrors('professional_id');
        $this->post(route('ward.hand-over', $admission), ['professional_id' => $wardNurse->id, 'reason' => 'Troca de equipe.'])->assertRedirect(route('ward.index'));

        $this->assertSame($wardNurse->id, $admission->fresh()->responsible_professional_id);
        // The doctor is not on the ICU team: the record closes at once; the new responsible reads it.
        $this->get(route('ward.show', $admission))->assertForbidden();
        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page
            ->where('history.1.text', 'Responsável: de Dra. Ana para Enf. Carla')->where('history.1.reason', 'Troca de equipe.'));
        $this->assertSame(1, AuditEvent::where('action', 'admission.responsible_changed')->count());
        // Bed management sees the beds only, never the hand-over and its reason.
        $this->actingAs($this->bedManager->user)->get(route('admissions.index'))->assertInertia(fn (Assert $page) => $page->has('admissions.0.history', 1));

        // A deactivated account is no longer offered nor accepted as responsible.
        $this->wardNurse->user->deactivate();
        $this->assertNotContains($wardNurse->id, array_column(AdmissionController::requestOptions($this->unit->id)['professionals'], 'id'));
    }

    public function test_the_admission_history_is_insert_only(): void
    {
        $this->admission($this->bed($this->ward, 'E-1'));
        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = (array) DB::table('admission_movements')->first();
        unset($row['id']);

        foreach ([
            fn () => DB::table('admission_movements')->update(['reason' => 'Reescrito']),
            fn () => DB::table('admission_movements')->delete(),
            fn () => DB::table('admission_movements')->insert([...$row, 'kind' => 'responsible']),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('The database accepted a change it must refuse.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['P0001', '23514']);
            }
        }
    }

    public function test_an_admission_draft_never_blocks_nor_shows_in_another_visit(): void
    {
        $admission = $this->admission($this->bed($this->ward, 'E-1'));
        $this->actingAs($this->doctorAccount->user)->post(route('ward.notes.store', $admission), ['body' => 'Rascunho da internação.'])->assertSessionHasNoErrors();

        // An outpatient visit of the same patient by the same doctor: the admission draft is not one of its drafts.
        $appointment = $this->arrived($this->doctor, $this->unit, '08:40');
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment));
        $visit = Visit::withoutTenantScope()->latest('id')->firstOrFail();
        $this->get(route('visits.show', $visit))->assertInertia(fn (Assert $page) => $page->has('drafts', 0));
        $this->post(route('visits.close', $visit))->assertSessionHasNoErrors();
    }

    public function test_only_the_bed_sector_team_and_the_responsible_doctor_read(): void
    {
        // Requested, no bed yet: only the responsible doctor.
        $bed = $this->bed($this->ward, 'E-1');
        $admission = $this->admission();
        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $admission))->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))->assertOk();

        $this->actingAs($this->bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id]);
        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $admission))->assertOk();

        // Another sector's team, another unit's nurse, bed management, reception and the manager never read.
        foreach ([$this->icuNurse, $this->nurseAccount] as $member) {
            $this->actingAs($member->user)->get(route('ward.show', $admission))->assertForbidden();
            $this->post(route('ward.notes.store', $admission), ['body' => 'Tentativa.'])->assertForbidden();
            $this->get(route('clinical-record.show', $this->patient))->assertRedirect(route('clinical-record.emergency', $this->patient));
            $this->get(route('ward.index'))->assertInertia(fn (Assert $page) => $page->has('admissions', 0));
        }
        foreach ([$this->bedManager, $this->reception, $this->manager] as $member) {
            $this->actingAs($member->user)->get(route('ward.show', $admission))->assertForbidden();
            $this->get(route('ward.index'))->assertForbidden();
        }
        $this->assertSame(0, DB::table('clinical_reads')->whereIn('user_id', [$this->icuNurse->user_id, $this->bedManager->user_id])->count());
    }

    public function test_access_ends_with_the_qualification_or_the_account(): void
    {
        $admission = $this->admission($this->bed($this->ward, 'E-1'));
        $this->actingAs($this->wardNurse->user)->post(route('ward.notes.store', $admission), ['body' => 'Rascunho.']);
        $draft = ClinicalNote::withoutTenantScope()->where('admission_id', $admission->id)->sole();

        app(CurrentTenant::class)->set($this->unit->tenant);
        Qualification::query()->where('professional_id', Professional::query()->where('membership_id', $this->wardNurse->id)->value('id'))->update(['valid_until' => '2026-11-12']);
        app(CurrentTenant::class)->set(null);
        $this->get(route('ward.show', $admission))->assertForbidden();
        $this->post(route('clinical-notes.sign', $draft), ['body' => 'Rascunho.', 'password' => 'password'])->assertForbidden();

        $this->doctorAccount->revoke();
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))->assertForbidden();
    }

    public function test_the_database_keeps_one_origin_and_one_draft_per_author(): void
    {
        $admission = $this->admission($this->bed($this->ward, 'E-1'));
        $this->actingAs($this->wardNurse->user)->post(route('ward.notes.store', $admission), ['body' => 'Rascunho.']);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = (array) DB::table('clinical_notes')->where('admission_id', $admission->id)->first();
        unset($row['id']);

        foreach ([
            // A second draft of the same author in the same admission.
            fn () => DB::table('clinical_notes')->insert($row),
            // Two origins at once.
            fn () => DB::table('clinical_notes')->insert([...$row, 'author_user_id' => $this->doctorAccount->user_id, 'visit_id' => Visit::query()->value('id')]),
            // Moving a note to another origin.
            fn () => DB::table('clinical_notes')->where('admission_id', $admission->id)->update(['admission_id' => null, 'visit_id' => Visit::query()->value('id')]),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('The database accepted a change it must refuse.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['23505', '23514', 'P0001']);
            }
        }
    }
}
