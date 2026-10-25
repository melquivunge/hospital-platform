<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\BillingItem;
use App\Models\Material;
use App\Models\Membership;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 6.1a (ADR-0010): the Billing role, the network's billable items and its price tables, with prices in cents
 * whose periods never overlap; a new price ends the one before it, and a price is never changed nor deleted.
 */
class BillingPriceTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Unit $unit;

    /** Another network, created before the first request. */
    private Membership $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->unit = Unit::factory()->for($this->admin->tenant)->create();
        $this->stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
    }

    public function test_prices_from_reais_in_cents(): void
    {
        $this->assertSame([123456, 123450, 123400, 5, 99999999999999, null, null, null, null, null], array_map(fn (string $text) => PriceEntry::cents($text),
            ['1.234,56', '1234,5', '1234', '0,05', '999.999.999.999,99', '12.34', '1,234', 'R$ 10', '99999999999999999999', '1.234.567.890.123']));
    }

    public function test_the_admin_keeps_items_tables_and_prices_with_periods_that_never_overlap(): void
    {
        app(CurrentTenant::class)->set($this->admin->tenant);
        $gauze = Material::query()->create(['name' => 'Compressa', 'stock_unit' => 'pacote']);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->admin->user)->post(route('billing-items.store'), ['kind' => 'daily', 'bed_type' => 'icu', 'name' => 'Diária de UTI', 'code' => '60000775'])->assertSessionHasNoErrors();
        $this->post(route('billing-items.store'), ['kind' => 'material', 'reference_id' => $gauze->id, 'name' => 'Compressa cirúrgica'])->assertSessionHasNoErrors();
        $this->post(route('billing-items.store'), ['kind' => 'consultation', 'name' => 'Consulta'])->assertSessionHasNoErrors();
        // One item per thing charged for; each kind with its own reference.
        $this->post(route('billing-items.store'), ['kind' => 'daily', 'bed_type' => 'icu', 'name' => 'Outra UTI'])->assertSessionHasErrorsIn('newItem', ['name' => 'Já existe um item cobrável para isto.']);
        $this->post(route('billing-items.store'), ['kind' => 'material', 'name' => 'Sem referência'])->assertSessionHasErrorsIn('newItem', ['reference_id']);
        $this->post(route('billing-items.store'), ['kind' => 'material', 'reference_id' => 999999, 'name' => 'X'])->assertSessionHasErrorsIn('newItem', ['reference_id' => 'Escolha um item ativo do catálogo.']);
        $this->post(route('billing-items.store'), ['kind' => 'consultation', 'name' => '=1+1', 'code' => '-1'])->assertSessionHasErrorsIn('newItem', ['name', 'code']);
        $icu = BillingItem::withoutTenantScope()->where('kind', 'daily')->sole();
        $this->assertEquals(['kind' => 'daily'], AuditEvent::where('action', 'billing_item.created')->oldest('id')->first()->context);

        $this->post(route('price-tables.store'), ['name' => 'Particular'])->assertSessionHasNoErrors();
        $this->post(route('price-tables.store'), ['name' => 'PARTICULAR'])->assertSessionHasErrorsIn('newTable', ['name' => 'Já existe uma tabela com este nome.']);
        $table = PriceTable::withoutTenantScope()->sole();
        $price = fn (string $text, string $from) => $this->post(route('price-tables.prices.store', $table), ['billing_item_id' => $icu->id, 'price' => $text, 'valid_from' => $from]);
        $price('2.500,00', '2026-01-01')->assertSessionHasNoErrors();
        $price('2.800,00', '2026-07-01')->assertSessionHasNoErrors();
        // A price starting on or before the current one is refused; a bad amount too.
        $price('2.900,00', '2026-07-01')->assertSessionHasErrorsIn('price', ['valid_from']);
        $price('2.900,00', '2026-03-01')->assertSessionHasErrorsIn('price', ['valid_from']);
        $price('2,900.00', '2026-09-01')->assertSessionHasErrorsIn('price', ['price' => 'Escreva o preço em reais, como 1.234,56.']);
        $price('99999999999999999', '2026-09-01')->assertSessionHasErrorsIn('price', ['price']);
        // An inactive item, an item of another network and an inactive table take no new price.
        $this->put(route('billing-items.update', $icu), ['name' => 'Diária de UTI', 'code' => '60000775', 'active' => false])->assertSessionHasNoErrors();
        $price('3.000,00', '2026-09-01')->assertSessionHasErrorsIn('price', ['billing_item_id']);
        $this->put(route('billing-items.update', $icu), ['name' => 'Diária de UTI', 'code' => '60000775', 'active' => true])->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set(null);
        $foreign = BillingItem::withoutTenantScope()->forceCreate(['tenant_id' => $this->stranger->tenant_id, 'kind' => 'consultation', 'name' => 'Consulta']);
        $this->post(route('price-tables.prices.store', $table), ['billing_item_id' => $foreign->id, 'price' => '10,00', 'valid_from' => '2026-09-01'])->assertSessionHasErrorsIn('price', ['billing_item_id']);
        $this->put(route('price-tables.update', $table), ['name' => 'Particular', 'active' => false])->assertSessionHasNoErrors();
        $price('3.000,00', '2026-09-01')->assertSessionHasErrorsIn('price', ['billing_item_id' => 'Esta tabela está inativa. Reative-a antes de registrar preços.']);
        $this->put(route('price-tables.update', $table), ['name' => 'Particular', 'active' => true])->assertSessionHasNoErrors();
        $this->assertSame([['2026-01-01', '2026-06-30', 250000], ['2026-07-01', null, 280000]], PriceEntry::withoutTenantScope()->orderBy('valid_from')->get()
            ->map(fn (PriceEntry $entry) => [$entry->valid_from->toDateString(), $entry->valid_until?->toDateString(), $entry->price_cents])->all());
        $this->assertSame([250000, 280000, null], [$table->priceOn($icu->id, '2026-06-30'), $table->priceOn($icu->id, '2027-01-01'), $table->priceOn($icu->id, '2025-12-31')]);
        $this->assertEquals(['price_table_id' => $table->id, 'billing_item_id' => $icu->id, 'price_cents' => 280000, 'valid_from' => '2026-07-01'], AuditEvent::where('action', 'price_entry.created')->latest('id')->first()->context);

        $this->get(route('billing-prices.index', ['table' => $table->id]))->assertInertia(fn (Assert $page) => $page->component('billing-prices/index')
            ->has('items', 3)->where('tableId', $table->id)->has('prices', 2)->where('prices.0.cents', 280000)->has('references.material', 1));

        // The database keeps prices: never changed nor deleted, no overlap, only the end date set once.
        foreach ([
            fn () => DB::table('price_entries')->where('valid_from', '2026-01-01')->update(['price_cents' => 1]),
            fn () => DB::table('price_entries')->where('valid_from', '2026-01-01')->delete(),
            fn () => DB::table('price_entries')->where('valid_from', '2026-01-01')->update(['valid_until' => '2026-05-01']),
            fn () => DB::table('price_entries')->insert(['tenant_id' => $table->tenant_id, 'price_table_id' => $table->id, 'billing_item_id' => $icu->id, 'price_cents' => 1, 'valid_from' => '2026-08-01', 'created_by_user_id' => $this->admin->user_id, 'created_at' => now()]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('The database accepted a change to a price.');
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23514', '23P01'], $e->getMessage());
            }
        }
    }

    public function test_only_the_network_admin_and_only_its_own_network_and_the_billing_role_needs_a_unit(): void
    {
        $stranger = $this->stranger;
        $manager = Membership::factory()->for($this->admin->tenant)->create();
        $manager->grant(Role::UnitManager, $this->unit);
        $table = PriceTable::withoutTenantScope()->forceCreate(['tenant_id' => $this->admin->tenant_id, 'name' => 'Particular']);

        $this->actingAs($manager->user)->get(route('billing-prices.index'))->assertForbidden();
        $this->post(route('price-tables.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($stranger->user)->put(route('price-tables.update', $table), ['name' => 'Y', 'active' => true])->assertNotFound();

        $person = Membership::factory()->for($this->admin->tenant)->create();
        $this->actingAs($this->admin->user)->post(route('people.roles.store', $person), ['role' => 'billing', 'unit_id' => $this->unit->id])->assertSessionHasNoErrors();
        $this->assertTrue($person->fresh()->hasRole(Role::Billing, $this->unit->id));
        $this->post(route('people.roles.store', $person), ['role' => 'billing'])->assertSessionHasErrors();
    }
}
