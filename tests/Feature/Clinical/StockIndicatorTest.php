<?php

namespace Tests\Feature\Clinical;

use App\Models\Medication;
use App\Models\Membership;
use App\Models\StockInventory;
use App\Models\StockLot;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 4c.4c (ADR-0007): the stock indicators per unit and in detail; the network admin sees every unit.
 */
class StockIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Membership $pharmacist;

    private Membership $manager;

    private Membership $otherManager;

    private Unit $unit;

    private Unit $other;

    private Medication $medication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 12:00:00');
        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $this->admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['name' => 'Hospital A', 'timezone' => 'America/Sao_Paulo']);
        $this->other = Unit::factory()->for($tenant)->create(['name' => 'Hospital B', 'timezone' => 'America/Sao_Paulo']);
        $this->pharmacist = Membership::factory()->for($tenant)->create();
        $this->pharmacist->grant(Role::Pharmacy, $this->unit);
        $this->manager = Membership::factory()->for($tenant)->create();
        $this->manager->grant(Role::UnitManager, $this->unit);
        $this->otherManager = Membership::factory()->for($tenant)->create();
        $this->otherManager->grant(Role::UnitManager, $this->other);
        app(CurrentTenant::class)->set($tenant);
        $this->medication = Medication::query()->create(['active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'stock_unit' => 'comprimido']);
        app(CurrentTenant::class)->set(null);
    }

    public function test_the_figures_of_each_unit_and_the_detail_of_the_chosen_one(): void
    {
        // An old loss, out of the default period of 30 days.
        $this->travelTo('2026-09-10 12:00:00');
        $old = $this->stockLot($this->unit->id, $this->medication, 5, 'ANTIGO', '2027-06-30')->id;
        $this->actingAs($this->pharmacist->user)->post(route('stock.discards.store', $old), ['reason' => 'loss', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->travelTo('2026-11-13 12:00:00');
        $soon = $this->stockLot($this->unit->id, $this->medication, 10, 'PERTO', '2026-12-01')->id;
        $this->stockLot($this->unit->id, $this->medication, 4, 'VENCIDO', '2026-11-01');
        $this->stockLot($this->other->id, $this->medication, 6, 'B1', '2026-11-20');
        $this->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => 40])->assertSessionHasNoErrors();
        $this->post(route('stock.discards.store', $soon), ['reason' => 'damage', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post(route('stock.discards.store', $soon), ['reason' => 'damage', 'quantity' => 1])->assertSessionHasNoErrors();
        // An inventory that finds one missing from ANTIGO: a loss by inventory difference.
        $this->post(route('stock.inventories.store'), ['unit_id' => $this->unit->id, 'medication_ids' => [$this->medication->id]])->assertSessionHasNoErrors();
        $inventory = StockInventory::withoutTenantScope()->sole();
        foreach ($inventory->lines as $line) {
            $balance = StockLot::withoutTenantScope()->findOrFail($line->stock_lot_id)->balance;
            $this->put(route('stock.inventories.count', [$inventory, $line]), ['counted' => $line->stock_lot_id === $old ? $balance - 1 : $balance])->assertSessionHasNoErrors();
        }
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();
        $this->actingAs($this->manager->user)->post(route('stock.inventories.approve', $inventory))->assertSessionHasNoErrors();

        // Valid: PERTO 7 and ANTIGO 3, against a minimum of 40 (VENCIDO does not count).
        $this->actingAs($this->pharmacist->user)->get(route('stock.indicators.index'))->assertInertia(fn (Assert $page) => $page->component('stock/indicators')
            ->where('stockUnitIds', [$this->unit->id])
            ->has('units', 1)
            ->where('units.0', ['id' => $this->unit->id, 'name' => 'Hospital A', 'below' => 1, 'expiring' => 1, 'expired' => 1, 'losses' => ['damage' => 2, 'inventory' => 1]])
            ->where('detail.below.0.valid', 10)->where('detail.below.0.minimum', 40)
            ->where('detail.expiring.0.lot', 'PERTO')->where('detail.expiring.0.balance', 7)
            ->where('detail.expired.0.lot', 'VENCIDO')
            ->where('detail.losses', [
                ['medication' => 'Dipirona 500 mg (Comprimido)', 'stockUnit' => 'comprimido', 'reason' => 'damage', 'quantity' => 3],
                ['medication' => 'Dipirona 500 mg (Comprimido)', 'stockUnit' => 'comprimido', 'reason' => 'inventory', 'quantity' => 1],
            ]));
        // An older period brings the old loss.
        $this->get(route('stock.indicators.index', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertInertia(fn (Assert $page) => $page->where('units.0.losses', ['loss' => 1]));
    }

    public function test_expiry_edges_other_networks_and_revoked_access(): void
    {
        // Expiring today is still valid; exactly 30 days ahead is expiring; 31 is not.
        $this->stockLot($this->unit->id, $this->medication, 1, 'HOJE', '2026-11-13');
        $this->stockLot($this->unit->id, $this->medication, 1, 'TRINTA', '2026-12-13');
        $this->stockLot($this->unit->id, $this->medication, 1, 'TRINTAUM', '2026-12-14');
        $foreign = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        Unit::factory()->for($foreign->tenant)->create();

        $this->actingAs($this->admin->user)->get(route('stock.indicators.index'))->assertInertia(fn (Assert $page) => $page
            ->has('units', 2)->where('units.0', fn ($unit) => $unit['expiring'] === 2 && $unit['expired'] === 0)
            ->where('detail.expiring', fn ($rows) => collect($rows)->pluck('lot')->all() === ['HOJE', 'TRINTA']));
        $this->pharmacist->revoke();
        $this->actingAs($this->pharmacist->user)->get(route('stock.indicators.index'))->assertForbidden();
    }

    public function test_the_network_admin_sees_every_unit_and_the_others_only_theirs(): void
    {
        $this->stockLot($this->other->id, $this->medication, 6, 'B1', '2026-11-20');
        $this->actingAs($this->admin->user)->get(route('stock.indicators.index', ['unit' => $this->other->id]))->assertInertia(fn (Assert $page) => $page
            // The network admin opens every unit's stock as its manager (user's decision, 27/10/2026).
            ->where('stockUnitIds', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$this->unit->id, $this->other->id])->sort()->values()->all())->has('units', 2)->where('unitId', $this->other->id)
            ->where('units.1.name', 'Hospital B')->where('units.1.expiring', 1)->where('detail.expiring.0.lot', 'B1'));
        $this->actingAs($this->otherManager->user)->get(route('stock.indicators.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page
            ->has('units', 1)->where('unitId', $this->other->id)->where('stockUnitIds', [$this->other->id]));
        $this->actingAs(Membership::factory()->for($this->admin->tenant)->create()->user)->get(route('stock.indicators.index'))->assertForbidden();
    }
}
