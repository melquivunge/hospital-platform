<?php

namespace Tests\Feature\Clinical;

use App\Models\ClinicalDocument;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\OperatingRoom;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Surgery;
use App\Models\SurgeryTeamMember;
use App\Models\SurgicalProcedure;
use App\Models\Unit;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The record for the surgery (step 5b.3b, ADR-0009): the Surgical center of the unit and the members of the active
 * team read the record while the surgery is requested or scheduled, one trail row per opening with the reason
 * "surgery"; the access ends with the cancellation or the removal from the team. Security review 5b.7: the access is
 * bounded in time, the team holds a function that attends patients, and the same surgery is not ordered twice.
 */
class SurgeryRecordTest extends ClinicalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private Membership $center;

    private Membership $surgeonAccount;

    private Professional $surgeon;

    private OperatingRoom $room;

    private Visit $visit;

    private Membership $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $tenant = $this->patient->tenant;
        $this->center = Membership::factory()->for($tenant)->create();
        $this->center->grant(Role::SurgicalCenter, $this->unit);
        // A surgeon who does not attend the patient: only the team gives the record.
        $this->surgeonAccount = Membership::factory()->for($tenant)->create();
        $this->surgeonAccount->grant(Role::Professional, $this->unit);
        app(CurrentTenant::class)->set($tenant);
        SurgicalProcedure::query()->create(['name' => 'Apendicectomia', 'duration_minutes' => 60, 'size' => 'medium']);
        $this->room = OperatingRoom::query()->create(['unit_id' => $this->unit->id, 'name' => 'Sala 1']);
        $this->surgeon = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Dr. Bruno']);
        $this->surgeon->forceFill(['membership_id' => $this->surgeonAccount->id])->save();
        Qualification::request($this->surgeon, $this->unit, ['job_function_id' => JobFunction::query()->value('id'), 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::query()->where('unit_id', $this->unit->id)->value('id')])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);
        // Another client, created before the first request.
        $this->stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->stranger->grant(Role::SurgicalCenter, Unit::factory()->for($this->stranger->tenant)->create());

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Dor.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
        $this->post(route('clinical-documents.store', $this->visit), ['file' => UploadedFile::fake()->createWithContent('ecg.pdf', self::PDF)])->assertSessionHasNoErrors();
        $this->post(route('surgery-orders.visit.store', $this->visit), [
            'surgical_procedure_id' => SurgicalProcedure::withoutTenantScope()->value('id'), 'laterality' => 'left', 'urgency' => 'elective',
            'desired_date' => '2026-11-20', 'indication' => 'Hérnia inguinal sintomática.', 'password' => 'password',
        ])->assertSessionHasNoErrors();
    }

    private function surgery(): Surgery
    {
        return Surgery::withoutTenantScope()->sole();
    }

    /**
     * @return array<int, string>
     */
    private function reads(Membership $who): array
    {
        return DB::table('clinical_reads')->where('user_id', $who->user_id)->orderBy('id')->pluck('reason')->all();
    }

    public function test_the_surgical_center_reads_the_record_while_the_surgery_is_open(): void
    {
        $surgery = $this->surgery();
        // The agenda logs nothing; the record logs one row per opening.
        $this->actingAs($this->center->user)->get(route('surgery-schedule.index'))->assertOk();
        $this->get(route('surgery-schedule.show', $surgery))->assertOk();
        $this->assertSame([], $this->reads($this->center));
        $this->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page
            ->component('surgery-record/show')->where('surgery.indication', 'Hérnia inguinal sintomática.')->where('surgery.canManage', true)
            ->where('patient.name', 'Joana Prado')->has('documents', 1)->has('prescriptions'));
        $this->get(route('surgery-record.show', $surgery))->assertOk();
        $this->assertSame(['surgery', 'surgery'], $this->reads($this->center));
        $this->assertSame([$surgery->id], DB::table('clinical_reads')->where('user_id', $this->center->user_id)->distinct()->pluck('surgery_id')->all());
        $document = ClinicalDocument::withoutTenantScope()->sole();
        $this->get(route('clinical-documents.download', $document))->assertOk();
        $this->assertSame(3, count($this->reads($this->center)));

        // Another unit's center does not read it; nobody does once the surgery is cancelled.
        $elsewhere = Membership::factory()->for($this->patient->tenant)->create();
        $elsewhere->grant(Role::SurgicalCenter, $this->other);
        $this->actingAs($elsewhere->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->actingAs($this->center->user)->post(route('surgery-schedule.cancel', $surgery), ['reason' => 'Conduta clínica.'])->assertSessionHasNoErrors();
        $this->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->get(route('clinical-documents.download', $document))->assertForbidden();
    }

    public function test_the_scheduled_team_reads_the_record_only_while_on_the_team(): void
    {
        $surgery = $this->surgery();
        $this->actingAs($this->surgeonAccount->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page->component('surgery-record/mine')->has('surgeries', 0));

        $this->actingAs($this->center->user)->post(route('surgery-schedule.schedule', $surgery), [
            'operating_room_id' => $this->room->id, 'start' => '2026-11-20T08:00', 'duration_minutes' => 60, 'surgeon_id' => $this->surgeon->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->surgeonAccount->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('canUseMySurgeries', true));
        $this->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page
            ->has('surgeries', 1)->where('surgeries.0.patient', 'Joana Prado')->where('surgeries.0.role', 'surgeon')->where('surgeries.0.start', '20/11/2026 08:00')->missing('surgeries.0.indication'));
        $this->assertSame([], $this->reads($this->surgeonAccount));
        $this->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('surgery.canManage', false)->where('surgery.team.0.name', 'Dr. Bruno'));
        $this->assertSame(['surgery'], $this->reads($this->surgeonAccount));

        // Replaced on the team: the access ends.
        $this->actingAs($this->center->user)->post(route('surgery-schedule.team.store', $surgery), ['professional_id' => $this->doctor->id, 'role' => 'surgeon'])->assertSessionHasNoErrors();
        $this->post(route('surgery-schedule.team.remove', SurgeryTeamMember::withoutTenantScope()->where('professional_id', $this->surgeon->id)->sole()))->assertSessionHasNoErrors();
        $this->actingAs($this->surgeonAccount->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->get(route('clinical-documents.download', ClinicalDocument::withoutTenantScope()->sole()))->assertForbidden();
        $this->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page->has('surgeries', 0));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('canUseMySurgeries', false));
    }

    public function test_a_revoked_role_and_a_user_without_basis_do_not_read(): void
    {
        $surgery = $this->surgery();
        $this->actingAs($this->nurseAccount->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page->has('surgeries', 0));
        // Without a linked professional there is no team to list.
        $this->actingAs($this->center->user)->get(route('surgery-record.mine'))->assertForbidden();
        $this->get(route('surgery-record.show', $surgery))->assertOk();
        DB::table('role_assignments')->where('membership_id', $this->center->id)->delete();
        $this->get(route('surgery-record.show', $surgery))->assertForbidden();
    }

    public function test_the_access_ends_with_a_stale_request_a_revoked_membership_or_qualification_and_never_crosses_clients(): void
    {
        $surgery = $this->surgery();
        $this->actingAs($this->stranger->user)->get(route('surgery-record.show', $surgery))->assertNotFound();

        // Unscheduled: until 7 days after the wished date (20/11).
        $this->travelTo('2026-11-27 12:00:00');
        $this->actingAs($this->center->user)->get(route('surgery-record.show', $surgery))->assertOk();
        $this->travelTo('2026-11-28 12:00:00');
        $this->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->get(route('surgery-schedule.show', $surgery))->assertOk();

        // Scheduled: the center and the team read again; the attending author on the team cannot withdraw here.
        $this->post(route('surgery-schedule.schedule', $surgery), [
            'operating_room_id' => $this->room->id, 'start' => '2026-12-01T08:00', 'duration_minutes' => 60, 'surgeon_id' => $this->surgeon->id,
        ])->assertSessionHasNoErrors();
        $this->post(route('surgery-schedule.team.store', $surgery), ['professional_id' => $this->doctor->id, 'role' => 'assistant'])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->get(route('surgery-record.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('documents.0.canWithdraw', false));
        $this->actingAs($this->surgeonAccount->user)->get(route('surgery-record.show', $surgery))->assertOk();

        // A qualification no longer valid in the unit ends the team's access.
        DB::table('qualifications')->where('professional_id', $this->surgeon->id)->update(['valid_until' => '2026-11-27']);
        $this->get(route('surgery-record.show', $surgery))->assertForbidden();
        DB::table('qualifications')->where('professional_id', $this->surgeon->id)->update(['valid_until' => null]);
        $this->get(route('surgery-record.show', $surgery))->assertOk();

        // A revoked membership ends it for the team member and for the center.
        DB::table('memberships')->whereIn('id', [$this->surgeonAccount->id, $this->center->id])->update(['revoked_at' => now()]);
        $this->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->actingAs($this->center->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
    }

    public function test_the_access_is_bounded_in_time_the_team_attends_patients_and_an_order_is_not_repeated(): void
    {
        $surgery = $this->surgery();
        // The same order again (a double click, a second tab) is refused while it waits; another side is another surgery.
        $order = fn (array $values) => $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.visit.store', $this->visit), [
            'surgical_procedure_id' => $surgery->surgical_procedure_id, 'laterality' => 'left', 'urgency' => 'elective', 'desired_date' => '2026-11-20',
            'indication' => 'Hérnia.', 'password' => 'password', ...$values,
        ]);
        $order([])->assertSessionHasErrorsIn('order', ['surgical_procedure_id' => 'Este pedido já foi feito neste atendimento e aguarda agendamento.']);
        // A request wished for far ahead opens the record only from 30 days before it.
        $order(['laterality' => 'right', 'desired_date' => '2027-03-01'])->assertSessionHasNoErrors();
        $far = Surgery::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->center->user)->get(route('surgery-record.show', $surgery))->assertOk();
        $this->get(route('surgery-record.show', $far))->assertForbidden();

        // A function that does not attend patients is never on the team.
        app(CurrentTenant::class)->set($this->patient->tenant);
        $clerk = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Sra. Lúcia']);
        $office = JobFunction::query()->create(['name' => 'Secretaria', 'attends_patients' => false]);
        Qualification::request($clerk, $this->unit, ['job_function_id' => $office->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::query()->where('unit_id', $this->unit->id)->value('id')])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);
        $this->get(route('surgery-schedule.show', $surgery))->assertInertia(fn (Assert $page) => $page->where('professionals', fn ($people) => ! collect($people)->pluck('id')->contains($clerk->id)));
        $this->post(route('surgery-schedule.schedule', $surgery), ['operating_room_id' => $this->room->id, 'start' => '2026-11-20T08:00', 'duration_minutes' => 60, 'surgeon_id' => $clerk->id])
            ->assertSessionHasErrorsIn('schedule', ['surgeon_id']);

        // Scheduled: open until 24 hours after the planned end, then closed for the center and the team, and gone from
        // "Minhas cirurgias".
        $this->post(route('surgery-schedule.schedule', $surgery), ['operating_room_id' => $this->room->id, 'start' => '2026-11-20T08:00', 'duration_minutes' => 60, 'surgeon_id' => $this->surgeon->id])->assertSessionHasNoErrors();
        $this->travelTo('2026-11-21 11:30:00');
        $this->get(route('surgery-record.show', $surgery))->assertOk();
        $this->actingAs($this->surgeonAccount->user)->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page->has('surgeries', 1));
        $this->travelTo('2026-11-21 12:30:00');
        $this->get(route('surgery-record.show', $surgery))->assertForbidden();
        $this->get(route('surgery-record.mine'))->assertInertia(fn (Assert $page) => $page->has('surgeries', 0));
        $this->actingAs($this->center->user)->get(route('surgery-record.show', $surgery))->assertForbidden();
        // Rescheduled, it opens again.
        $this->post(route('surgery-schedule.reschedule', $surgery), ['operating_room_id' => $this->room->id, 'start' => '2026-11-23T08:00', 'duration_minutes' => 60, 'reason' => 'Paciente não compareceu.'])->assertSessionHasNoErrors();
        $this->get(route('surgery-record.show', $surgery))->assertOk();
    }
}
