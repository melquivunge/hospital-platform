<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\StockInventory;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 4c.4a (ADR-0007): the daily stock alert (once per day of the unit, from 7 h) to the unit's pharmacy and
 * managers, the inventory waiting for the manager, and the manager's read-only view of the stock.
 */
class StockAlertTest extends TestCase
{
    use RefreshDatabase;

    private Membership $pharmacist;

    private Membership $manager;

    private Membership $elsewhere;

    private Unit $unit;

    private Medication $medication;

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
        $this->elsewhere = Membership::factory()->for($tenant)->create();
        $this->elsewhere->grant(Role::Pharmacy, Unit::factory()->for($tenant)->create(['timezone' => 'America/Sao_Paulo']));
        app(CurrentTenant::class)->set($tenant);
        $this->medication = Medication::query()->create(['active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'stock_unit' => 'comprimido']);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * @return array<int, list<string>> user id => titles
     */
    private function inbox(): array
    {
        (new DeliverNotifications)->handle();

        return DB::table('user_notifications')->orderBy('id')->get()->groupBy('user_id')->map(fn ($rows) => $rows->pluck('title')->all())->all();
    }

    public function test_the_daily_alert_goes_once_a_day_of_the_unit_to_its_pharmacy_and_managers(): void
    {
        $this->stockLot($this->unit->id, $this->medication, 10, 'PERTO', '2026-12-01');
        $this->stockLot($this->unit->id, $this->medication, 4, 'VENCIDO', '2026-11-01');
        $this->stockLot($this->unit->id, $this->medication, 20, 'LONGE', '2027-06-30');
        // 30 valid against a minimum of 40 (the expired lot does not count, on the screen either).
        $this->actingAs($this->pharmacist->user)->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => 40])->assertSessionHasNoErrors();

        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('medications.0.total', 34)->where('medications.0.valid', 30)->where('medications.0.below', true));

        // Before 7 h in the unit, nothing.
        $this->travelTo('2026-11-13 09:30:00');
        $this->artisan('stock:alerts')->assertSuccessful();
        $this->assertSame(0, AuditEvent::where('action', 'stock.daily_alert')->count());

        $this->travelTo('2026-11-13 12:00:00');
        $this->artisan('stock:alerts')->expectsOutput('Avisos enviados: 1.')->assertSuccessful();
        $this->artisan('stock:alerts')->expectsOutput('Avisos enviados: 0.')->assertSuccessful();
        $event = AuditEvent::where('action', 'stock.daily_alert')->sole();
        $this->assertEquals(['unit_id' => $this->unit->id, 'date' => '2026-11-13', 'expiring' => 1, 'expired' => 1, 'below_minimum' => 1], $event->context);
        $title = 'Estoque: 1 lote(s) vencido(s) com saldo, 1 lote(s) vencem em 30 dias, 1 medicamento(s) abaixo do mínimo';
        $this->assertSame([$this->pharmacist->user_id => [$title], $this->manager->user_id => [$title]], $this->inbox());
        $this->assertSame("/stock?unit={$this->unit->id}", DB::table('user_notifications')->value('url'));

