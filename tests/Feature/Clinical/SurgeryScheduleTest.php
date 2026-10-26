<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The surgical agenda (step 5b.3a, ADR-0009): the Surgical center role of the unit books room, start, length and
 * surgeon; never two surgeries in a room at once; reschedules and cancels with a reason; keeps the team.
 */
class SurgeryScheduleTest extends ClinicalTestCase
{
    private Membership $center;

    private Professional $anesthetist;

    private OperatingRoom $room;

    private OperatingRoom $second;

    private OperatingRoom $elsewhere;

    private Visit $visit;

    private Membership $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->patient->tenant;
        $this->center = Membership::factory()->for($tenant)->create();
        $this->center->grant(Role::SurgicalCenter, $this->unit);
        app(CurrentTenant::class)->set($tenant);
        SurgicalProcedure::query()->create(['name' => 'Apendicectomia', 'duration_minutes' => 60, 'size' => 'medium']);
        $this->room = OperatingRoom::query()->create(['unit_id' => $this->unit->id, 'name' => 'Sala 1']);
        $this->second = OperatingRoom::query()->create(['unit_id' => $this->unit->id, 'name' => 'Sala 2']);
        $this->elsewhere = OperatingRoom::query()->create(['unit_id' => $this->other->id, 'name' => 'Sala 1']);
        $this->anesthetist = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Dr. Caio']);
        Qualification::request($this->anesthetist, $this->unit, ['job_function_id' => JobFunction::query()->value('id'), 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::query()->where('unit_id', $this->unit->id)->value('id')])
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
    }

    private function order(string $urgency = 'elective'): Surgery
    {
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.visit.store', $this->visit), [
            'surgical_procedure_id' => SurgicalProcedure::withoutTenantScope()->value('id'), 'laterality' => 'none', 'urgency' => $urgency,
            'desired_date' => '2026-11-20', 'indication' => 'Indicação clínica sigilosa.', 'password' => 'password',
        ])->assertSessionHasNoErrors();

        return Surgery::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function booking(array $overrides = []): array
    {
        return ['operating_room_id' => $this->room->id, 'start' => '2026-11-20T08:00', 'duration_minutes' => 90, 'surgeon_id' => $this->doctor->id, ...$overrides];
    }

    public function test_the_surgical_center_sees_the_agenda_without_a_read_and_without_the_indication(): void
    {
        $surgery = $this->order();
        $this->actingAs($this->center->user)->get(route('surgery-schedule.index'))->assertInertia(fn (Assert $page) => $page
            ->component('surgery-schedule/index')->where('unitId', $this->unit->id)->has('requested', 1)
            ->where('requested.0.patient', 'Joana Prado')->where('requested.0.procedure', 'Apendicectomia')->missing('requested.0.indication')
            ->has('rooms', 2));
        $this->get(route('surgery-schedule.index', ['date' => '2026-02-31']))->assertInertia(fn (Assert $page) => $page->where('date', '2026-11-13'));
        $this->get(route('surgery-schedule.index', ['date' => 'x']))->assertOk();
        $this->get(route('surgery-schedule.show', $surgery))->assertInertia(fn (Assert $page) => $page
            ->component('surgery-schedule/show')->where('surgery.status', 'requested')->where('surgery.durationMinutes', 60)
            ->has('professionals', 2)->has('rooms', 2)->missing('surgery.indication'));
        $this->assertSame(0, DB::table('clinical_reads')->where('user_id', $this->center->user_id)->count());
        $this->assertStringNotContainsString('sigilosa', (string) $this->get(route('surgery-schedule.show', $surgery))->getContent());

        // Only the Surgical center role, only of its unit.
        $this->actingAs($this->doctorAccount->user)->get(route('surgery-schedule.index'))->assertForbidden();
        $this->get(route('surgery-schedule.show', $surgery))->assertForbidden();
        $this->post(route('surgery-schedule.schedule', $surgery), $this->booking())->assertForbidden();
        $elsewhere = Membership::factory()->for($this->patient->tenant)->create();
        $elsewhere->grant(Role::SurgicalCenter, $this->other);
        $this->actingAs($elsewhere->user)->get(route('surgery-schedule.show', $surgery))->assertForbidden();
        $this->post(route('surgery-schedule.cancel', $surgery), ['reason' => 'X'])->assertForbidden();
        $this->actingAs($this->center->user)->post(route('surgery-schedule.schedule', $surgery), $this->booking())->assertSessionHasNoErrors();
        $member = SurgeryTeamMember::withoutTenantScope()->sole();
        $this->actingAs($elsewhere->user)->post(route('surgery-schedule.reschedule', $surgery), $this->booking(['reason' => 'X']))->assertForbidden();
        $this->post(route('surgery-schedule.team.store', $surgery), ['professional_id' => $this->anesthetist->id, 'role' => 'assistant'])->assertForbidden();
        $this->post(route('surgery-schedule.team.remove', $member))->assertForbidden();
        // Another client does not find them.
        $this->actingAs($this->stranger->user)->get(route('surgery-schedule.show', $surgery))->assertNotFound();
        $this->post(route('surgery-schedule.team.remove', $member))->assertNotFound();
    }

    public function test_scheduling_books_the_room_and_the_team_and_never_overlaps(): void
    {
        $surgery = $this->order();
        $this->actingAs($this->center->user);
        $schedule = fn (Surgery $target, array $overrides) => $this->post(route('surgery-schedule.schedule', $target), $this->booking($overrides));
        $schedule($surgery, ['operating_room_id' => $this->elsewhere->id])->assertSessionHasErrorsIn('schedule', 'operating_room_id');
        $schedule($surgery, ['start' => '2026-11-13T08:00'])->assertSessionHasErrorsIn('schedule', ['start' => 'Este horário já passou.']);
        $schedule($surgery, ['surgeon_id' => $this->nurse->id])->assertSessionHasErrorsIn('schedule', 'surgeon_id');
        $schedule($surgery, ['anesthetist_id' => $this->doctor->id])->assertSessionHasErrorsIn('schedule', 'anesthetist_id');
        $schedule($surgery, ['urgent_reason' => 'Agora.'])->assertSessionHasErrorsIn('schedule', 'urgent_reason');
        $schedule($surgery, ['duration_minutes' => 2000])->assertSessionHasErrorsIn('schedule', 'duration_minutes');
        $this->assertSame('requested', $surgery->fresh()->status);

        $schedule($surgery, ['anesthetist_id' => $this->anesthetist->id])->assertSessionHasNoErrors()->assertRedirect(route('surgery-schedule.show', $surgery));
        $surgery->refresh();
        $this->assertSame(['scheduled', $this->room->id, '2026-11-20 11:00:00', '2026-11-20 12:30:00'], [$surgery->status, $surgery->operating_room_id, $surgery->scheduled_start?->utc()->toDateTimeString(), $surgery->scheduled_end?->utc()->toDateTimeString()]);
        $this->assertSame([[$this->doctor->id, 'surgeon'], [$this->anesthetist->id, 'anesthetist']], $surgery->team()->withoutGlobalScopes()->get()->map(fn (SurgeryTeamMember $member) => [$member->professional_id, $member->role])->all());
        $this->assertEquals(['unit_id' => $this->unit->id, 'operating_room_id' => $this->room->id, 'urgency' => 'elective'], AuditEvent::where('action', 'surgery.scheduled')->sole()->context);
        $schedule($surgery, [])->assertSessionHasErrorsIn('schedule', 'operating_room_id');

        // The same room at an overlapping time is refused; right after it, or in another room, is fine.
        $next = $this->order();
        $this->actingAs($this->center->user);
        $schedule($next, ['start' => '2026-11-20T09:00'])->assertSessionHasErrorsIn('schedule', ['start' => 'A sala já está ocupada neste horário. Escolha outro horário ou outra sala.']);
        $schedule($next, ['start' => '2026-11-20T09:00', 'operating_room_id' => $this->second->id])->assertSessionHasNoErrors();
        $third = $this->order();
        $this->actingAs($this->center->user);
        $schedule($third, ['start' => '2026-11-20T09:30'])->assertSessionHasNoErrors();
        $this->get(route('surgery-schedule.index', ['date' => '2026-11-20']))->assertInertia(fn (Assert $page) => $page
            ->has('requested', 0)->where('rooms.0.name', 'Sala 1')->has('rooms.0.surgeries', 2)->where('rooms.0.surgeries.0.start', '20/11 08:00')
            ->where('rooms.0.surgeries.0.surgeons', ['Dra. Ana'])->has('rooms.1.surgeries', 1));

        try {
            DB::transaction(fn () => DB::table('surgeries')->where('id', $third->id)->update(['scheduled_start' => '2026-11-20 11:30:00+00']));
            $this->fail('Two surgeries overlapping in a room must be refused.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode());
        }
    }

    public function test_reschedule_team_changes_and_cancel_keep_the_history(): void
    {
        $surgery = $this->order();
        $this->actingAs($this->center->user)->post(route('surgery-schedule.schedule', $surgery), $this->booking())->assertSessionHasNoErrors();

        $this->post(route('surgery-schedule.reschedule', $surgery), $this->booking(['start' => '2026-11-21T10:00']))->assertSessionHasErrorsIn('reschedule', 'reason');
        $this->post(route('surgery-schedule.reschedule', $surgery), $this->booking(['start' => '2026-11-21T10:00', 'operating_room_id' => $this->second->id, 'reason' => 'Sala em manutenção.']))->assertSessionHasNoErrors();
        $surgery->refresh();
        $this->assertSame([$this->second->id, '2026-11-21 13:00:00'], [$surgery->operating_room_id, $surgery->scheduled_start?->utc()->toDateTimeString()]);
        $blocker = $this->order();
        $this->actingAs($this->center->user)->post(route('surgery-schedule.schedule', $blocker), $this->booking(['start' => '2026-11-22T10:00']))->assertSessionHasNoErrors();
        $this->post(route('surgery-schedule.reschedule', $surgery), $this->booking(['start' => '2026-11-22T11:00', 'reason' => 'Outra troca.']))->assertSessionHasErrorsIn('reschedule', ['start' => 'A sala já está ocupada neste horário. Escolha outro horário ou outra sala.']);
        $this->assertSame(1, DB::table('surgery_reschedules')->count());
        $this->assertSame([[$this->room->id, '2026-11-20 11:00:00', 'Sala em manutenção.']], DB::table('surgery_reschedules')->get()->map(fn ($row) => [$row->previous_room_id, substr((string) $row->previous_start, 0, 19), $row->reason])->all());

        // Team: no duplicate; the last surgeon stays; removing keeps the row.
        $this->post(route('surgery-schedule.team.store', $surgery), ['professional_id' => $this->doctor->id, 'role' => 'assistant'])->assertSessionHasErrorsIn('team', ['professional_id' => 'Este profissional já está na equipe.']);
        $this->post(route('surgery-schedule.team.store', $surgery), ['professional_id' => $this->nurse->id, 'role' => 'scrub'])->assertSessionHasErrorsIn('team', 'professional_id');
        $surgeon = SurgeryTeamMember::withoutTenantScope()->where('surgery_id', $surgery->id)->sole();
        $this->post(route('surgery-schedule.team.remove', $surgeon))->assertSessionHasErrorsIn("member{$surgeon->id}", ['member' => 'A cirurgia precisa de um cirurgião. Inclua outro antes de retirar este.']);
        $this->post(route('surgery-schedule.team.store', $surgery), ['professional_id' => $this->anesthetist->id, 'role' => 'surgeon'])->assertSessionHasNoErrors();
        $this->post(route('surgery-schedule.team.remove', $surgeon))->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('surgery_team_members')->where('surgery_id', $surgery->id)->count());
        $this->assertSame([$this->anesthetist->id], $surgery->team()->withoutGlobalScopes()->pluck('professional_id')->all());
        $this->assertSame(1, AuditEvent::where('action', 'surgery.team_removed')->count());

        // Cancelled by the center with a reason: the room is free again.
        $this->post(route('surgery-schedule.cancel', $surgery), ['reason' => ' '])->assertSessionHasErrorsIn('cancel', 'reason');
        $this->post(route('surgery-schedule.cancel', $surgery), ['reason' => 'Paciente sem condição clínica.'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $surgery->fresh()->status);
        $this->assertEquals(['unit_id' => $this->unit->id, 'by' => 'surgical_center'], AuditEvent::where('action', 'surgery.cancelled')->sole()->context);
        $this->post(route('surgery-schedule.cancel', $surgery), ['reason' => 'De novo.'])->assertSessionHasErrorsIn('cancel', 'reason');
        $other = $this->order();
        $this->actingAs($this->center->user)->post(route('surgery-schedule.schedule', $other), $this->booking(['start' => '2026-11-21T10:00', 'operating_room_id' => $this->second->id]))->assertSessionHasNoErrors();
    }

    public function test_an_urgent_surgery_is_booked_at_once_with_the_reason_and_the_author_cancels_a_scheduled_one(): void
    {
        $surgery = $this->order('urgent');
        $this->actingAs($this->center->user);
        $this->post(route('surgery-schedule.schedule', $surgery), $this->booking(['start' => '2026-11-13T09:10']))->assertSessionHasErrorsIn('schedule', 'urgent_reason');
        $this->post(route('surgery-schedule.schedule', $surgery), $this->booking(['start' => '2026-11-13T08:29', 'urgent_reason' => 'Abdome agudo.']))->assertSessionHasErrorsIn('schedule', ['start' => 'Este horário já passou.']);
        $this->post(route('surgery-schedule.schedule', $surgery), $this->booking(['start' => '2026-11-13T08:31', 'urgent_reason' => ' Abdome agudo. ']))->assertSessionHasNoErrors();
        $this->assertSame(['scheduled', 'Abdome agudo.'], [$surgery->fresh()->status, $surgery->fresh()->urgent_reason]);

        // The author still cancels before the start.
        $this->actingAs($this->doctorAccount->user)->get(route('surgery-orders.visit', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('surgeries.0.status', 'scheduled')->where('surgeries.0.scheduledStart', '13/11/2026 08:31')->where('surgeries.0.canCancel', true));
        $this->post(route('surgery-orders.cancel', $surgery), ['reason' => 'Conduta clínica.'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $surgery->fresh()->status);
    }
}
