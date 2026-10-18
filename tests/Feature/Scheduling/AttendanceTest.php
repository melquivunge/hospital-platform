<?php

namespace Tests\Feature\Scheduling;

use App\Models\AttendanceEvent;
use App\Models\AttendancePoint;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Shift;
use App\Models\ShiftRequest;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Scheduling\RequestStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Synthetic configuration (not product values): point at (-23.550520, -46.633308) with a 100 m radius,
 * maximum accuracy 50 m, entry from 30 min before the start, exit up to 60 min after the end.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -23.550520;

    private const LNG = -46.633308;

    private Membership $admin;

    private Membership $coordinator;

    private Membership $manager;

    private Unit $unit;

    private Sector $icu;

    private JobFunction $doctor;

    private Professional $ana;

    private Membership $anaAccess;

    private Professional $bruno;

    private Membership $brunoAccess;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $this->admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->icu = Sector::factory()->for($this->unit)->create(['name' => 'UTI']);
        $this->doctor = JobFunction::factory()->for($tenant)->create();
        $this->coordinator = Membership::factory()->for($tenant)->create();
        $this->coordinator->grant(Role::ShiftCoordinator, $this->unit);
        $this->manager = Membership::factory()->for($tenant)->create();
        $this->manager->grant(Role::UnitManager, $this->unit);

        app(CurrentTenant::class)->set($tenant);
        [$this->ana, $this->anaAccess] = $this->linkedProfessional('Ana Sintética');
        [$this->bruno, $this->brunoAccess] = $this->linkedProfessional('Bruno Sintético');

        // 07:00–19:00 in São Paulo = 10:00–22:00 UTC.
        $this->actingAs($this->coordinator->user)->post(route('units.shifts.store', $this->unit), [
            'sector_id' => $this->icu->id, 'job_function_id' => $this->doctor->id, 'professional_id' => $this->ana->id,
            'starts_at' => '2026-11-12T07:00', 'ends_at' => '2026-11-12T19:00',
        ])->assertSessionHasNoErrors();
        $this->shift = Shift::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('shifts.publish', $this->shift))->assertSessionHasNoErrors();
    }

    /**
     * @return array{Professional, Membership}
     */
    private function linkedProfessional(string $name): array
    {
        $access = Membership::factory()->for($this->unit->tenant)->create();
        $access->grant(Role::Professional, $this->unit);
        $professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => $name, 'membership_id' => $access->id]);
        Qualification::request($professional, $this->unit, ['job_function_id' => $this->doctor->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$this->icu->id])
            ->transitionTo(QualificationStatus::Approved, null);

        return [$professional, $access];
    }

    private function configure(): void
    {
        foreach (['attendance_max_accuracy' => 50, 'attendance_check_in_lead' => 30, 'attendance_check_out_grace' => 60] as $key => $value) {
            $this->actingAs($this->admin->user)->post(route('policies.publish', $key), [
                'expected_version' => 0, 'value' => $value, 'delegable' => false, 'reason' => 'Valor sintético de teste',
            ])->assertSessionHasNoErrors();
        }

        $this->actingAs($this->manager->user)->post(route('units.attendance-points.store', $this->unit), [
            'name' => 'Portaria', 'latitude' => self::LAT, 'longitude' => self::LNG, 'radius_m' => 100,
        ])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function attend(string $kind = 'check_in', array $data = [], ?Membership $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->anaAccess)->user)->post(route('shifts.attendance', $this->shift), [
            'kind' => $kind,
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'accuracy' => 20,
            'measured_at' => now()->getTimestampMs(),
            'idempotency_key' => (string) Str::uuid(),
            ...$data,
        ]);
    }

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    public function test_without_points_and_rules_nothing_is_confirmed_but_the_attempt_is_kept(): void
    {
        $this->at('2026-11-12 09:50');
        $this->attend()->assertSessionHasErrors(['attendance' => 'A presença ainda não está configurada nesta unidade (pontos, precisão e janelas).']);

        $event = AttendanceEvent::withoutTenantScope()->sole();
        $this->assertSame('rejected', $event->result);
        $this->assertFalse($this->shift->fresh()?->hasCheckIn());
    }

    public function test_entry_inside_the_radius_is_recorded_once_with_the_versions_used(): void
    {
        $this->configure();
        $this->at('2026-11-12 09:50');
        $key = (string) Str::uuid();

        $this->attend('check_in', ['idempotency_key' => $key])->assertSessionHasNoErrors()->assertRedirect(route('shifts.mine'));
        $event = AttendanceEvent::withoutTenantScope()->sole();
        $this->assertTrue($event->accepted());
        $this->assertSame(AttendancePoint::withoutTenantScope()->value('id'), $event->point_id);
        $this->assertSame(['attendance_max_accuracy', 'attendance_check_in_lead', 'attendance_check_out_grace'], array_keys($event->policy_version_ids ?? []));
        $this->assertNotNull($event->measured_at);

        // Same attempt again: same result, no duplicate. A new attempt is refused.
        $this->attend('check_in', ['idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->attend()->assertSessionHasErrors(['attendance' => 'A entrada já foi registrada.']);
        $this->assertSame(1, AttendanceEvent::withoutTenantScope()->count());

        $this->get(route('shifts.mine'))->assertInertia(fn (Assert $page) => $page->where('shifts.0.checkIn', '06:50')->where('shifts.0.checkOut', null));
    }

    public function test_radius_border_counts_the_accuracy(): void
    {
        $this->configure();
        $this->at('2026-11-12 09:50');
        // About 79.9 m north of the point.
        $north = self::LAT + 0.000719;

        $this->attend('check_in', ['latitude' => $north, 'accuracy' => 20.2])->assertSessionHasErrors(['attendance' => 'Você está fora do raio permitido pelo hospital.']);
        $this->attend('check_in', ['latitude' => $north, 'accuracy' => 20])->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 'accepted'], AttendanceEvent::withoutTenantScope()->orderBy('id')->pluck('result')->all());
    }

    public function test_low_accuracy_far_away_and_stale_readings_are_refused(): void
    {
        $this->configure();
        $this->at('2026-11-12 09:50');

        $this->attend('check_in', ['accuracy' => 51])->assertSessionHasErrors(['attendance' => 'A precisão da localização está acima do limite da unidade. Tente em área aberta.']);
        $this->attend('check_in', ['latitude' => self::LAT + 0.01])->assertSessionHasErrors(['attendance' => 'Você está fora do raio permitido pelo hospital.']);
        $this->attend('check_in', ['measured_at' => now()->subMinutes(5)->getTimestampMs()])->assertSessionHasErrors(['attendance' => 'A medição de localização é antiga ou tem horário inválido. Tente de novo.']);
        $this->attend('check_in', ['latitude' => null])->assertSessionHasErrors(['latitude' => 'Não foi possível obter a localização do aparelho.']);

        $this->assertSame(['low_accuracy', 'outside_radius', 'stale_measurement'], AttendanceEvent::withoutTenantScope()->orderBy('id')->pluck('reason')->all());
        $this->assertFalse($this->shift->fresh()?->hasCheckIn());
    }

    public function test_windows_and_sequence(): void
    {
        $this->configure();

        $this->at('2026-11-12 09:29');
        $this->attend()->assertSessionHasErrors(['attendance' => 'Fora da janela de horário permitida para este registro.']);
        $this->attend('check_out')->assertSessionHasErrors(['attendance' => 'Registre a entrada antes da saída.']);

        $this->at('2026-11-12 09:31');
        $this->attend()->assertSessionHasNoErrors();

        $this->at('2026-11-12 23:01');
        $this->attend('check_out')->assertSessionHasErrors(['attendance' => 'Fora da janela de horário permitida para este registro.']);
        $this->at('2026-11-12 22:59');
        $this->attend('check_out')->assertSessionHasNoErrors();
        $this->attend('check_out')->assertSessionHasErrors(['attendance' => 'A saída já foi registrada.']);
        $this->assertSame(2, AttendanceEvent::withoutTenantScope()->where('result', 'accepted')->count());
    }

    public function test_only_the_responsible_registers_and_other_clients_do_not_find_the_shift(): void
    {
        $this->configure();
        $this->at('2026-11-12 09:50');

        $this->attend('check_in', [], $this->brunoAccess)->assertNotFound();
        $this->actingAs($this->coordinator->user)->post(route('shifts.attendance', $this->shift), ['kind' => 'check_in'])->assertForbidden();

        app(CurrentTenant::class)->set(null);
        $outsider = Membership::factory()->create();
        $this->actingAs($outsider->user)->post(route('shifts.attendance', $this->shift), ['kind' => 'check_in'])->assertNotFound();
        $this->assertSame(0, AttendanceEvent::withoutTenantScope()->count());
    }

    public function test_entry_ends_changes_of_responsible_and_blocks_cancellation(): void
    {
        $this->configure();
        $this->at('2026-11-12 09:00');
        $this->actingAs($this->anaAccess->user)->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $this->bruno->id])->assertSessionHasNoErrors();

        $this->at('2026-11-12 09:40');
        $this->attend()->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Expired, ShiftRequest::withoutTenantScope()->sole()->status);

        $this->actingAs($this->anaAccess->user)->post(route('shifts.announce', $this->shift))
            ->assertSessionHasErrors(['shift' => 'A entrada já foi registrada: o plantão não pode ser anunciado.']);
        $this->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $this->bruno->id])
            ->assertSessionHasErrors(['shift' => 'A entrada já foi registrada: o plantão não pode ser transferido.']);
        $this->actingAs($this->coordinator->user)->post(route('shifts.cancel', $this->shift), ['reason' => 'Teste'])
            ->assertSessionHasErrors(['reason' => 'A entrada já foi registrada: o plantão não pode ser cancelado.']);

        $this->get(route('shifts.show', $this->shift))
            ->assertInertia(fn (Assert $page) => $page->has('attendance', 1)->where('attendance.0.point', 'Portaria')->where('attendance.0.distance', 0)->missing('attendance.0.latitude'));
    }

    public function test_points_are_managed_by_the_unit_manager_and_only_deactivated(): void
    {
        $this->actingAs($this->coordinator->user)->post(route('units.attendance-points.store', $this->unit), [
            'name' => 'Portaria', 'latitude' => self::LAT, 'longitude' => self::LNG, 'radius_m' => 100,
        ])->assertForbidden();

        $this->configure();
        $point = AttendancePoint::withoutTenantScope()->sole();
        $this->actingAs($this->manager->user)->get(route('units.show', $this->unit))
            ->assertInertia(fn (Assert $page) => $page->has('attendancePoints', 1)->where('attendancePoints.0.radius_m', 100));
        $this->actingAs($this->admin->user)->post(route('units.attendance-points.store', $this->unit), ['name' => 'X', 'latitude' => 91, 'longitude' => 0, 'radius_m' => 0])
            ->assertSessionHasErrors(['latitude', 'radius_m']);

        $this->actingAs($this->manager->user)->post(route('units.attendance-points.deactivate', [$this->unit, $point]))->assertSessionHasNoErrors();
        $this->at('2026-11-12 09:50');
        $this->attend()->assertSessionHasErrors(['attendance' => 'A presença ainda não está configurada nesta unidade (pontos, precisão e janelas).']);

        $this->expectException(QueryException::class);
        DB::table('attendance_points')->where('id', $point->id)->update(['radius_m' => 5000]);
    }

    public function test_replays_absurd_clocks_and_other_units_points(): void
    {
        $this->configure();
        $this->at('2026-11-12 09:50');
        $key = (string) Str::uuid();

        $this->attend('check_in', ['idempotency_key' => $key, 'accuracy' => 80])->assertSessionHasErrors('attendance');
        $this->attend('check_in', ['idempotency_key' => $key, 'accuracy' => 80])->assertSessionHasErrors('attendance');
        $this->attend('check_out', ['idempotency_key' => $key])->assertSessionHasErrors(['attendance' => 'Tentativa repetida com outro conteúdo. Recarregue a página.']);
        $this->assertSame(1, AttendanceEvent::withoutTenantScope()->count());

        $this->attend('check_in', ['measured_at' => PHP_INT_MAX])->assertSessionHasErrors('measured_at');
        $this->assertSame(1, AttendanceEvent::withoutTenantScope()->count());

        $other = Unit::factory()->for($this->unit->tenant)->create();
        $point = AttendancePoint::withoutTenantScope()->sole();
        $this->actingAs($this->admin->user)->post(route('units.attendance-points.deactivate', [$other, $point]))->assertNotFound();
        $this->actingAs($this->manager->user)->post(route('units.attendance-points.deactivate', [$this->unit, $point]))->assertSessionHasNoErrors();
        $this->post(route('units.attendance-points.deactivate', [$this->unit, $point]))->assertNotFound();
    }

    public function test_entry_clears_an_announcement_and_expires_a_swap_where_it_is_the_counterpart(): void
    {
        $this->configure();
        $this->at('2026-11-12 09:00');
        $this->actingAs($this->anaAccess->user)->post(route('shifts.announce', $this->shift))->assertSessionHasNoErrors();
        $this->actingAs($this->anaAccess->user)->post(route('shifts.cancel-announcement', $this->shift));

        $this->actingAs($this->coordinator->user)->post(route('units.shifts.store', $this->unit), [
            'sector_id' => $this->icu->id, 'job_function_id' => $this->doctor->id, 'professional_id' => $this->bruno->id,
            'starts_at' => '2026-11-14T07:00', 'ends_at' => '2026-11-14T19:00',
        ]);
        $brunoShift = Shift::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('shifts.publish', $brunoShift));
        $this->actingAs($this->brunoAccess->user)->post(route('shifts.swap.store', $brunoShift), ['counterpart_shift_id' => $this->shift->id])->assertSessionHasNoErrors();

        $this->at('2026-11-12 09:40');
        $this->attend()->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Expired, ShiftRequest::withoutTenantScope()->sole()->status);
    }

    public function test_attendance_events_are_append_only(): void
    {
        $this->configure();
        $this->at('2026-11-12 09:50');
        $this->attend()->assertSessionHasNoErrors();

        $this->expectException(QueryException::class);
        DB::table('attendance_events')->update(['result' => 'rejected']);
    }
}
