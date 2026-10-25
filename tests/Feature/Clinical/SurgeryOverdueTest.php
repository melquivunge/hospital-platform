<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Membership;
use App\Models\OperatingRoom;
use App\Models\Surgery;
use App\Models\SurgicalProcedure;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 5b.3c (ADR-0009): a surgery still scheduled 24 hours after its start, and a request never scheduled 7 days
 * after the wished date, reach the unit managers once a day of the unit (from 7 h), without clinical content.
 */
class SurgeryOverdueTest extends ClinicalTestCase
{
    private Membership $center;

    private OperatingRoom $room;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->center = Membership::factory()->for($this->unit->tenant)->create();
        $this->center->grant(Role::SurgicalCenter, $this->unit);
        app(CurrentTenant::class)->set($this->patient->tenant);
        SurgicalProcedure::query()->create(['name' => 'Procedimento sigiloso', 'duration_minutes' => 60, 'size' => 'medium']);
        $this->room = OperatingRoom::query()->create(['unit_id' => $this->unit->id, 'name' => 'Sala 1']);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Dor.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    private function order(string $desired = '2026-11-15'): Surgery
    {
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.visit.store', $this->visit), [
            'surgical_procedure_id' => SurgicalProcedure::withoutTenantScope()->value('id'), 'laterality' => 'none', 'urgency' => 'elective',
            'desired_date' => $desired, 'indication' => 'Indicação.', 'password' => 'password',
        ])->assertSessionHasNoErrors();

        return Surgery::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function schedule(Surgery $surgery, string $start): void
    {
        $this->actingAs($this->center->user)->post(route('surgery-schedule.schedule', $surgery), [
            'operating_room_id' => $this->room->id, 'start' => $start, 'duration_minutes' => 60, 'surgeon_id' => $this->doctor->id,
        ])->assertSessionHasNoErrors();
    }

    /**
     * @return array<int, list<string>> user id => titles
     */
    private function inbox(): array
    {
        (new DeliverNotifications)->handle();

        return DB::table('user_notifications')->orderBy('id')->get()->groupBy('user_id')->map(fn ($rows) => $rows->pluck('title')->all())->all();
    }

    public function test_stalled_surgeries_reach_the_managers_once_a_day_without_clinical_content(): void
    {
        $forgotten = $this->order();
        $this->schedule($forgotten, '2026-11-14T08:00');
        $this->schedule($this->order(), '2026-11-30T08:00');
        $cancelled = $this->order();
        $this->schedule($cancelled, '2026-11-14T10:00');
        $this->actingAs($this->center->user)->post(route('surgery-schedule.cancel', $cancelled), ['reason' => 'Paciente desistiu.'])->assertSessionHasNoErrors();
        $waiting = $this->order();

        // 23 hours after the planned end (09:00 in the unit): nothing yet.
        $this->travelTo('2026-11-15 11:00:00');
        $this->artisan('surgeries:overdue')->assertSuccessful();
        $this->assertSame(0, AuditEvent::where('action', 'surgery.overdue')->count());

        // 24 hours after, at 9 h in the unit: only the forgotten scheduled one; the request is still within 7 days.
        $this->travelTo('2026-11-15 12:00:00');
        $this->artisan('surgeries:overdue')->assertSuccessful();
        $this->artisan('surgeries:overdue')->assertSuccessful();
        $this->assertEquals(['unit_id' => $this->unit->id, 'date' => '2026-11-15', 'scheduled' => 1, 'requested' => 0], AuditEvent::where('action', 'surgery.overdue')->sole()->context);
        $this->assertSame([$this->manager->user_id => ['Cirurgias: 1 agendada(s) com fim previsto há mais de 24 horas']], $this->inbox());

        // The manager sees when, where and who ordered; never the patient or the procedure.
        $this->actingAs($this->manager->user)->get(route('surgery-overdue.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page
            ->component('surgery-overdue/index')->has('scheduled', 1)->where('scheduled.0.id', $forgotten->id)->where('scheduled.0.start', '14/11/2026 08:00')
            ->where('scheduled.0.room', 'Sala 1')->where('scheduled.0.place', 'Urgência')->where('scheduled.0.author', $this->doctorAccount->user->name)->has('requested', 0));
        $content = (string) $this->get(route('surgery-overdue.index', ['unit' => $this->unit->id]))->getContent();
        $this->assertStringNotContainsString('sigiloso', $content);
        $this->assertStringNotContainsString('Joana Prado', $content);

        // Seven days after the wished date the request still opens the record: not stalled yet.
        $this->travelTo('2026-11-22 12:00:00');
        $this->artisan('surgeries:overdue')->assertSuccessful();
        $this->assertEquals(['unit_id' => $this->unit->id, 'date' => '2026-11-22', 'scheduled' => 1, 'requested' => 0], AuditEvent::where('action', 'surgery.overdue')->latest('id')->firstOrFail()->context);
        // A manager of the other unit asking for this one gets their own, never these surgeries.
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::UnitManager, $this->other);
        $this->actingAs($elsewhere->user)->get(route('surgery-overdue.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page
            ->where('unitId', $this->other->id)->has('units', 1)->has('scheduled', 0)->has('requested', 0));

        // Eight days after the wished date, the request never scheduled joins; the next day is another alert.
        $this->travelTo('2026-11-23 12:00:00');
        $this->artisan('surgeries:overdue')->assertSuccessful();
        $this->assertEquals(['unit_id' => $this->unit->id, 'date' => '2026-11-23', 'scheduled' => 1, 'requested' => 1], AuditEvent::where('action', 'surgery.overdue')->latest('id')->firstOrFail()->context);
        $scheduledOnly = 'Cirurgias: 1 agendada(s) com fim previsto há mais de 24 horas';
        $this->assertSame([$this->manager->user_id => [$scheduledOnly, $scheduledOnly, $scheduledOnly.' e 1 pedido(s) sem agendamento']], $this->inbox());
        $this->actingAs($this->manager->user)->get(route('surgery-overdue.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page->where('requested.0.id', $waiting->id)->where('requested.0.desiredDate', '15/11/2026'));

        // Cancelling clears it: nothing more to say.
        $this->actingAs($this->center->user)->post(route('surgery-schedule.cancel', $forgotten), ['reason' => 'Não realizada.'])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.cancel', $waiting), ['reason' => 'Conduta clínica.'])->assertSessionHasNoErrors();
        $this->travelTo('2026-11-24 12:00:00');
        $this->artisan('surgeries:overdue')->assertSuccessful();
        $this->assertSame(3, AuditEvent::where('action', 'surgery.overdue')->count());
    }

    public function test_before_seven_in_the_unit_nothing_and_without_a_manager_the_network_admins_hear(): void
    {
        $this->schedule($this->order(), '2026-11-14T08:00');
        // 9 h UTC is 6 h in São Paulo.
        $this->travelTo('2026-11-16 09:00:00');
        $this->artisan('surgeries:overdue')->assertSuccessful();
        $this->assertSame(0, AuditEvent::where('action', 'surgery.overdue')->count());

        $this->manager->revoke();
        $admin = Membership::query()->withoutGlobalScopes()->where('tenant_id', $this->unit->tenant_id)->whereHas('roleAssignments', fn ($query) => $query->withoutGlobalScopes()->where('role', Role::NetworkAdmin))->firstOrFail();
        $this->travelTo('2026-11-16 10:30:00');
        $this->artisan('surgeries:overdue')->assertSuccessful();
        $this->assertSame([$admin->user_id => ['Cirurgias: 1 agendada(s) com fim previsto há mais de 24 horas']], $this->inbox());
        $this->actingAs($admin->user)->get(route('surgery-overdue.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page->has('scheduled', 1));
        $this->actingAs($this->reception->user)->get(route('surgery-overdue.index'))->assertForbidden();
        $this->actingAs($this->center->user)->get(route('surgery-overdue.index'))->assertForbidden();
    }
}