        // The next day of the unit, again.
        $this->travelTo('2026-11-14 12:00:00');
        $this->artisan('stock:alerts')->expectsOutput('Avisos enviados: 1.');
        $this->assertSame(2, AuditEvent::where('action', 'stock.daily_alert')->count());
    }

    public function test_each_network_hears_only_of_its_own_stock_and_revoked_access_hears_nothing(): void
    {
        $this->stockLot($this->unit->id, $this->medication, 4, 'VENCIDO', '2026-11-01');
        // Another network: a unit without a time zone (UTC) and nothing to warn, and one with an expired lot.
        $other = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $quiet = Unit::factory()->for($other->tenant)->create(['timezone' => null]);
        $theirs = Unit::factory()->for($other->tenant)->create(['timezone' => 'America/Manaus']);
        $theirPharmacist = Membership::factory()->for($other->tenant)->create();
        $theirPharmacist->grant(Role::Pharmacy, $theirs);
        $theirPharmacist->grant(Role::Pharmacy, $quiet);
        app(CurrentTenant::class)->set($other->tenant);
        $theirMedication = Medication::query()->create(['active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'stock_unit' => 'comprimido']);
        app(CurrentTenant::class)->set(null);
        $this->stockLot($theirs->id, $theirMedication, 2, 'DELES', '2026-11-01');
        $this->stockLot($quiet->id, $theirMedication, 2, 'LONGE', '2027-06-30');
        $this->manager->revoke();

        $this->artisan('stock:alerts')->expectsOutput('Avisos enviados: 2.');
        $title = 'Estoque: 1 lote(s) vencido(s) com saldo';
        $this->assertSame([$this->pharmacist->user_id => [$title], $theirPharmacist->user_id => [$title]], $this->inbox());
        $this->assertSame([$this->unit->id, $theirs->id], DB::table('stock_alert_days')->orderBy('id')->pluck('unit_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_controlled_discard_reaches_the_unit_managers_or_the_network_admins(): void
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        $morphine = Medication::query()->create(['active_ingredient' => 'Morfina', 'form' => 'Ampola', 'strength' => '10 mg/mL', 'dose_unit' => 'mg', 'routes' => ['iv'], 'stock_unit' => 'ampola', 'controlled' => true]);
        app(CurrentTenant::class)->set(null);
        $controlled = $this->stockLot($this->unit->id, $morphine, 10, 'M1', '2027-06-30')->id;
        $common = $this->stockLot($this->unit->id, $this->medication, 10, 'D1', '2027-06-30')->id;
        $this->actingAs($this->pharmacist->user)->post(route('stock.discards.store', $common), ['reason' => 'loss', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(route('stock.discards.store', $controlled), ['reason' => 'damage', 'quantity' => 2])->assertSessionHasNoErrors();
        $title = 'Descarte de controlado: 2 ampola de Morfina 10 mg/mL (Ampola), avaria';
        $this->assertSame([$this->manager->user_id => [$title]], $this->inbox());
        $this->assertSame("/stock/movements?unit={$this->unit->id}&controlled=1", DB::table('user_notifications')->value('url'));

        // A unit without a manager: the network admins hear of it.
        $other = (int) $this->elsewhere->roleAssignments()->value('unit_id');
        $lot = $this->stockLot($other, $morphine, 5, 'M2', '2027-06-30')->id;
        $this->actingAs($this->elsewhere->user)->post(route('stock.discards.store', $lot), ['reason' => 'other', 'quantity' => 1, 'note' => 'Frasco aberto.'])->assertSessionHasNoErrors();
        $admin = (int) Membership::query()->where('tenant_id', $this->unit->tenant_id)->whereHas('roleAssignments', fn ($query) => $query->where('role', Role::NetworkAdmin->value))->value('user_id');
        $this->assertSame([$this->manager->user_id => [$title], $admin => ['Descarte de controlado: 1 ampola de Morfina 10 mg/mL (Ampola), outro motivo']], $this->inbox());
        $this->assertSame("/stock/indicators?unit={$other}", DB::table('user_notifications')->where('user_id', $admin)->value('url'));
    }

    public function test_nothing_to_warn_sends_nothing(): void
    {
        $this->stockLot($this->unit->id, $this->medication, 50, 'LONGE', '2027-06-30');
        $this->actingAs($this->pharmacist->user)->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => 40])->assertSessionHasNoErrors();
        $this->artisan('stock:alerts')->expectsOutput('Avisos enviados: 0.')->assertSuccessful();
        $this->assertSame(0, DB::table('stock_alert_days')->count());
    }

    public function test_the_manager_hears_of_the_inventory_and_sees_the_stock_read_only(): void
    {
        $lot = $this->stockLot($this->unit->id, $this->medication, 50, 'LONGE', '2027-06-30')->id;
        $this->actingAs($this->pharmacist->user)->post(route('stock.inventories.store'), ['unit_id' => $this->unit->id])->assertSessionHasNoErrors();
        $inventory = StockInventory::withoutTenantScope()->sole();
        $this->put(route('stock.inventories.count', [$inventory, $inventory->lines()->sole()]), ['counted' => 50])->assertSessionHasNoErrors();
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();
        $this->assertSame([$this->manager->user_id => ['Inventário de estoque aguardando aprovação']], $this->inbox());
        $this->assertSame("/stock/inventories/{$inventory->id}", DB::table('user_notifications')->value('url'));

        $this->actingAs($this->manager->user)->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('canMove', false)->where('medications.0.total', 50));
        $this->post(route('stock.entries.store'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'lot_code' => 'X', 'expires_on' => '2027-06-30', 'quantity' => 1])->assertSessionHasErrorsIn('entry', ['unit_id']);
        $this->post(route('stock.discards.store', $lot), ['reason' => 'loss', 'quantity' => 1])->assertForbidden();
        $this->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => 5])->assertSessionHasErrorsIn('minimum', ['unit_id']);
        $this->actingAs($this->pharmacist->user)->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('canMove', true));
        $this->actingAs($this->elsewhere->user)->get(route('stock.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page->whereNot('unitId', $this->unit->id));
    }
}
