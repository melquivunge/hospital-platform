<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 4c.4b (ADR-0007): the stock movements report by period and medication, controlled first, and its CSV,
 * for the unit's pharmacy and managers, with no patient data.
 */
class StockReportTest extends TestCase
{
    use RefreshDatabase;

    private Membership $pharmacist;

    private Membership $manager;

    private Unit $unit;

    private Medication $dipyrone;

    private Medication $morphine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 12:00:00');
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['timezone' => 'America/Sao_Paulo']);
        $this->pharmacist = Membership::factory()->for($tenant)->create();
        $this->pharmacist->grant(Role::Pharmacy, $this->unit);
        $this->manager = Membership::factory()->for($tenant)->create();
        $this->manager->grant(Role::UnitManager, $this->unit);
        app(CurrentTenant::class)->set($tenant);
        $this->dipyrone = Medication::query()->create(['active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'stock_unit' => 'comprimido']);
        $this->morphine = Medication::query()->create(['active_ingredient' => 'Morfina', 'form' => 'Ampola', 'strength' => '10 mg/mL', 'dose_unit' => 'mg', 'routes' => ['intravenous'], 'stock_unit' => 'ampola', 'controlled' => true]);
        app(CurrentTenant::class)->set(null);

        // Two months ago: out of the default period.
        $this->travelTo('2026-09-10 12:00:00');
        $this->stockLot($this->unit->id, $this->dipyrone, 100, 'ANTIGO', '2027-06-30');
        $this->travelTo('2026-11-13 12:00:00');
        $dipyrone = $this->stockLot($this->unit->id, $this->dipyrone, 50, 'D1', '2027-06-30')->id;
        $morphine = $this->stockLot($this->unit->id, $this->morphine, 10, 'M1', '2027-06-30')->id;
        $this->actingAs($this->pharmacist->user)->post(route('stock.discards.store', $morphine), ['reason' => 'damage', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post(route('stock.discards.store', $dipyrone), ['reason' => 'other', 'quantity' => 3, 'note' => '=SOMA(1;2)'])->assertSessionHasNoErrors();
    }

    public function test_the_report_shows_the_period_controlled_first_with_no_patient_data(): void
    {
        $this->actingAs($this->manager->user)->get(route('stock.movements.index'))->assertInertia(fn (Assert $page) => $page->component('stock/movements')
            ->where('filters.from', '2026-10-15')->where('filters.to', '2026-11-13')
            ->has('summary', 2)
            ->where('summary.0.name', 'Morfina 10 mg/mL (Ampola)')->where('summary.0.controlled', true)->where('summary.0.entry', 10)->where('summary.0.discard', -2)
            ->where('summary.1.entry', 50)->where('summary.1.discard', -3)
            ->where('pages.total', 4)
            ->where('movements.0.type', 'Descarte')->where('movements.0.reason', 'Outro: =SOMA(1;2)')->where('movements.0.out', 3)->where('movements.0.in', '')
            ->where('movements.1.reason', 'Avaria')
            ->where('movements.0', fn ($row) => array_keys($row->all()) === ['id', 'at', 'medication', 'controlled', 'lot', 'expiresOn', 'type', 'in', 'out', 'reason', 'user']));

        // Filters: one medication, the controlled ones, and an older period.
        $this->get(route('stock.movements.index', ['medication' => $this->dipyrone->id]))->assertInertia(fn (Assert $page) => $page->has('summary', 1)->where('pages.total', 2));
        $this->get(route('stock.movements.index', ['controlled' => 1]))->assertInertia(fn (Assert $page) => $page->has('summary', 1)->where('summary.0.controlled', true));
        $this->get(route('stock.movements.index', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertInertia(fn (Assert $page) => $page->where('pages.total', 1)->where('movements.0.lot', 'ANTIGO'));
        // Over a year, or backwards: the last 30 days.
        $this->get(route('stock.movements.index', ['from' => '2025-01-01', 'to' => '2026-11-13']))->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-10-15'));
    }

    public function test_the_period_is_the_days_of_the_unit(): void
    {
        // 23:30 on 11-13 and 00:30 on 11-14 in São Paulo (UTC−3).
        $this->travelTo('2026-11-14 02:30:00');
        $late = $this->stockLot($this->unit->id, $this->dipyrone, 1, 'TARDE', '2027-06-30');
        $this->travelTo('2026-11-14 03:30:00');
        $this->stockLot($this->unit->id, $this->dipyrone, 1, 'CEDO', '2027-06-30');
        $this->actingAs($this->pharmacist->user)->get(route('stock.movements.index', ['from' => '2026-11-13', 'to' => '2026-11-13']))
            ->assertInertia(fn (Assert $page) => $page->where('pages.total', 5)->where('movements.0.lot', $late->lot_code)->where('movements.0.at', '13/11/2026 23:30'));
        $this->get(route('stock.movements.index', ['from' => '2026-11-14', 'to' => '2026-11-14']))
            ->assertInertia(fn (Assert $page) => $page->where('pages.total', 1)->where('movements.0.lot', 'CEDO'));
    }

    public function test_the_csv_has_the_same_rows_with_formulas_neutralized_and_is_audited(): void
    {
        $response = $this->actingAs($this->pharmacist->user)->get(route('stock.movements.csv', ['from' => '2026-11-01', 'to' => '2026-11-13']));
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $rows = array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), explode("\n", trim(substr($response->streamedContent(), 3))));
        $this->assertSame(['Data e hora', 'Medicamento', 'Controlado', 'Lote', 'Validade', 'Tipo', 'Entrada', 'Saída', 'Motivo', 'Responsável'], $rows[0]);
        $this->assertCount(5, $rows);
        $this->assertSame(['Morfina 10 mg/mL (Ampola)', 'sim', 'M1', '30/06/2027', 'Entrada', '10', '', ''], array_slice($rows[2], 1, 8));
        // Quantities as positive numbers in two columns: a spreadsheet keeps them numbers.
        $this->assertSame(['Dipirona 500 mg (Comprimido)', 'não', 'D1', '30/06/2027', 'Descarte', '', '3', 'Outro: =SOMA(1;2)'], array_slice($rows[4], 1, 8));
        $this->assertEquals(['unit_id' => $this->unit->id, 'from' => '2026-11-01', 'to' => '2026-11-13', 'medication_id' => null, 'controlled' => false, 'rows' => 4],
            AuditEvent::where('action', 'stock.movements_exported')->sole()->context);
    }

    public function test_a_text_cell_starting_like_a_formula_is_neutralized_in_the_csv(): void
    {
        $lot = $this->stockLot($this->unit->id, $this->dipyrone, 5, 'D2', '2027-06-30')->id;
        $this->actingAs($this->pharmacist->user)->post(route('stock.discards.store', $lot), ['reason' => 'other', 'quantity' => 1, 'note' => 'x'])->assertSessionHasNoErrors();
        // The reason cell starts with the label, so a note never begins a cell; the user name might.
        $this->pharmacist->user->forceFill(['name' => '=HYPERLINK("x")'])->save();
        $this->post(route('stock.discards.store', $lot), ['reason' => 'loss', 'quantity' => 1])->assertSessionHasNoErrors();
        $content = $this->get(route('stock.movements.csv'))->streamedContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""x"")"', $content);
        $this->assertStringNotContainsString(';=HYPERLINK', $content);
    }

    public function test_only_the_units_pharmacy_and_managers_see_it(): void
    {
        $this->actingAs(Membership::factory()->for($this->pharmacist->tenant)->create()->user)->get(route('stock.movements.index'))->assertForbidden();
        $this->get(route('stock.movements.csv'))->assertForbidden();
        $elsewhere = Membership::factory()->for($this->pharmacist->tenant)->create();
        $other = Unit::factory()->for($this->pharmacist->tenant)->create();
        $elsewhere->grant(Role::Pharmacy, $other);
        $this->actingAs($elsewhere->user)->get(route('stock.movements.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page->where('unitId', $other->id)->where('pages.total', 0));
    }
}
