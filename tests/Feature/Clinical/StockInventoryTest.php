<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Material;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\StockInventory;
use App\Models\StockInventoryLine;
use App\Models\StockLot;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 4c.3b (ADR-0007): the inventory counted by the pharmacy, lot by lot and blind, and approved by another
 * person, the unit manager; the adjustment uses the balance at the count.
 */
class StockInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Membership $pharmacist;

    /** Pharmacy and unit manager of the same unit. */
    private Membership $both;

    private Membership $manager;

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
        $this->both = Membership::factory()->for($tenant)->create();
        $this->both->grant(Role::Pharmacy, $this->unit);
        $this->both->grant(Role::UnitManager, $this->unit);
        $this->manager = Membership::factory()->for($tenant)->create();
        $this->manager->grant(Role::UnitManager, $this->unit);
        app(CurrentTenant::class)->set($tenant);
        $this->medication = Medication::query()->create(['active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'stock_unit' => 'comprimido']);
        app(CurrentTenant::class)->set(null);
    }

    private function open(): StockInventory
    {
        $this->actingAs($this->pharmacist->user)->post(route('stock.inventories.store'), ['unit_id' => $this->unit->id])->assertSessionHasNoErrors();

        return StockInventory::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function line(StockInventory $inventory, int $lotId): StockInventoryLine
    {
        return StockInventoryLine::withoutTenantScope()->where(['stock_inventory_id' => $inventory->id, 'stock_lot_id' => $lotId])->sole();
    }

    private function countLot(Membership $who, StockInventory $inventory, int $lotId, int $counted): void
    {
        $this->actingAs($who->user)->put(route('stock.inventories.count', [$inventory, $this->line($inventory, $lotId)]), ['counted' => $counted])->assertSessionHasNoErrors();
    }

    /**
     * @return array<string, int>
     */
    private function balances(): array
    {
        return DB::table('stock_lots')->orderBy('lot_code')->pluck('balance', 'lot_code')->map(fn ($balance) => (int) $balance)->all();
    }

    public function test_the_count_adjusts_by_the_balance_at_the_count_and_another_person_approves(): void
    {
        $a = $this->stockLot($this->unit->id, $this->medication, 10, 'A', '2027-06-30')->id;
        $b = $this->stockLot($this->unit->id, $this->medication, 5, 'B')->id;
        $c = $this->stockLot($this->unit->id, $this->medication, 8, 'C')->id;
        $inventory = $this->open();
        $this->assertSame(3, $inventory->lines()->count());
        $this->post(route('stock.inventories.store'), ['unit_id' => $this->unit->id])->assertSessionHasErrorsIn('open', ['unit_id' => 'Já há um inventário em andamento nesta unidade.']);

        // A blind count for the pharmacy.
        $this->get(route('stock.inventories.show', $inventory))->assertInertia(fn (Assert $page) => $page->where('canCount', true)->where('canDecide', false)
            ->where('lines.0.expected', null)->where('lines.0.difference', null));
        $this->countLot($this->pharmacist, $inventory, $a, 8);
        // Two go out of A after its count: the approval does not adjust them again.
        $this->post(route('stock.discards.store', $a), ['reason' => 'damage', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->countLot($this->both, $inventory, $b, 5);
        $this->actingAs($this->pharmacist->user)->post(route('stock.inventories.submit', $inventory))->assertSessionHasErrorsIn('submit', ['lines']);
        $this->countLot($this->pharmacist, $inventory, $c, 9);
        // A lot found without a record, and one already in the list.
        $found = fn (array $data) => $this->post(route('stock.inventories.found', $inventory), ['medication_id' => $this->medication->id, 'expires_on' => '2027-03-31', ...$data]);
        $found(['lot_code' => 'novo 1', 'counted' => 3])->assertSessionHasNoErrors();
        $found(['lot_code' => ' a', 'expires_on' => '2027-06-30', 'counted' => 1])->assertSessionHasErrorsIn('found', ['lot_code' => 'Este lote já está na lista. Corrija a contagem dele.']);
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();
        $this->put(route('stock.inventories.count', [$inventory, $this->line($inventory, $a)]), ['counted' => 1])->assertSessionHasErrorsIn("count{$this->line($inventory, $a)->id}", ['status']);

        // Who counted never approves, even as the unit manager; a pharmacist is not one.
        $this->actingAs($this->both->user)->get(route('stock.inventories.show', $inventory))->assertInertia(fn (Assert $page) => $page->where('canDecide', false));
        $this->post(route('stock.inventories.approve', $inventory))->assertSessionHasErrorsIn('decide', ['decision' => 'Quem abriu, contou ou enviou o inventário não decide sobre ele.']);
        $this->actingAs($this->pharmacist->user)->post(route('stock.inventories.approve', $inventory))->assertForbidden();

        $this->actingAs($this->manager->user)->get(route('stock.inventories.show', $inventory))->assertInertia(fn (Assert $page) => $page->where('canDecide', true)
            ->where('lines.0.expected', 10)->where('lines.0.difference', -2)->where('lines.2.difference', 1)->where('lines.3.found', true)->where('lines.3.difference', 3));
        $this->post(route('stock.inventories.approve', $inventory))->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Inventário aprovado. 3 lote(s) ajustado(s).');
        $this->post(route('stock.inventories.approve', $inventory))->assertSessionHasErrorsIn('decide', ['decision' => 'Este inventário não está aguardando aprovação.']);

        $this->assertSame(['A' => 6, 'B' => 5, 'C' => 9, 'NOVO1' => 3], $this->balances());
        $novo = StockLot::withoutTenantScope()->where('lot_code', 'NOVO1')->sole()->id;
        $this->assertSame([$a => -2, $c => 1, $novo => 3], DB::table('stock_movements')->where('type', 'adjustment')->where('stock_inventory_id', $inventory->id)->orderBy('id')->pluck('quantity', 'stock_lot_id')->map(fn ($quantity) => (int) $quantity)->all());
        $this->assertEquals(['unit_id' => $this->unit->id, 'adjustments' => [$a => -2, $c => 1, $novo => 3]], AuditEvent::where('action', 'stock.inventory_approved')->sole()->context);
        foreach (DB::table('stock_lots')->get() as $lot) {
            $this->assertSame((int) DB::table('stock_movements')->where('stock_lot_id', $lot->id)->sum('quantity'), $lot->balance);
        }
        // Decided: the counter now sees the differences too.
        $this->actingAs($this->pharmacist->user)->get(route('stock.inventories.show', $inventory))->assertInertia(fn (Assert $page) => $page->where('lines.0.difference', -2)->where('canCount', false));
    }

    public function test_a_refusal_has_a_reason_and_frees_the_unit_for_a_new_count(): void
    {
        $a = $this->stockLot($this->unit->id, $this->medication, 10, 'A')->id;
        $inventory = $this->open();
        $this->countLot($this->pharmacist, $inventory, $a, 3);
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();

        $this->actingAs($this->manager->user)->post(route('stock.inventories.refuse', $inventory), ['refusal_reason' => ' '])->assertSessionHasErrorsIn('decide', ['refusal_reason']);
        $this->post(route('stock.inventories.refuse', $inventory), ['refusal_reason' => 'Diferença grande; contar de novo.'])->assertSessionHasNoErrors();
        $this->assertSame(['refused', 'Diferença grande; contar de novo.'], [$inventory->fresh()->status, $inventory->fresh()->refusal_reason]);
        $this->assertEquals(['unit_id' => $this->unit->id], AuditEvent::where('action', 'stock.inventory_refused')->sole()->context);
        $this->assertSame(['A' => 10], $this->balances());
        $this->open();
    }

    public function test_an_approval_that_would_leave_a_lot_negative_is_refused(): void
    {
        $a = $this->stockLot($this->unit->id, $this->medication, 5, 'A')->id;
        $inventory = $this->open();
        $this->countLot($this->pharmacist, $inventory, $a, 2);
        // After the count, 4 of the 5 go out: the adjustment of −3 no longer fits.
        $this->post(route('stock.discards.store', $a), ['reason' => 'loss', 'quantity' => 4])->assertSessionHasNoErrors();
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();

        $this->actingAs($this->manager->user)->post(route('stock.inventories.approve', $inventory))
            ->assertSessionHasErrorsIn('decide', ['decision' => 'Saiu mais do lote A depois da contagem do que o ajuste comporta. Recuse e peça uma nova contagem.']);
        $this->assertSame(['submitted', ['A' => 1]], [$inventory->fresh()->status, $this->balances()]);
    }

    public function test_a_recount_reads_the_balance_again_and_whoever_counted_never_decides(): void
    {
        $a = $this->stockLot($this->unit->id, $this->medication, 10, 'A')->id;
        $inventory = $this->open();
        // Pharmacy and manager at once: still a blind count while counting.
        $this->countLot($this->both, $inventory, $a, 3);
        $this->get(route('stock.inventories.show', $inventory))->assertInertia(fn (Assert $page) => $page->where('canCount', true)->where('lines.0.expected', null));
        $this->actingAs($this->pharmacist->user)->post(route('stock.discards.store', $a), ['reason' => 'loss', 'quantity' => 1])->assertSessionHasNoErrors();
        // Recounted by someone else: the balance is read again; who counted first still never decides.
        $this->countLot($this->pharmacist, $inventory, $a, 3);
        $this->assertSame([3, 9, $this->pharmacist->user_id], [$this->line($inventory, $a)->counted, $this->line($inventory, $a)->expected, $this->line($inventory, $a)->counted_by_user_id]);
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();
        $this->actingAs($this->both->user)->post(route('stock.inventories.refuse', $inventory), ['refusal_reason' => 'Não.'])
            ->assertSessionHasErrorsIn('decide', ['decision' => 'Quem abriu, contou ou enviou o inventário não decide sobre ele.']);
        $this->actingAs($this->manager->user)->post(route('stock.inventories.approve', $inventory))->assertSessionHasNoErrors();
        $this->assertSame(['A' => 3], $this->balances());

        // A line of another inventory is not this one's.
        $other = Unit::factory()->for($this->pharmacist->tenant)->create();
        $this->pharmacist->grant(Role::Pharmacy, $other);
        $b = $this->stockLot($other->id, $this->medication, 2, 'B')->id;
        $this->actingAs($this->pharmacist->user)->post(route('stock.inventories.store'), ['unit_id' => $other->id])->assertSessionHasNoErrors();
        $second = StockInventory::withoutTenantScope()->latest('id')->firstOrFail();
        $this->put(route('stock.inventories.count', [$inventory, $this->line($second, $b)]), ['counted' => 1])->assertNotFound();
    }

    public function test_a_found_lot_recorded_after_the_count_asks_for_a_new_count(): void
    {
        $this->stockLot($this->unit->id, $this->medication, 10, 'A');
        $inventory = $this->open();
        $this->countLot($this->pharmacist, $inventory, StockLot::withoutTenantScope()->sole()->id, 10);
        $found = fn (string $code, int $counted) => $this->post(route('stock.inventories.found', $inventory), ['medication_id' => $this->medication->id, 'lot_code' => $code, 'expires_on' => '2027-03-31', 'counted' => $counted]);
        $found('X', 2)->assertSessionHasNoErrors();
        $found('Y', 1)->assertSessionHasNoErrors();
        // Y was not really there: recounted to 0, no lot is created for it.
        $this->put(route('stock.inventories.count', [$inventory, StockInventoryLine::withoutTenantScope()->where('lot_code', 'Y')->sole()]), ['counted' => 0])->assertSessionHasNoErrors();
        // X is entered in the meantime: listing it again is refused, and approving would count it twice.
        $this->post(route('stock.entries.store'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'lot_code' => 'X', 'expires_on' => '2027-03-31', 'quantity' => 2])->assertSessionHasNoErrors();
        $found('X', 2)->assertSessionHasErrorsIn('found', ['lot_code' => 'Este lote já está na lista. Corrija a contagem dele.']);
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();
        $this->actingAs($this->manager->user)->post(route('stock.inventories.approve', $inventory))
            ->assertSessionHasErrorsIn('decide', ['decision' => 'O lote X foi cadastrado depois de encontrado na contagem. Recuse e peça uma nova contagem.']);
        $this->assertSame(['submitted', ['A' => 10, 'X' => 2]], [$inventory->fresh()->status, $this->balances()]);

        // Without X, approving creates no lot for Y.
        $this->post(route('stock.inventories.refuse', $inventory), ['refusal_reason' => 'Recontar.'])->assertSessionHasNoErrors();
        $again = $this->open();
        foreach ($again->lines as $line) {
            $this->countLot($this->pharmacist, $again, (int) $line->stock_lot_id, 1);
        }
        $this->post(route('stock.inventories.found', $again), ['medication_id' => $this->medication->id, 'lot_code' => 'Y', 'expires_on' => '2027-03-31', 'counted' => 1])->assertSessionHasNoErrors();
        $this->put(route('stock.inventories.count', [$again, StockInventoryLine::withoutTenantScope()->where(['stock_inventory_id' => $again->id, 'lot_code' => 'Y'])->sole()]), ['counted' => 0])->assertSessionHasNoErrors();
        $this->post(route('stock.inventories.submit', $again))->assertSessionHasNoErrors();
        $this->actingAs($this->manager->user)->post(route('stock.inventories.approve', $again))->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Inventário aprovado. 2 lote(s) ajustado(s).');
        $this->assertSame(['A' => 1, 'X' => 1], $this->balances());
    }

    public function test_only_the_units_pharmacy_and_manager_see_it_and_the_database_keeps_the_adjustments(): void
    {
        $a = $this->stockLot($this->unit->id, $this->medication, 5, 'A')->id;
        $z = $this->stockLot($this->unit->id, $this->medication, 1, 'Z')->id;
        $inventory = $this->open();
        $outsider = Membership::factory()->for($this->pharmacist->tenant)->create();
        $this->actingAs($outsider->user)->get(route('stock.inventories.index'))->assertForbidden();
        $outsider->grant(Role::Pharmacy, Unit::factory()->for($this->pharmacist->tenant)->create());
        $this->actingAs($outsider->user)->get(route('stock.inventories.show', $inventory))->assertForbidden();
        $this->put(route('stock.inventories.count', [$inventory, $this->line($inventory, $a)]), ['counted' => 1])->assertForbidden();
        $this->actingAs($this->manager->user)->get(route('stock.inventories.index'))->assertInertia(fn (Assert $page) => $page->has('inventories', 1)->has('openUnits', 0));
        $this->post(route('stock.inventories.store'), ['unit_id' => $this->unit->id])->assertSessionHasErrorsIn('open', ['unit_id']);

        $this->countLot($this->pharmacist, $inventory, $a, 4);
        $this->countLot($this->pharmacist, $inventory, $z, 1);
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();
        $this->actingAs($this->manager->user)->post(route('stock.inventories.approve', $inventory))->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Inventário aprovado. 1 lote(s) ajustado(s).');
        $movement = (array) DB::table('stock_movements')->where('type', 'adjustment')->sole();
        // A line is a recorded lot or a code with an expiry, and a count always has the balance at the count.
        $line = (array) DB::table('stock_inventory_lines')->where('stock_lot_id', $a)->sole();
        foreach ([['expected' => null], ['stock_lot_id' => null, 'lot_code' => null, 'expires_on' => '2027-01-01'], ['counted' => null]] as $wrong) {
            try {
                DB::transaction(fn () => DB::table('stock_inventory_lines')->insert([...$line, 'id' => $line['id'] + 100, 'stock_lot_id' => null, 'lot_code' => 'W', 'expires_on' => '2027-01-01', ...$wrong]));
                $this->fail('The database accepted '.json_encode($wrong));
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
        // One adjustment per lot and inventory; an adjustment belongs to an inventory and is never zero.
        foreach ([[[], '23505'], [['stock_inventory_id' => null], '23514'], [['type' => 'entry', 'quantity' => 1], '23514'], [['quantity' => 0, 'stock_lot_id' => $z], '23514']] as [$wrong, $code]) {
            try {
                DB::transaction(fn () => DB::table('stock_movements')->insert([...$movement, 'id' => $movement['id'] + 100, ...$wrong]));
                $this->fail('The database accepted '.json_encode($wrong));
            } catch (QueryException $exception) {
                $this->assertSame($code, $exception->getCode());
            }
        }
    }

    public function test_material_lots_are_counted_found_and_adjusted_like_medications(): void
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        $gauze = Material::query()->create(['name' => 'Compressa cirúrgica', 'stock_unit' => 'pacote']);
        app(CurrentTenant::class)->set(null);
        $this->stockLot($this->unit->id, $this->medication, 10, 'A');
        $this->actingAs($this->pharmacist->user)->post(route('stock.entries.store'), ['unit_id' => $this->unit->id, 'material_id' => $gauze->id, 'lot_code' => 'G1', 'expires_on' => '2027-06-30', 'quantity' => 20])->assertSessionHasNoErrors();
        $g1 = StockLot::withoutTenantScope()->where('lot_code', 'G1')->sole()->id;
        $this->get(route('stock.inventories.index'))->assertInertia(fn (Assert $page) => $page
            ->where('openUnits.0.items', fn ($items) => collect($items)->pluck('key')->all() === ["medication:{$this->medication->id}", "material:{$gauze->id}"]));

        // Only the chosen material is counted.
        $this->post(route('stock.inventories.store'), ['unit_id' => $this->unit->id, 'material_ids' => [$gauze->id]])->assertSessionHasNoErrors();
        $inventory = StockInventory::withoutTenantScope()->latest('id')->firstOrFail();
        $this->assertSame([$g1], $inventory->lines()->pluck('stock_lot_id')->all());
        $this->countLot($this->pharmacist, $inventory, $g1, 18);
        $this->post(route('stock.inventories.found', $inventory), ['material_id' => $gauze->id, 'lot_code' => 'g2', 'expires_on' => '2027-08-31', 'counted' => 4])->assertSessionHasNoErrors();
        $this->post(route('stock.inventories.found', $inventory), ['material_id' => $gauze->id, 'lot_code' => 'G2', 'expires_on' => '2027-08-31', 'counted' => 1])
            ->assertSessionHasErrorsIn('found', ['lot_code' => 'Este lote já está na lista. Corrija a contagem dele.']);
        $this->get(route('stock.inventories.show', $inventory))->assertInertia(fn (Assert $page) => $page->where('lines.0.medication', 'Compressa cirúrgica')->where('lines.1.stockUnit', 'pacote')
            ->where('catalog', fn ($catalog) => collect($catalog)->pluck('key')->contains("material:{$gauze->id}")));
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();
        $this->actingAs($this->manager->user)->post(route('stock.inventories.approve', $inventory))->assertSessionHasNoErrors();

        $this->assertSame(['A' => 10, 'G1' => 18, 'G2' => 4], $this->balances());
        $this->assertSame($gauze->id, StockLot::withoutTenantScope()->where('lot_code', 'G2')->value('material_id'));
        // An inventory line holds one item, never both or none.
        foreach ([['medication_id' => $this->medication->id, 'material_id' => $gauze->id], []] as $item) {
            try {
                DB::transaction(fn () => DB::table('stock_inventory_lines')->insert(['tenant_id' => $inventory->tenant_id, 'stock_inventory_id' => $inventory->id, 'lot_code' => 'X'.count($item), 'expires_on' => '2027-01-01', ...$item]));
                $this->fail('The database accepted an inventory line without exactly one item.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), $e->getMessage());
            }
        }
    }
}
