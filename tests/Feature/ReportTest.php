<?php

namespace Tests\Feature;

use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Sector;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $n = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 12:00:00');
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $admin->tenant;
        $unit = Unit::factory()->for($tenant)->create(['name' => 'Hospital A', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $other = Unit::factory()->for($tenant)->create(['name' => 'Hospital B', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $coordinator = Membership::factory()->for($tenant)->create();
        $coordinator->grant(Role::ShiftCoordinator, $unit);
        app(CurrentTenant::class)->set($tenant);
        $ana = Professional::factory()->create(['base_unit_id' => $unit->id, 'name' => 'Ana']);
        $formula = Professional::factory()->create(['base_unit_id' => $unit->id, 'name' => '=HYPERLINK("http://x")']);
        app(CurrentTenant::class)->set(null);

        $this->n = ['admin' => $admin, 'coordinator' => $coordinator, 'unit' => $unit, 'other' => $other, 'ana' => $ana, 'formula' => $formula,
            'sector' => Sector::factory()->for($unit)->create(), 'otherSector' => Sector::factory()->for($other)->create(),
            'function' => JobFunction::factory()->for($tenant)->create()];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(string $start, array $attributes = []): int
    {
        $user = $this->n['admin']->user_id;
        $status = $attributes['status'] ?? 'published';

        return DB::table('shifts')->insertGetId([
            'tenant_id' => $this->n['unit']->tenant_id, 'unit_id' => $this->n['unit']->id, 'sector_id' => $this->n['sector']->id,
            'job_function_id' => $this->n['function']->id, 'starts_at' => $start, 'ends_at' => date('Y-m-d H:i:sP', strtotime($start) + 12 * 3600),
            'status' => $status, 'created_by_user_id' => $user,
            'published_at' => $status === 'draft' ? null : $start, 'published_by_user_id' => $status === 'draft' ? null : $user,
            'cancelled_at' => $status === 'cancelled' ? $start : null, 'cancelled_by_user_id' => $status === 'cancelled' ? $user : null,
            'cancel_reason' => $status === 'cancelled' ? 'Teste' : null, 'created_at' => now(), 'updated_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<list<string|null>>
     */
    private function csv(string $route, array $query): array
    {
        $body = $this->get(route($route, ['format' => 'csv', ...$query]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}", $body);

        return array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", substr($body, 3)))));
    }

    public function test_figures_add_up_and_the_exports_list_exactly_the_counted_shifts(): void
    {
        $filled = $this->shift('2026-11-10 10:00:00+00', ['professional_id' => $this->n['ana']->id]);
        $this->shift('2026-11-11 10:00:00+00', ['professional_id' => $this->n['formula']->id, 'announced_at' => '2026-11-01 10:00:00+00']);
        $this->shift('2026-11-12 10:00:00+00');
        $this->shift('2026-11-25 10:00:00+00', ['status' => 'draft', 'professional_id' => $this->n['ana']->id]);
        $this->shift('2026-11-13 10:00:00+00', ['status' => 'cancelled']);
        // Outside the period (local time: 31/10 21:00) and in a unit the coordinator does not read.
        $this->shift('2026-11-01 00:00:00+00', ['professional_id' => $this->n['ana']->id]);
        $this->shift('2026-11-14 10:00:00+00', ['unit_id' => $this->n['other']->id, 'sector_id' => $this->n['otherSector']->id]);

        $point = DB::table('attendance_points')->insertGetId(['tenant_id' => $this->n['unit']->tenant_id, 'unit_id' => $this->n['unit']->id, 'name' => 'Portaria',
            'latitude' => -23.561234, 'longitude' => -46.655678, 'radius_m' => 100, 'created_by_user_id' => $this->n['admin']->user_id, 'created_at' => now()]);
        DB::table('attendance_events')->insert(['tenant_id' => $this->n['unit']->tenant_id, 'shift_id' => $filled, 'professional_id' => $this->n['ana']->id,
            'user_id' => $this->n['admin']->user_id, 'kind' => 'check_in', 'result' => 'accepted', 'latitude' => -23.561299, 'longitude' => -46.655611,
            'accuracy_m' => 12, 'received_at' => '2026-11-10 09:55:00+00', 'point_id' => $point, 'distance_m' => 9, 'idempotency_key' => '00000000-0000-0000-0000-000000000001']);

        $query = ['unit' => $this->n['unit']->id, 'from' => '2026-11-01', 'to' => '2026-11-30'];
        $this->actingAs($this->n['coordinator']->user)->get(route('reports.index', $query))
            ->assertInertia(fn (Assert $page) => $page->component('reports/index')
                ->where('coverage', ['drafts' => 1, 'cancelled' => 1, 'published' => 3, 'filled' => 1, 'announced' => 1, 'open' => 1])
                ->where('attendance.started', 2)->where('attendance.checkedIn', 1)->where('attendance.noEntry', 1)
                ->has('hours', 2)->where('hours.0.hours', 12));

        $schedule = $this->csv('reports.schedule', $query);
        $this->assertSame(['Data', 'Início', 'Fim', 'Setor', 'Função', 'Profissional', 'Situação', 'Tipo'], $schedule[0]);
        $this->assertCount(1 + 5, $schedule);
        $this->assertSame(['10/11/2026', '07:00', '19:00'], array_slice($schedule[1], 0, 3));
        $this->assertSame("'=HYPERLINK(\"http://x\")", $schedule[2][5]);
        $this->assertSame('Anunciado para substituição', $schedule[2][7]);

        $presence = $this->csv('reports.attendance', $query);
        $this->assertCount(1 + 2, $presence);
        $this->assertSame('10/11/2026 06:55', $presence[1][6]);
        $this->assertStringNotContainsString('23.56', implode(';', array_merge(...$presence)));
        $this->assertSame(2, DB::table('audit_events')->where('action', 'report.exported')->count());
    }

    public function test_only_schedule_readers_see_reports_and_only_their_units(): void
    {
        $this->shift('2026-11-14 10:00:00+00', ['unit_id' => $this->n['other']->id, 'sector_id' => $this->n['otherSector']->id]);
        $query = ['unit' => $this->n['other']->id, 'from' => '2026-11-01', 'to' => '2026-11-30'];

        // A coordinator asking for another unit gets their own unit back, never the other one's figures.
        $this->actingAs($this->n['coordinator']->user)->get(route('reports.index', $query))
            ->assertInertia(fn (Assert $page) => $page->where('unit.id', $this->n['unit']->id)->has('units', 1)->where('coverage.published', 0));
        $this->assertCount(1, $this->csv('reports.schedule', $query));

        $this->actingAs($this->n['admin']->user)->get(route('reports.index', $query))
            ->assertInertia(fn (Assert $page) => $page->where('unit.id', $this->n['other']->id)->where('coverage.published', 1));

        $professional = Membership::factory()->for($this->n['unit']->tenant)->create();
        $professional->grant(Role::Professional, $this->n['unit']);
        $this->actingAs($professional->user)->get(route('reports.index'))->assertForbidden();
        $this->get(route('reports.schedule', 'pdf'))->assertForbidden();
    }

    public function test_requests_count_once_for_every_unit_that_approves_them_and_periods_follow_the_unit_clock(): void
    {
        $mine = $this->shift('2026-11-10 10:00:00+00', ['professional_id' => $this->n['ana']->id]);
        $alsoMine = $this->shift('2026-11-11 10:00:00+00', ['professional_id' => $this->n['formula']->id]);
        $elsewhere = $this->shift('2026-11-12 10:00:00+00', ['professional_id' => $this->n['formula']->id, 'unit_id' => $this->n['other']->id, 'sector_id' => $this->n['otherSector']->id]);
        // 30/11 23:30 local counts; 01/12 00:00 local does not.
        $this->shift('2026-12-01 02:30:00+00');
        $this->shift('2026-12-01 03:00:00+00');
        $request = fn (string $type, int $shift, ?int $counterpart, string $status) => DB::table('shift_requests')->insert([
            'tenant_id' => $this->n['unit']->tenant_id, 'type' => $type, 'shift_id' => $shift, 'counterpart_shift_id' => $counterpart,
            'requester_professional_id' => $this->n['ana']->id, 'recipient_professional_id' => $this->n['formula']->id, 'status' => $status,
            'requested_by_user_id' => $this->n['admin']->user_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $request('swap', $mine, $alsoMine, 'completed');
        $request('swap', $elsewhere, $mine, 'refused');
        $request('transfer', $alsoMine, null, 'expired');

        $this->actingAs($this->n['admin']->user)->get(route('reports.index', ['unit' => $this->n['unit']->id, 'from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertInertia(fn (Assert $page) => $page->where('coverage.published', 3)
                ->where('requests', ['pending' => 0, 'completed' => 1, 'refused' => 1, 'cancelled' => 0, 'expired' => 1]));
        $this->get(route('reports.index', ['unit' => $this->n['other']->id, 'from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertInertia(fn (Assert $page) => $page->where('requests.refused', 1)->where('requests.completed', 0));
    }

    public function test_the_pdf_carries_the_same_rows_and_is_audited(): void
    {
        $this->shift('2026-11-10 10:00:00+00', ['professional_id' => $this->n['ana']->id]);
        $this->shift('2026-11-12 10:00:00+00');
        $query = ['format' => 'pdf', 'unit' => $this->n['unit']->id, 'from' => '2026-11-01', 'to' => '2026-11-30'];

        $response = $this->actingAs($this->n['coordinator']->user)->get(route('reports.schedule', $query))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'attachment; filename="escala-2026-11-01-a-2026-11-30.pdf"');
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertEquals(['report' => 'escala', 'format' => 'pdf', 'from' => '2026-11-01', 'to' => '2026-11-30', 'rows' => 2],
            json_decode((string) DB::table('audit_events')->where('action', 'report.exported')->value('context'), true));

        $this->get(route('reports.attendance', $query))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/reports/schedule.xlsx')->assertNotFound();
    }

    public function test_an_invalid_or_too_long_period_falls_back_to_the_current_month(): void
    {
        $this->actingAs($this->n['admin']->user);
        foreach ([['from' => '2026-01-01', 'to' => '2026-12-31'], ['from' => 'x', 'to' => '2026-11-02'], ['from' => '2026-11-10', 'to' => '2026-11-01']] as $period) {
            $this->get(route('reports.index', ['unit' => $this->n['unit']->id, ...$period]))
                ->assertInertia(fn (Assert $page) => $page->where('from', '2026-11-01')->where('to', '2026-11-30'));
        }
    }
}
