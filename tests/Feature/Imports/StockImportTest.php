<?php

namespace Tests\Feature\Imports;

use App\Jobs\ApplyImportBatch;
use App\Models\ImportBatch;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 4c.1b (ADR-0007): the initial stock balance imported by CSV by the unit's pharmacy, with the V1 flow
 * (preview without writes, confirmation, resumable apply) and no balance entered twice on reprocessing.
 */
class StockImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'source_id;unit;active_ingredient;form;strength;lot_code;expires_on;quantity;manufacturer';

    private Membership $admin;

    private Membership $pharmacist;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-13 12:00:00');
        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $this->admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['name' => 'Hospital Norte', 'timezone' => 'America/Sao_Paulo']);
        Unit::factory()->for($tenant)->create(['name' => 'Hospital Sul', 'timezone' => 'America/Sao_Paulo']);
        $this->pharmacist = Membership::factory()->for($tenant)->create();
        $this->pharmacist->grant(Role::Pharmacy, $this->unit);
        app(CurrentTenant::class)->set($tenant);
        Medication::query()->create(['active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral']]);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * @param  list<string>  $lines
     */
    private function upload(array $lines, ?Membership $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->pharmacist)->user)->post(route('imports.store'), [
            'type' => 'stock', 'source_system' => 'Sistema antigo',
            'file' => UploadedFile::fake()->createWithContent('saldo.csv', implode("\r\n", [self::HEADER, ...$lines])),
        ]);
    }

    private function confirm(ImportBatch $batch): void
    {
        Bus::fake();
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        Bus::assertDispatched(ApplyImportBatch::class, function (ApplyImportBatch $job) use ($batch) {
            $job->handle(app(CurrentTenant::class));

            return $job->batchId === $batch->id;
        });
    }

    /**
     * @return array<int, string> line => status
     */
    private function statuses(ImportBatch $batch): array
    {
        return DB::table('import_items')->where('import_batch_id', $batch->id)->orderBy('line')->pluck('status', 'line')->all();
    }

    public function test_the_balance_is_previewed_applied_and_never_entered_twice(): void
    {
        $file = [
            '1;hospital norte;Dipirona;comprimido;500 MG;ab 1;31/05/2027;100;EMS',
            '2;Hospital Norte;Dipirona;Comprimido;500 mg;AB1;2027-05-31;20;',
            '3;Hospital Norte;Dipirona;Comprimido;500 mg;X2;2026-11-12;5;',
            '4;Hospital Norte;Captopril;Comprimido;25 mg;X3;2027-01-01;5;',
            '5;Hospital Sul;Dipirona;Comprimido;500 mg;X4;2027-01-01;5;',
            '1;Hospital Norte;Dipirona;Comprimido;500 mg;X5;2027-01-01;5;',
            '7;Hospital Norte;Dipirona;Comprimido;500 mg;X6;2027-01-01;0;',
        ];
        $this->upload($file)->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->assertSame([2 => 'ready', 3 => 'ready', 4 => 'rejected', 5 => 'rejected', 6 => 'rejected', 7 => 'rejected', 8 => 'rejected'], $this->statuses($batch));
        // The preview writes nothing to the stock.
        $this->assertSame(0, DB::table('stock_movements')->count());

        $this->confirm($batch);
        $lot = DB::table('stock_lots')->sole();
        $this->assertSame(['AB1', 120, 'EMS', $this->unit->id], [$lot->lot_code, $lot->balance, $lot->manufacturer, $lot->unit_id]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'stock.entry')->whereJsonContains('context->import_batch_id', $batch->id)->whereJsonContains('context->line', 2)->count());

        // The same file again: already imported, nothing entered. A different manufacturer for the lot is a conflict.
        $this->travel(1)->minutes();
        $this->upload([...array_slice($file, 0, 2), '9;Hospital Norte;Dipirona;Comprimido;500 mg;AB1;2027-05-31;3;Medley'])->assertRedirect();
        $again = ImportBatch::withoutTenantScope()->latest('id')->firstOrFail();
        $this->assertSame([2 => 'identical', 3 => 'identical', 4 => 'conflict'], $this->statuses($again));
        $this->confirm($again);
        $this->assertSame(120, DB::table('stock_lots')->sole()->balance);
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_only_the_pharmacist_who_previewed_it_moves_the_stock(): void
    {
        // The pharmacy imports only the stock; the network admin does not import stock at all.
        $this->actingAs($this->pharmacist->user)->get(route('imports.create'))->assertInertia(fn (Assert $page) => $page->has('columns', 1)->has('columns.stock'));
        $this->actingAs($this->admin->user)->get(route('imports.create'))->assertInertia(fn (Assert $page) => $page->missing('columns.stock'));
        $this->upload(['1;Hospital Norte;Dipirona;Comprimido;500 mg;L1;2027-01-01;10;'], $this->admin)->assertSessionHasErrors('type');

        $this->upload(['1;Hospital Norte;Dipirona;Comprimido;500 mg;L1;2027-01-01;10;'])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->actingAs($this->admin->user)->post(route('imports.confirm', $batch))->assertForbidden();

        // The role revoked between the preview and the apply: nothing moves.
        DB::table('role_assignments')->where('membership_id', $this->pharmacist->id)->where('role', 'pharmacy')->delete();
        $this->pharmacist->grant(Role::Importer, $this->unit);
        $this->actingAs($this->pharmacist->user)->get(route('imports.show', $batch))->assertForbidden();
        Bus::fake();
        (new ApplyImportBatch($batch->tenant_id, $batch->id))->handle(app(CurrentTenant::class));
        $this->assertSame([2 => 'conflict'], $this->statuses($batch));
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_dates_ids_per_unit_changed_content_and_changes_after_the_preview(): void
    {
        $south = Unit::withoutTenantScope()->where('name', 'Hospital Sul')->sole();
        $this->pharmacist->grant(Role::Pharmacy, $south);
        $this->upload([
            '1;Hospital Norte;Dipirona;Comprimido;500 mg;L1;31/02/2027;10;',
            '2;Hospital Norte;Dipirona;Comprimido;500 mg;L2;2027-01-01;10;',
            '3;Hospital Norte;Dipirona;Comprimido;500 mg;L3;2026-11-14;10;',
            '4;Hospital Norte;Dipirona;Comprimido;500 mg;L4;2027-01-01;10;',
        ])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->assertSame([2 => 'rejected', 3 => 'ready', 4 => 'ready', 5 => 'ready'], $this->statuses($batch));
        // Between the preview and the apply, line 4 expires.
        $this->travelTo('2026-11-15 12:00:00');
        $this->confirm($batch);
        $this->assertSame([2 => 'rejected', 3 => 'created', 4 => 'conflict', 5 => 'created'], $this->statuses($batch));
        $this->assertSame(['O lote venceu depois da prévia.'], json_decode((string) DB::table('import_items')->where('import_batch_id', $batch->id)->where('line', 4)->value('messages'), true));

        // The same source id in another unit is another line; in the same unit with other data, a conflict.
        $this->upload([
            '2;Hospital Sul;Dipirona;Comprimido;500 mg;L2;2027-01-01;10;',
            '2;Hospital Norte;Dipirona;Comprimido;500 mg;L2;2027-01-01;99;',
        ])->assertRedirect();
        $again = ImportBatch::withoutTenantScope()->latest('id')->firstOrFail();
        $this->assertSame([2 => 'ready', 3 => 'conflict'], $this->statuses($again));

        // The medication deactivated between the preview and the apply.
        app(CurrentTenant::class)->set($this->admin->tenant);
        Medication::query()->update(['active' => false]);
        app(CurrentTenant::class)->set(null);
        $this->confirm($again);
        $this->assertSame([2 => 'conflict', 3 => 'conflict'], $this->statuses($again));
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_each_role_imports_only_its_types(): void
    {
        foreach (['professionals', 'shifts'] as $type) {
            $this->actingAs($this->pharmacist->user)->post(route('imports.store'), [
                'type' => $type, 'source_system' => 'X', 'file' => UploadedFile::fake()->createWithContent('a.csv', "source_id\r\n1"),
            ])->assertSessionHasErrors('type');
        }
        $importer = Membership::factory()->for($this->admin->tenant)->create();
        $importer->grant(Role::Importer, $this->unit);
        $this->upload(['1;Hospital Norte;Dipirona;Comprimido;500 mg;L1;2027-01-01;10;'], $importer)->assertSessionHasErrors('type');
        // The pharmacist's template is the stock one.
        $this->assertStringContainsString(self::HEADER, $this->actingAs($this->pharmacist->user)->get(route('imports.template'))->streamedContent());
        $this->assertSame(0, DB::table('import_batches')->count());
    }
}
