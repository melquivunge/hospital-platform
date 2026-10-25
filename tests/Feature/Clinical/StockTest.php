<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Material;
use App\Models\Medication;
use App\Models\Membership;
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
 * Step 4c.1a (ADR-0007): lot entries into the unit's pharmacy stock, the balance kept by the database from the
 * movements, and the minimum of each medication; step 5b.6b (ADR-0009): materials in the same ledger.
 */
class StockTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Membership $pharmacist;

    private Unit $unit;

    private Unit $other;

    private Medication $medication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 12:00:00');
        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $this->admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['timezone' => 'America/Sao_Paulo']);
        $this->other = Unit::factory()->for($tenant)->create(['timezone' => 'America/Sao_Paulo']);
        $this->pharmacist = Membership::factory()->for($tenant)->create();
        $this->pharmacist->grant(Role::Pharmacy, $this->unit);
        app(CurrentTenant::class)->set($tenant);
        $this->medication = Medication::query()->create(['active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'stock_unit' => 'comprimido']);
        app(CurrentTenant::class)->set(null);
    }

    private function entry(array $overrides = []): void
    {
        $this->actingAs($this->pharmacist->user)->post(route('stock.entries.store'), [
            'unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'lot_code' => 'ab 12-3', 'expires_on' => '2027-05-31', 'quantity' => 100, ...$overrides,
        ])->assertSessionHasNoErrors();
    }

    public function test_an_entry_creates_the_lot_and_the_same_lot_adds_up(): void
    {
        $this->entry(['manufacturer' => ' EMS ']);
        $this->entry(['lot_code' => 'AB12-3', 'quantity' => 20]);
        $this->entry(['lot_code' => 'X9', 'expires_on' => '2026-12-01', 'quantity' => 5]);

        $lot = StockLot::withoutTenantScope()->where('lot_code', 'AB12-3')->sole();
        $this->assertSame([120, 'EMS'], [$lot->balance, $lot->manufacturer]);
        $this->assertSame(2, DB::table('stock_lots')->count());
        // The balance is the sum of the movements.
        foreach (DB::table('stock_lots')->get() as $row) {
            $this->assertSame((int) DB::table('stock_movements')->where('stock_lot_id', $row->id)->sum('quantity'), $row->balance);
        }
        $audit = AuditEvent::where('action', 'stock.entry')->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['unit_id', 'medication_id', 'stock_lot_id', 'quantity'], array_keys($audit->context));

        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->component('stock/index')
            ->where('items.0.total', 125)->where('items.0.stockUnit', 'comprimido')->where('items.0.below', false)
            // The nearest expiry first; X9 expires within 30 days.
            ->where('items.0.lots.0.code', 'X9')->where('items.0.lots.0.state', 'expiring')
            ->where('items.0.lots.1.state', 'valid'));
    }

    public function test_an_expired_lot_an_inactive_medication_and_another_units_stock_are_refused(): void
    {
        $this->actingAs($this->pharmacist->user);
        $post = fn (array $overrides) => $this->post(route('stock.entries.store'), [
            'unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'lot_code' => 'L1', 'expires_on' => '2027-01-01', 'quantity' => 10, ...$overrides,
        ]);
        $post(['expires_on' => '2026-11-12'])->assertSessionHasErrors('expires_on', null, 'entry');
        $post(['unit_id' => $this->other->id])->assertSessionHasErrors('unit_id', null, 'entry');
        $post(['lot_code' => 'lote ção'])->assertSessionHasErrors('lot_code', null, 'entry');
        $post(['quantity' => 0])->assertSessionHasErrors('quantity', null, 'entry');
        $this->medication->forceFill(['active' => false])->saveQuietly();
        $post([])->assertSessionHasErrors('medication_id', null, 'entry');
        $this->assertSame(0, DB::table('stock_movements')->count());

        // Only the unit's pharmacy: not the network admin, not a pharmacy of another unit.
        $this->actingAs($this->admin->user)->get(route('stock.index'))->assertForbidden();
        $this->post(route('stock.entries.store'), ['unit_id' => $this->unit->id])->assertSessionHasErrors('unit_id', null, 'entry');
        $elsewhere = Membership::factory()->for($this->admin->tenant)->create();
        $elsewhere->grant(Role::Pharmacy, $this->other);
        $this->actingAs($elsewhere->user)->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('unitId', $this->other->id)->has('items', 0));
    }

    public function test_a_discard_takes_from_the_lot_with_a_reason_and_never_more_than_its_balance(): void
    {
        $this->entry();
        $lot = StockLot::withoutTenantScope()->sole();
        $discard = fn (array $data) => $this->actingAs($this->pharmacist->user)->post(route('stock.discards.store', $lot), $data);

        $discard(['reason' => 'damage', 'quantity' => 10, 'note' => 'ignorado'])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Descarte registrado.');
        $discard(['reason' => 'other', 'quantity' => 5, 'note' => ' Frasco sem rótulo '])->assertSessionHasNoErrors();
        $discard(['reason' => 'other', 'quantity' => 5])->assertSessionHasErrorsIn("discard{$lot->id}", ['note']);
        $discard(['reason' => 'expired', 'quantity' => 5])->assertSessionHasErrorsIn("discard{$lot->id}", ['reason' => 'Este lote ainda está na validade. Escolha outro motivo.']);
        $discard(['reason' => 'loss', 'quantity' => 86])->assertSessionHasErrorsIn("discard{$lot->id}", ['quantity' => 'O lote tem 85 em saldo.']);
        $discard(['reason' => 'stolen', 'quantity' => 1])->assertSessionHasErrorsIn("discard{$lot->id}", ['reason']);
        $discard(['reason' => 'loss', 'quantity' => 0])->assertSessionHasErrorsIn("discard{$lot->id}", ['quantity']);
        $discard(['reason' => 'other', 'quantity' => 1, 'note' => '   '])->assertSessionHasErrorsIn("discard{$lot->id}", ['note']);
        $discard(['reason' => 'other', 'quantity' => 1, 'note' => str_repeat('a', 201)])->assertSessionHasErrorsIn("discard{$lot->id}", ['note']);

        $this->assertSame(85, $lot->fresh()->balance);
        $this->assertSame([['damage', null, -10], ['other', 'Frasco sem rótulo', -5]], DB::table('stock_movements')->where('type', 'discard')->orderBy('id')->get()
            ->map(fn ($row) => [$row->reason, $row->note, (int) $row->quantity])->all());
        // The audit keeps the reason code, never the text.
        $audit = AuditEvent::where('action', 'stock.discard')->latest('id')->firstOrFail();
        $this->assertEquals(['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'stock_lot_id' => $lot->id, 'quantity' => 5, 'reason' => 'other'], $audit->context);
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('discards.0.note', 'Frasco sem rótulo')->where('discards.1.reason', 'damage'));

        // The database keeps the codes and the text together.
        $movement = (array) DB::table('stock_movements')->where('type', 'discard')->first();
        foreach ([['reason' => 'stolen'], ['reason' => 'damage', 'note' => 'texto'], ['reason' => 'other', 'note' => null], ['reason' => 'other', 'note' => '  '], ['reason' => 'damage', 'note' => '  '], ['type' => 'entry', 'quantity' => 1, 'reason' => null, 'note' => 'texto']] as $wrong) {
            try {
                DB::transaction(fn () => DB::table('stock_movements')->insert([...$movement, 'id' => $movement['id'] + 100, ...$wrong]));
                $this->fail('The database accepted '.json_encode($wrong));
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
    }

    public function test_an_expired_lot_is_discarded_whole_and_only_by_the_units_pharmacy(): void
    {
        $this->entry(['expires_on' => '2026-11-20', 'quantity' => 7]);
        $lot = StockLot::withoutTenantScope()->sole();
        // The last day of validity is not expired yet (unit time zone); the next day it is.
        $this->travelTo('2026-11-21 02:59:00');
        $this->actingAs($this->pharmacist->user)->post(route('stock.discards.store', $lot), ['reason' => 'expired', 'quantity' => 7])->assertSessionHasErrorsIn("discard{$lot->id}", ['reason']);
        $this->travelTo('2026-12-01 12:00:00');
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('items.0.lots.0.state', 'expired'));

        $this->actingAs($this->admin->user)->post(route('stock.discards.store', $lot), ['reason' => 'expired', 'quantity' => 7])->assertForbidden();
        $elsewhere = Membership::factory()->for($this->admin->tenant)->create();
        $elsewhere->grant(Role::Pharmacy, $this->other);
        $this->actingAs($elsewhere->user)->post(route('stock.discards.store', $lot), ['reason' => 'expired', 'quantity' => 7])->assertForbidden();
        $this->assertSame(7, $lot->fresh()->balance);

        $this->actingAs($this->pharmacist->user)->post(route('stock.discards.store', $lot), ['reason' => 'expired', 'quantity' => 7])->assertSessionHasNoErrors();
        $this->assertSame(0, $lot->fresh()->balance);
    }

    public function test_a_lot_on_its_last_day_is_still_valid_in_the_units_time_zone(): void
    {
        // 23:00 on 11-13 in São Paulo (02:00 UTC on 11-14): the lot that expires on 11-13 is still valid.
        $this->travelTo('2026-11-14 02:00:00');
        $this->entry(['expires_on' => '2026-11-13', 'quantity' => 5]);
        $this->actingAs($this->pharmacist->user)->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => 5])->assertSessionHasNoErrors();
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('items.0.lots.0.state', 'expiring')
            ->where('items.0.valid', 5)->where('items.0.below', false));
        // From midnight in the unit, expired.
        $this->travelTo('2026-11-14 03:00:00');
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('items.0.lots.0.state', 'expired')->where('items.0.below', true));
    }

    public function test_the_database_keeps_the_balance_and_the_history(): void
    {
        $this->entry();
        $lot = DB::table('stock_lots')->sole();
        $movement = DB::table('stock_movements')->sole();
        $refused = function (callable $statement, string $code) {
            try {
                DB::transaction($statement);
                $this->fail('The database accepted it.');
            } catch (QueryException $exception) {
                $this->assertSame($code, $exception->getCode());
            }
        };
        $refused(fn () => DB::table('stock_lots')->where('id', $lot->id)->update(['balance' => 1000]), 'P0001');
        // Announcing the change by hand does not help a statement of the application.
        $refused(function () use ($lot) {
            DB::select("SELECT set_config('app.stock_balance_change', ?, true)", ["{$lot->id}:900"]);
            DB::table('stock_lots')->where('id', $lot->id)->update(['balance' => 1000]);
        }, 'P0001');
        $refused(fn () => DB::table('stock_lots')->where('id', $lot->id)->update(['lot_code' => 'OUTRO']), 'P0001');
        $refused(fn () => DB::table('stock_lots')->where('id', $lot->id)->delete(), 'P0001');
        $refused(fn () => DB::table('stock_lots')->insert([...(array) $lot, 'id' => $lot->id + 1, 'lot_code' => 'NOVO', 'balance' => 50]), 'P0001');
        $refused(fn () => DB::table('stock_movements')->where('id', $movement->id)->update(['quantity' => 1]), 'P0001');
        $refused(fn () => DB::table('stock_movements')->where('id', $movement->id)->delete(), 'P0001');
        // The runtime role may not truncate at all; the trigger guards the owner.
        $refused(fn () => DB::statement('TRUNCATE stock_movements CASCADE'), '42501');
        $refused(fn () => DB::table('stock_movements')->insert([...(array) $movement, 'id' => $movement->id + 1, 'quantity' => -500]), '23514');
        $this->assertSame(100, DB::table('stock_lots')->value('balance'));
    }

    public function test_a_lot_keeps_its_manufacturer_and_an_expiry_far_ahead_is_a_typo(): void
    {
        $this->entry(['manufacturer' => 'EMS']);
        $this->entry(['manufacturer' => 'ems', 'quantity' => 1]);
        $this->entry(['quantity' => 1]);
        $this->post(route('stock.entries.store'), [
            'unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'lot_code' => 'AB12-3', 'expires_on' => '2027-05-31', 'quantity' => 5, 'manufacturer' => 'Medley',
        ])->assertSessionHasErrors('manufacturer', null, 'entry');
        $this->post(route('stock.entries.store'), [
            'unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'lot_code' => 'Z1', 'expires_on' => '2062-05-31', 'quantity' => 5,
        ])->assertSessionHasErrors('expires_on', null, 'entry');
        $this->assertSame(102, DB::table('stock_lots')->sole()->balance);
        $this->assertSame(3, DB::table('stock_movements')->count());
    }

    public function test_without_the_pharmacy_role_or_with_another_networks_medication_nothing_moves(): void
    {
        $nurse = Membership::factory()->for($this->admin->tenant)->create();
        $nurse->grant(Role::Professional, $this->unit);
        $this->actingAs($nurse->user)->get(route('stock.index'))->assertForbidden();
        $this->post(route('stock.entries.store'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'lot_code' => 'L1', 'expires_on' => '2027-01-01', 'quantity' => 5])
            ->assertSessionHasErrors('unit_id', null, 'entry');

        // The request above left its tenant as the current one.
        app(CurrentTenant::class)->set(null);
        $foreign = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        app(CurrentTenant::class)->set($foreign->tenant);
        $theirs = Medication::query()->create(['active_ingredient' => 'Captopril', 'form' => 'Comprimido', 'strength' => '25 mg', 'dose_unit' => 'mg', 'routes' => ['oral']]);
        app(CurrentTenant::class)->set(null);
        $this->actingAs($this->pharmacist->user)->post(route('stock.entries.store'), ['unit_id' => $this->unit->id, 'medication_id' => $theirs->id, 'lot_code' => 'L1', 'expires_on' => '2027-01-01', 'quantity' => 5])
            ->assertSessionHasErrors('medication_id', null, 'entry');
        $this->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $theirs->id, 'minimum' => 5])->assertNotFound();
        $this->assertSame(0, DB::table('stock_movements')->count() + DB::table('stock_minimums')->count());
    }

    public function test_the_minimum_flags_the_medication_and_empty_removes_it(): void
    {
        $this->entry(['quantity' => 10]);
        $this->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => 30])->assertSessionHasNoErrors();
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->where('items.0.minimum', 30)->where('items.0.below', true));
        $this->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => 5])->assertSessionHasNoErrors();
        $this->assertSame(5, DB::table('stock_minimums')->sole()->minimum);
        $this->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => ''])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('stock_minimums')->count());
        // Unchanged: nothing to audit.
        $audits = AuditEvent::where('action', 'stock.minimum_set')->count();
        $this->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => ''])->assertSessionHasNoErrors();
        $this->assertSame($audits, AuditEvent::where('action', 'stock.minimum_set')->count());
        $this->put(route('stock.minimums.update'), ['unit_id' => $this->other->id, 'medication_id' => $this->medication->id, 'minimum' => 5])->assertSessionHasErrors('unit_id', null, 'minimum');
        $this->assertEquals(['unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'minimum' => null], AuditEvent::where('action', 'stock.minimum_set')->latest('id')->firstOrFail()->context);
    }

    public function test_the_catalog_keeps_the_stock_unit(): void
    {
        $this->actingAs($this->admin->user)->put(route('medications.update', $this->medication), [
            'active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'active' => true, 'stock_unit' => 'caixa',
        ])->assertSessionHasErrors('stock_unit', null, "medication-{$this->medication->id}");
        $this->put(route('medications.update', $this->medication), [
            'active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'active' => true, 'stock_unit' => 'drágea',
        ])->assertSessionHasNoErrors();
        $this->assertSame('drágea', $this->medication->refresh()->stock_unit);
    }

    public function test_materials_share_the_ledger_with_entries_minimum_discard_report_indicators_and_alert(): void
    {
        app(CurrentTenant::class)->set($this->admin->tenant);
        $gauze = Material::query()->create(['name' => 'Compressa cirúrgica', 'stock_unit' => 'pacote']);
        $inactive = Material::query()->create(['name' => 'Luva antiga', 'stock_unit' => 'par', 'active' => false]);
        app(CurrentTenant::class)->set(null);
        $this->entry();
        $material = fn (array $data) => $this->post(route('stock.entries.store'), ['unit_id' => $this->unit->id, 'lot_code' => 'g1', 'expires_on' => '2026-11-20', 'quantity' => 40, ...$data]);
        $material(['material_id' => $gauze->id])->assertSessionHasNoErrors();
        $material(['material_id' => $gauze->id, 'quantity' => 10])->assertSessionHasNoErrors();
        // Exactly one item, active.
        $material(['material_id' => $inactive->id])->assertSessionHasErrorsIn('entry', ['material_id' => 'Escolha um medicamento ou material ativo do catálogo.']);
        $material(['material_id' => $gauze->id, 'medication_id' => $this->medication->id])->assertSessionHasErrorsIn('entry', ['medication_id']);
        $material([])->assertSessionHasErrorsIn('entry', ['medication_id']);
        $lot = StockLot::withoutTenantScope()->whereNotNull('material_id')->sole();
        $this->assertSame([null, 'G1', 50], [$lot->medication_id, $lot->lot_code, $lot->balance]);
        $this->assertEquals(['unit_id' => $this->unit->id, 'material_id' => $gauze->id, 'stock_lot_id' => $lot->id, 'quantity' => 10], AuditEvent::where('action', 'stock.entry')->latest('id')->firstOrFail()->context);

        $this->put(route('stock.minimums.update'), ['unit_id' => $this->unit->id, 'material_id' => $gauze->id, 'minimum' => 60])->assertSessionHasNoErrors();
        $this->assertEquals(['unit_id' => $this->unit->id, 'material_id' => $gauze->id, 'minimum' => 60], AuditEvent::where('action', 'stock.minimum_set')->sole()->context);
        $this->post(route('stock.discards.store', $lot), ['reason' => 'damage', 'quantity' => 5])->assertSessionHasNoErrors();
        $this->assertEquals(['unit_id' => $this->unit->id, 'material_id' => $gauze->id, 'stock_lot_id' => $lot->id, 'quantity' => 5, 'reason' => 'damage'], AuditEvent::where('action', 'stock.discard')->sole()->context);

        // Medications first, then materials; the material flagged below its minimum.
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->has('items', 2)
            ->where('items.0.key', "medication:{$this->medication->id}")->where('items.1.key', "material:{$gauze->id}")->where('items.1.material', true)
            ->where('items.1.total', 45)->where('items.1.stockUnit', 'pacote')->where('items.1.below', true)->where('items.1.lots.0.state', 'expiring')
            ->where('discards.0.name', 'Compressa cirúrgica')
            ->where('catalog', fn ($catalog) => collect($catalog)->pluck('key')->all() === ["medication:{$this->medication->id}", "material:{$gauze->id}"]));

        // The movement report filters by the material; its rows carry the name and no controlled flag.
        $this->get(route('stock.movements.index', ['item' => "material:{$gauze->id}"]))->assertInertia(fn (Assert $page) => $page
            ->where('filters.item', "material:{$gauze->id}")->has('summary', 1)->where('summary.0.entry', 50)->where('summary.0.discard', -5)
            ->where('pages.total', 3)->where('movements.0.medication', 'Compressa cirúrgica')->where('movements.0.controlled', false)
            ->where('options', fn ($options) => collect($options)->pluck('key')->all() === ["medication:{$this->medication->id}", "material:{$gauze->id}"]));
        $this->get(route('stock.movements.index', ['item' => 'material:x']))->assertInertia(fn (Assert $page) => $page->where('filters.item', null)->where('pages.total', 4));

        $this->get(route('stock.indicators.index'))->assertInertia(fn (Assert $page) => $page->where('units.0.below', 1)->where('units.0.expiring', 1)
            ->where('detail.below.0.id', "material:{$gauze->id}")->where('detail.below.0.medication', 'Compressa cirúrgica')->where('detail.below.0.valid', 45)
            ->where('detail.expiring.0.stockUnit', 'pacote')->where('detail.losses.0.medication', 'Compressa cirúrgica'));
        $this->artisan('stock:alerts')->expectsOutput('Avisos enviados: 1.')->assertSuccessful();
        $this->assertEquals(['expiring' => 1, 'expired' => 0, 'below_minimum' => 1], collect(AuditEvent::where('action', 'stock.daily_alert')->sole()->context)->only(['expiring', 'expired', 'below_minimum'])->all());

        // The database keeps one item per lot and per minimum.
        foreach ([
            fn () => DB::table('stock_lots')->insert(['tenant_id' => $lot->tenant_id, 'unit_id' => $this->unit->id, 'medication_id' => $this->medication->id, 'material_id' => $gauze->id, 'lot_code' => 'X1', 'expires_on' => '2027-01-01', 'created_by_user_id' => $this->pharmacist->user->id, 'created_at' => now()]),
            fn () => DB::table('stock_lots')->insert(['tenant_id' => $lot->tenant_id, 'unit_id' => $this->unit->id, 'lot_code' => 'X2', 'expires_on' => '2027-01-01', 'created_by_user_id' => $this->pharmacist->user->id, 'created_at' => now()]),
            fn () => DB::table('stock_minimums')->insert(['tenant_id' => $lot->tenant_id, 'unit_id' => $this->unit->id, 'minimum' => 1, 'updated_by_user_id' => $this->pharmacist->user->id, 'created_at' => now(), 'updated_at' => now()]),
        ] as $insert) {
            try {
                DB::transaction($insert);
                $this->fail('The database accepted a row without exactly one item.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), $e->getMessage());
            }
        }
    }
}
