<?php

namespace Tests\Feature\Scheduling;

use App\Models\AuditEvent;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Shift;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Scheduling\ShiftStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShiftManagementTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Membership $coordinator;

    private Unit $unit;

    private Sector $icu;

    private JobFunction $doctor;

    private Professional $professional;

    private Qualification $qualification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $this->admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['name' => 'Hospital Horizonte', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->icu = Sector::factory()->for($this->unit)->create(['name' => 'UTI']);
        $this->doctor = JobFunction::factory()->for($tenant)->create(['name' => 'Médico']);
        $this->coordinator = Membership::factory()->for($tenant)->create();
        $this->coordinator->grant(Role::ShiftCoordinator, $this->unit);
        $this->professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Ana Sintética']);

        app(CurrentTenant::class)->set($tenant);
        $this->qualification = Qualification::request($this->professional, $this->unit, ['job_function_id' => $this->doctor->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$this->icu->id]);
        $this->qualification->transitionTo(QualificationStatus::Approved, null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createShift(array $data = [], ?Membership $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->coordinator)->user)->post(route('units.shifts.store', $this->unit), [
            'sector_id' => $this->icu->id,
            'job_function_id' => $this->doctor->id,
            'professional_id' => $this->professional->id,
            'starts_at' => '2026-11-10T07:00',
            'ends_at' => '2026-11-10T19:00',
            ...$data,
        ]);
    }

    private function latestShift(): Shift
    {
        return Shift::withoutTenantScope()->latest('id')->firstOrFail();
    }

    public function test_coordinator_creates_draft_in_unit_timezone_and_publishes_it(): void
    {
        $this->createShift()->assertSessionHasNoErrors()->assertRedirect();
        $shift = $this->latestShift();

        $this->assertSame(ShiftStatus::Draft, $shift->status);
        // 07:00 in São Paulo (UTC-3) is 10:00 UTC.
        $this->assertSame('2026-11-10 10:00:00', $shift->starts_at->utc()->format('Y-m-d H:i:s'));

        $this->post(route('shifts.publish', $shift))->assertSessionHasNoErrors();
        $shift->refresh();

        $this->assertSame(ShiftStatus::Published, $shift->status);
        $this->assertSame($this->qualification->id, $shift->qualification_id);
        $this->assertSame(2, $shift->eligibility_version);
        $this->assertSame(['shift.created', 'shift.published'], AuditEvent::where('action', 'like', 'shift.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_ineligible_professional_is_rejected_with_reasons(): void
    {
        $outsider = Professional::factory()->create(['base_unit_id' => $this->unit->id]);

        $this->createShift(['professional_id' => $outsider->id])
            ->assertSessionHasErrors(['professional_id' => 'Sem habilitação para esta função nesta unidade.']);
        $this->assertSame(0, Shift::withoutTenantScope()->count());
    }

    public function test_publication_revalidates_eligibility(): void
    {
        $this->createShift();
        $shift = $this->latestShift();
        $this->qualification->transitionTo(QualificationStatus::Suspended, 'Motivo sintético');

        $this->post(route('shifts.publish', $shift))
            ->assertSessionHasErrors(['professional_id' => 'A habilitação está suspensa.']);
        $this->assertSame(ShiftStatus::Draft, $shift->fresh()?->status);
    }

    public function test_overlapping_shifts_of_the_same_professional_are_blocked_but_adjacent_ones_are_not(): void
    {
        $this->createShift()->assertSessionHasNoErrors();

        $this->createShift(['starts_at' => '2026-11-10T18:00', 'ends_at' => '2026-11-11T06:00'])
            ->assertSessionHasErrors(['professional_id' => 'O profissional já tem outro plantão nesse horário.']);

        // Ending exactly when the next starts is not an overlap.
        $this->createShift(['starts_at' => '2026-11-10T19:00', 'ends_at' => '2026-11-11T07:00'])->assertSessionHasNoErrors();
        $this->assertSame(2, Shift::withoutTenantScope()->count());
    }

    public function test_database_rejects_overlap_even_bypassing_the_application(): void
    {
        $this->createShift();
        $shift = $this->latestShift();

        $this->expectException(QueryException::class);
        DB::table('shifts')->insert([
            ...collect($shift->getAttributes())->except('id')->all(),
            'starts_at' => '2026-11-10 12:00:00+00',
            'ends_at' => '2026-11-10 14:00:00+00',
        ]);
    }

    public function test_cancelled_shift_frees_the_slot_and_requires_a_reason(): void
    {
        $this->createShift();
        $shift = $this->latestShift();
        $this->post(route('shifts.publish', $shift));

        $this->post(route('shifts.cancel', $shift), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('shifts.cancel', $shift), ['reason' => 'Motivo sintético'])->assertSessionHasNoErrors();

        $this->assertSame(ShiftStatus::Cancelled, $shift->fresh()?->status);
        $this->createShift()->assertSessionHasNoErrors();
    }

    public function test_published_shift_cannot_be_edited_and_started_one_cannot_be_cancelled(): void
    {
        $this->createShift();
        $shift = $this->latestShift();
        $this->post(route('shifts.publish', $shift))->assertSessionHasNoErrors();

        $this->get(route('shifts.edit', $shift))->assertForbidden();
        $this->put(route('shifts.update', $shift), ['sector_id' => $this->icu->id, 'job_function_id' => $this->doctor->id, 'starts_at' => '2026-11-10T08:00', 'ends_at' => '2026-11-10T19:00'])
            ->assertSessionHasErrors('shift');

        $this->travelTo('2026-11-10 12:00:00');
        $this->post(route('shifts.cancel', $shift), ['reason' => 'Motivo sintético'])
            ->assertSessionHasErrors(['reason' => 'O plantão já começou e não pode ser cancelado.']);
    }

    public function test_shift_that_already_started_cannot_be_created_or_published(): void
    {
        $this->createShift(['starts_at' => '2026-10-01T07:00', 'ends_at' => '2026-10-01T19:00'])->assertSessionHasErrors('starts_at');

        $this->createShift();
        $shift = $this->latestShift();
        $this->travelTo('2026-11-10 12:00:00');
        $this->post(route('shifts.publish', $shift))->assertSessionHasErrors('starts_at');
    }

    public function test_publish_locks_and_validates_the_current_professional_not_a_stale_copy(): void
    {
        $this->createShift(['professional_id' => '']);
        $shift = $this->latestShift();
        // A concurrent edit assigned someone after this request loaded the shift.
        Shift::withoutTenantScope()->whereKey($shift->id)->update(['professional_id' => $this->professional->id]);

        $shift->publish();

        // The decision used the professional in the database (eligibility recorded for them).
        $this->assertSame($this->qualification->id, $shift->fresh()?->qualification_id);
    }

    public function test_open_vacancy_can_be_published(): void
    {
        $this->createShift(['professional_id' => ''])->assertSessionHasNoErrors();
        $shift = $this->latestShift();

        $this->post(route('shifts.publish', $shift))->assertSessionHasNoErrors();
        $this->assertNull($shift->fresh()?->professional_id);
        $this->assertSame(ShiftStatus::Published, $shift->fresh()?->status);
    }

    public function test_sector_of_another_unit_and_end_before_start_are_rejected(): void
    {
        $otherSector = Sector::factory()->for(Unit::factory()->for($this->admin->tenant))->create();

        $this->createShift(['sector_id' => $otherSector->id])->assertSessionHasErrors('sector_id');
        $this->createShift(['ends_at' => '2026-11-10T06:00'])->assertSessionHasErrors('ends_at');
    }

    public function test_roles_follow_the_users_decision(): void
    {
        $manager = Membership::factory()->for($this->admin->tenant)->create();
        $manager->grant(Role::UnitManager, $this->unit);
        $otherCoordinator = Membership::factory()->for($this->admin->tenant)->create();
        $otherCoordinator->grant(Role::ShiftCoordinator, Unit::factory()->for($this->admin->tenant)->create());
        $professionalRole = Membership::factory()->for($this->admin->tenant)->create();
        $professionalRole->grant(Role::Professional, $this->unit);
        $this->createShift();
        $shift = $this->latestShift();

        // Unit manager reads but does not build the schedule.
        $this->actingAs($manager->user)->get(route('shifts.show', $shift))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canPublish', false));
        $this->post(route('shifts.publish', $shift))->assertForbidden();
        $this->createShift([], $manager)->assertForbidden();

        $this->actingAs($otherCoordinator->user)->get(route('shifts.show', $shift))->assertForbidden();
        $this->createShift([], $otherCoordinator)->assertForbidden();

        $this->actingAs($professionalRole->user)->get(route('shifts.index'))->assertForbidden();

        // Network admin may publish.
        $this->actingAs($this->admin->user)->post(route('shifts.publish', $shift))->assertSessionHasNoErrors();
    }

    public function test_shifts_of_another_tenant_are_not_found(): void
    {
        app(CurrentTenant::class)->set(null);
        $otherAdmin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->createShift();
        $shift = $this->latestShift();

        $this->actingAs($otherAdmin->user)->get(route('shifts.show', $shift))->assertNotFound();
        $this->post(route('shifts.publish', $shift))->assertNotFound();
    }

    public function test_weekly_list_shows_local_times_including_midnight_crossing(): void
    {
        $this->createShift(['starts_at' => '2026-11-10T19:00', 'ends_at' => '2026-11-11T07:00']);

        $this->get(route('shifts.index', ['unit' => $this->unit->id, 'week' => '2026-11-12']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('week', '2026-11-09')
                ->has('shifts', 1)
                ->where('shifts.0.date', '2026-11-10')
                ->where('shifts.0.start', '19:00')
                ->where('shifts.0.endDate', '2026-11-11')
                ->where('shifts.0.professional', 'Ana Sintética')
                ->where('canManage', true));
    }
}
