<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\ScheduleBlock;
use App\Models\ScheduleRule;
use App\Models\Sector;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Membership $manager;

    private Unit $unit;

    private Unit $other;

    private Professional $doctor;

    private Professional $unqualified;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->other = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->manager = Membership::factory()->for($tenant)->create();
        $this->manager->grant(Role::UnitManager, $this->unit);
        $function = JobFunction::factory()->for($tenant)->create();
        app(CurrentTenant::class)->set($tenant);
        $this->doctor = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Dra. Ana']);
        $this->unqualified = Professional::factory()->create(['base_unit_id' => $this->unit->id]);
        foreach ([$this->unit, $this->other] as $unit) {
            Qualification::request($this->doctor, $unit, ['job_function_id' => $function->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::factory()->for($unit)->create()->id])
                ->transitionTo(QualificationStatus::Approved, null);
        }
        app(CurrentTenant::class)->set(null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function rule(array $data = []): array
    {
        return ['professional_id' => $this->doctor->id, 'weekday' => 1, 'start' => '08:00', 'end' => '12:00', 'slot_minutes' => 20, ...$data];
    }

    public function test_the_manager_sets_weekly_windows_that_never_overlap_for_the_same_professional(): void
    {
        $this->actingAs($this->manager->user)->post(route('schedules.rules.store', $this->unit), $this->rule())->assertSessionHasNoErrors();
        $rule = ScheduleRule::withoutTenantScope()->sole();
        $this->assertSame([480, 720, 20], [$rule->start_minute, $rule->end_minute, $rule->slot_minutes]);
        $this->assertTrue(AuditEvent::where('action', 'schedule.rule_created')->exists());

        $this->post(route('schedules.rules.store', $this->unit), $this->rule(['start' => '11:00', 'end' => '13:00']))->assertSessionHasErrors('start');
        $this->post(route('schedules.rules.store', $this->unit), $this->rule(['weekday' => 2]))->assertSessionHasNoErrors();
        $this->post(route('schedules.rules.store', $this->unit), $this->rule(['professional_id' => $this->unqualified->id]))->assertSessionHasErrors('professional_id');
        $this->post(route('schedules.rules.store', $this->unit), $this->rule(['start' => '12:00', 'end' => '08:00']))->assertSessionHasErrors('end');

        $this->get(route('schedules.index'))->assertInertia(fn (Assert $page) => $page->component('schedules/index')
            ->has('rules', 2)->has('professionals', 1)->where('professionals.0.name', 'Dra. Ana'));

        // Removing frees the window for a new one.
        $this->post(route('schedules.rules.deactivate', [$this->unit, $rule]))->assertSessionHasNoErrors();
        $this->post(route('schedules.rules.store', $this->unit), $this->rule(['start' => '09:00', 'end' => '11:00']))->assertSessionHasNoErrors();
    }

    public function test_the_same_professional_cannot_be_in_two_units_at_the_same_time(): void
    {
        $this->actingAs($this->admin->user)->post(route('schedules.rules.store', $this->unit), $this->rule())->assertSessionHasNoErrors();
        $this->post(route('schedules.rules.store', $this->other), $this->rule(['start' => '10:00', 'end' => '14:00']))->assertSessionHasErrors('start');
        $this->post(route('schedules.rules.store', $this->other), $this->rule(['start' => '13:00', 'end' => '17:00']))->assertSessionHasNoErrors();
    }

    public function test_blocks_use_the_units_clock_and_only_managers_of_the_unit_act(): void
    {
        $this->actingAs($this->manager->user)->post(route('schedules.blocks.store', $this->unit), [
            'professional_id' => $this->doctor->id, 'starts_at' => '2026-12-20T08:00', 'ends_at' => '2026-12-31T18:00', 'reason' => 'Férias',
        ])->assertSessionHasNoErrors();
        $block = ScheduleBlock::withoutTenantScope()->sole();
        $this->assertSame('2026-12-20 11:00:00', $block->starts_at->utc()->format('Y-m-d H:i:s'));

        // A manager of another unit, a reception desk or another client cannot touch this unit's grid.
        $this->post(route('schedules.rules.store', $this->other), $this->rule())->assertForbidden();
        $reception = Membership::factory()->for($this->unit->tenant)->create();
        $reception->grant(Role::Reception, $this->unit);
        $this->actingAs($reception->user)->get(route('schedules.index'))->assertForbidden();

        $this->actingAs($this->manager->user)->post(route('schedules.blocks.cancel', [$this->unit, $block]))->assertSessionHasNoErrors();
        $this->assertNotNull($block->fresh()->cancelled_at);
        $this->post(route('schedules.blocks.cancel', [$this->unit, $block]))->assertNotFound();
    }
}
