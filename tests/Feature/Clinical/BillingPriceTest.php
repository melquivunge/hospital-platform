<?php

namespace Tests\Feature\Clinical;

use App\Jobs\ApplyImportBatch;
use App\Models\AuditEvent;
use App\Models\BillingItem;
use App\Models\ImportBatch;
use App\Models\Insurer;
use App\Models\InsurerContract;
use App\Models\Material;
use App\Models\Membership;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 6.1a (ADR-0010): the Billing role, the network's billable items and its price tables, with prices in cents
 * whose periods never overlap; a new price ends the one before it, and a price is never changed nor deleted. Step
 * 6.1b: the insurers' contracts (table, adjustment, items that need authorization) and the private table.
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

    public function test_percentages_in_basis_points(): void
    {
        $this->assertSame([1000, -550, 1275, 0, null, null], array_map(fn (string $text) => InsurerContract::basisPoints($text), ['10', '-5,5', '12,75', '0', '10.5', '12345']));
    }

    public function test_contracts_price_by_their_table_and_adjustment_and_one_table_is_the_private_one(): void
    {
        $tenant = $this->admin->tenant;
        $insurer = Insurer::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'Saúde Mais']);
        $foreign = Insurer::withoutTenantScope()->forceCreate(['tenant_id' => $this->stranger->tenant_id, 'name' => 'Outra']);
        $manager = Membership::factory()->for($tenant)->create();
        $manager->grant(Role::UnitManager, $this->unit);
        $item = BillingItem::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'kind' => 'consultation', 'name' => 'Consulta']);
        [$base, $private] = [PriceTable::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'CBHPM']), PriceTable::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'Particular'])];
        $this->actingAs($this->admin->user);
        $this->post(route('price-tables.prices.store', $base), ['billing_item_id' => $item->id, 'price' => '100,05', 'valid_from' => '2026-01-01'])->assertSessionHasNoErrors();
        $this->post(route('price-tables.prices.store', $private), ['billing_item_id' => $item->id, 'price' => '250,00', 'valid_from' => '2026-01-01'])->assertSessionHasNoErrors();

        // No contract yet: no price for the insurer; no private table yet: none for the private patient.
        $this->assertSame([null, null], [InsurerContract::priceFor($insurer->id, $item->id, '2026-06-01'), InsurerContract::priceFor(null, $item->id, '2026-06-01')]);
        $this->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $base->id, 'adjustment' => '12,5'])->assertSessionHasNoErrors();
        $this->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $base->id, 'adjustment' => '2000'])->assertSessionHasErrorsIn("contract-{$insurer->id}", ['adjustment']);
        // 100,05 + 12,5% = 112,55625 → 112,56.
        $this->assertSame(11256, InsurerContract::priceFor($insurer->id, $item->id, '2026-06-01'));
        $this->assertEquals(['insurer_id' => $insurer->id, 'fields' => ['insurer_id', 'price_table_id', 'adjustment_bp'], 'price_table_id' => $base->id, 'adjustment_bp' => 1250], AuditEvent::where('action', 'insurer_contract.saved')->sole()->context);

        $this->post(route('price-tables.private', $private))->assertSessionHasNoErrors();
        $this->post(route('price-tables.private', $base))->assertSessionHasNoErrors();
        $this->post(route('price-tables.private', $private))->assertSessionHasNoErrors();
        $this->assertSame([$private->id], PriceTable::withoutTenantScope()->where('private', true)->pluck('id')->all());
        $this->assertSame(25000, InsurerContract::priceFor(null, $item->id, '2026-06-01'));

        $contract = InsurerContract::withoutTenantScope()->sole();
        $this->put(route('insurer-contracts.authorizations', $contract), ['billing_item_ids' => [$item->id]])->assertSessionHasNoErrors();
        $this->assertTrue(InsurerContract::needsAuthorization($insurer->id, $item->id));
        $this->put(route('insurer-contracts.authorizations', $contract), ['billing_item_ids' => []])->assertSessionHasNoErrors();
        $this->assertFalse(InsurerContract::needsAuthorization($insurer->id, $item->id));
        // A requirement on an inactive item (not on the screen) survives a save of the others.
        $this->put(route('insurer-contracts.authorizations', $contract), ['billing_item_ids' => [$item->id]])->assertSessionHasNoErrors();
        $this->put(route('billing-items.update', $item), ['name' => 'Consulta', 'active' => false])->assertSessionHasNoErrors();
        $this->put(route('insurer-contracts.authorizations', $contract), ['billing_item_ids' => []])->assertSessionHasNoErrors();
        $this->assertTrue(InsurerContract::needsAuthorization($insurer->id, $item->id));
        $this->put(route('billing-items.update', $item), ['name' => 'Consulta', 'active' => true])->assertSessionHasNoErrors();
        $this->put(route('insurer-contracts.authorizations', $contract), ['billing_item_ids' => []])->assertSessionHasNoErrors();
        // A negative adjustment: 100,05 - 10% = 90,045 → 90,05; -100% is free.
        $this->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $base->id, 'adjustment' => '-10'])->assertSessionHasNoErrors();
        $this->assertSame(9005, InsurerContract::priceFor($insurer->id, $item->id, '2026-06-01'));
        $this->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $base->id, 'adjustment' => '-100'])->assertSessionHasNoErrors();
        $this->assertSame(0, InsurerContract::priceFor($insurer->id, $item->id, '2026-06-01'));
        $this->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $base->id, 'adjustment' => '12,5'])->assertSessionHasNoErrors();
        // The private table is not deactivated; an inactive table does not become the private one.
        $this->put(route('price-tables.update', $private), ['name' => 'Particular', 'active' => false])->assertSessionHasErrorsIn("table-{$private->id}", ['active']);
        $this->put(route('price-tables.update', $base), ['name' => 'CBHPM', 'active' => false])->assertSessionHasNoErrors();
        $this->post(route('price-tables.private', $base))->assertSessionHasErrorsIn("table-{$base->id}", ['private']);
        $this->put(route('price-tables.update', $base), ['name' => 'CBHPM', 'active' => true])->assertSessionHasNoErrors();
        // Another network's items are refused in the list.
        app(CurrentTenant::class)->set(null);
        $foreignItem = BillingItem::withoutTenantScope()->forceCreate(['tenant_id' => $this->stranger->tenant_id, 'kind' => 'consultation', 'name' => 'Consulta']);
        $this->put(route('insurer-contracts.authorizations', $contract), ['billing_item_ids' => [$foreignItem->id]])->assertSessionHasErrorsIn("authorizations-{$contract->id}", ['billing_item_ids.0']);

        $this->get(route('billing-prices.index'))->assertInertia(fn (Assert $page) => $page->has('insurers', 1)
            ->where('insurers.0.contract.adjustmentBp', 1250)->where('insurers.0.contract.tableId', $base->id)->where('insurers.0.contract.authorizationItemIds', []));

        // Only the network admin; another network's insurer is not found; the database keeps one private table.
        $this->actingAs($manager->user)->put(route('insurer-contracts.save', $insurer), ['price_table_id' => $base->id])->assertForbidden();
        $this->post(route('price-tables.private', $base))->assertForbidden();
        $this->actingAs($this->admin->user);
        $this->put(route('insurer-contracts.save', $foreign), ['price_table_id' => $base->id])->assertNotFound();
        $this->expectException(QueryException::class);
        DB::table('price_tables')->whereKey($base->id)->update(['private' => true]);
    }

    public function test_prices_are_imported_by_csv_by_the_same_rule(): void
    {
        Storage::fake('local');
        $tenant = $this->admin->tenant;
        $table = PriceTable::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'name' => 'CBHPM']);
        $consult = BillingItem::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'kind' => 'consultation', 'name' => 'Consulta', 'code' => '10101012']);
        $icu = BillingItem::withoutTenantScope()->forceCreate(['tenant_id' => $tenant->id, 'kind' => 'daily', 'bed_type' => 'icu', 'name' => 'Diária de UTI']);
        $this->actingAs($this->admin->user)->post(route('price-tables.prices.store', $table), ['billing_item_id' => $icu->id, 'price' => '2.000,00', 'valid_from' => '2026-07-01'])->assertSessionHasNoErrors();
        $this->post(route('imports.store'), ['type' => 'prices', 'source_system' => 'Planilha', 'file' => UploadedFile::fake()->createWithContent('precos.csv', implode("\r\n", [
            'price_table;item_code;item_name;price;valid_from',
            'cbhpm;10101012;;150,00;01/01/2026',
            'CBHPM;;diária de uti;2.000,00;2026-07-01',
            'CBHPM;;Diária de UTI;1.800,00;2026-03-01',
            'Outra;10101012;;1,00;2026-01-01',
            'CBHPM;99999;;1,00;2026-01-01',
            'CBHPM;10101012;;12.34;2026-01-01',
            'CBHPM;10101012;;150,00;2026-01-01',
        ]))])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->assertSame([2 => 'ready', 3 => 'identical', 4 => 'conflict', 5 => 'rejected', 6 => 'rejected', 7 => 'rejected', 8 => 'rejected'], DB::table('import_items')->where('import_batch_id', $batch->id)->orderBy('line')->pluck('status', 'line')->all());
        Bus::fake();
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        Bus::assertDispatched(ApplyImportBatch::class, fn (ApplyImportBatch $job) => $job->handle(app(CurrentTenant::class)) === null);
        $this->assertSame(15000, $table->priceOn($consult->id, '2026-02-01'));
        $this->assertEquals(['price_table_id' => $table->id, 'billing_item_id' => $consult->id, 'price_cents' => 15000, 'valid_from' => '2026-01-01', 'import_batch_id' => $batch->id, 'line' => 2], AuditEvent::where('action', 'price_entry.created')->latest('id')->first()->context);
        // Not for a unit manager.
        $manager = Membership::factory()->for($tenant)->create();
        $manager->grant(Role::UnitManager, $this->unit);
        $this->actingAs($manager->user)->post(route('imports.confirm', $batch))->assertForbidden();
    }
}
