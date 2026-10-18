<?php

namespace Tests\Feature\Imports;

use App\Jobs\ApplyImportBatch;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\ImportIdentity;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfessionalImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = 'source_id;name;base_unit;council;council_number;council_uf;email;phone';

    private Membership $admin;

    private Unit $north;

    private Unit $south;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->north = Unit::factory()->for($this->admin->tenant)->create(['name' => 'Hospital Norte']);
        $this->south = Unit::factory()->for($this->admin->tenant)->create(['name' => 'Hospital Sul']);
    }

    /**
     * @param  list<string>  $lines
     */
    private function upload(array $lines, ?Membership $as = null, string $source = 'Planilha RH', ?string $header = null): TestResponse
    {
        $csv = implode("\r\n", [$header ?? self::HEADER, ...$lines]);

        return $this->actingAs(($as ?? $this->admin)->user)->post(route('imports.store'), [
            'source_system' => $source,
            'file' => UploadedFile::fake()->createWithContent('profissionais.csv', $csv),
        ]);
    }

    /**
     * Confirms through HTTP and runs the dispatched job here, independent of the queue driver.
     */
    private function confirm(ImportBatch $batch): TestResponse
    {
        Bus::fake();
        $response = $this->post(route('imports.confirm', $batch));
        Bus::assertDispatched(ApplyImportBatch::class, function (ApplyImportBatch $job) use ($batch) {
            $job->handle(app(CurrentTenant::class));

            return $job->batchId === $batch->id;
        });

        return $response;
    }

    private function latestBatch(): ImportBatch
    {
        return ImportBatch::withoutTenantScope()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, int>
     */
    private function counts(ImportBatch $batch): array
    {
        app(CurrentTenant::class)->set($this->admin->tenant);

        return $batch->counts();
    }

    public function test_preview_classifies_every_line_without_creating_anything(): void
    {
        $this->upload([
            'A1;Ana Sintética;hospital norte;crm;123;sp;Ana@Exemplo.test;(11) 90000-0000',
            'A2;;Hospital Norte;;;;;',
            'A3;Bruno Sintético;Hospital Inexistente;;;;;',
            'A1;Ana Duplicada;Hospital Norte;;;;;',
            'A5;Carla Sintética;Hospital Sul;CRM;123;SP;;',
        ])->assertRedirect();

        $batch = $this->latestBatch();
        $this->assertSame('previewed', $batch->status);
        $this->assertSame(5, $batch->received);
        $this->assertSame(['ready' => 1, 'created' => 0, 'identical' => 0, 'conflict' => 0, 'rejected' => 4], $this->counts($batch));
        $this->assertSame(0, Professional::withoutTenantScope()->count());
        Storage::disk('local')->assertExists($batch->path);
    }

    public function test_confirmation_creates_ready_lines_with_identity_and_audit(): void
    {
        $this->upload(['A1;Ana Sintética;Hospital Norte;crm;123;sp;Ana@Exemplo.test;']);
        $batch = $this->latestBatch();

        $this->confirm($batch)->assertRedirect();

        $professional = Professional::withoutTenantScope()->firstOrFail();
        $this->assertSame('Ana Sintética', $professional->name);
        $this->assertSame('CRM', $professional->council);
        $this->assertSame('ana@exemplo.test', $professional->email);
        $this->assertSame($this->north->id, $professional->base_unit_id);
        $this->assertSame('A1', $professional->external_id);
        $this->assertSame('applied', $batch->fresh()?->status);
        $this->assertSame(1, $this->counts($batch)['created']);
        $this->assertTrue(ImportIdentity::withoutTenantScope()->where('original_id', 'A1')->where('record_id', $professional->id)->exists());
        $this->assertSame($batch->id, AuditEvent::where('action', 'professional.created')->value('context')['import_batch_id'] ?? null);

        // Confirming again changes nothing.
        // The job clears the auth context it set (as on a long-lived worker), so authenticate again.
        $this->actingAs($this->admin->user)->post(route('imports.confirm', $batch))->assertSessionHasErrors('batch');
    }

    public function test_reimport_is_idempotent_and_changes_become_conflicts(): void
    {
        $this->upload(['A1;Ana Sintética;Hospital Norte;;;;;']);
        $this->confirm($this->latestBatch());

        $this->upload(['A1;Ana Sintética;Hospital Norte;;;;;', 'A1x;Outra;Hospital Norte;;;;;']);
        $this->assertSame(1, $this->counts($this->latestBatch())['identical']);

        $this->upload(['A1;Ana Renomeada;Hospital Sul;;;;;']);
        $batch = $this->latestBatch();
        $this->assertSame(1, $this->counts($batch)['conflict']);

        $this->assertSame(1, Professional::withoutTenantScope()->where('external_id', 'A1')->count());
        $this->assertSame('Ana Sintética', Professional::withoutTenantScope()->where('external_id', 'A1')->value('name'));
    }

    public function test_same_id_from_another_source_or_manual_registration_is_a_conflict(): void
    {
        Professional::factory()->create(['base_unit_id' => $this->north->id, 'council' => 'CRM', 'council_number' => '123', 'council_uf' => 'SP']);

        $this->upload(['B1;Ana Sintética;Hospital Norte;CRM;123;SP;;']);

        $this->assertSame(1, $this->counts($this->latestBatch())['conflict']);
    }

    public function test_applying_twice_never_duplicates(): void
    {
        $this->upload(['A1;Ana Sintética;Hospital Norte;;;;;', 'A2;Bruno Sintético;Hospital Sul;;;;;']);
        $batch = $this->latestBatch();
        ImportBatch::withoutTenantScope()->whereKey($batch->id)->update(['status' => 'applying']);

        (new ApplyImportBatch($batch->tenant_id, $batch->id))->handle(app(CurrentTenant::class));
        (new ApplyImportBatch($batch->tenant_id, $batch->id))->handle(app(CurrentTenant::class));

        $this->assertSame(2, Professional::withoutTenantScope()->count());
        $this->assertSame(2, $this->counts($batch)['created']);
    }

    public function test_file_level_errors_are_reported_and_nothing_is_stored(): void
    {
        $this->upload(['A1;Ana'], header: 'source_id;name;extra')->assertSessionHasErrors('file');
        $this->upload(["A1;Ana \xE9;Hospital Norte;;;;;"])->assertSessionHasErrors('file');
        $this->upload([])->assertSessionHasErrors('file');

        $this->assertSame(0, ImportBatch::withoutTenantScope()->count());
    }

    public function test_comma_delimiter_and_bom_are_accepted(): void
    {
        $this->upload(['A1,Ana Sintética,Hospital Norte,,,,,'], header: "\xEF\xBB\xBF".str_replace(';', ',', self::HEADER))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->counts($this->latestBatch())['ready']);
    }

    public function test_importer_only_imports_into_own_units_and_sees_own_batches(): void
    {
        $importer = Membership::factory()->for($this->admin->tenant)->create();
        $importer->grant(Role::Importer, $this->north);
        $manager = Membership::factory()->for($this->admin->tenant)->create();
        $manager->grant(Role::UnitManager, $this->north);
        $other = Membership::factory()->for($this->admin->tenant)->create();
        $other->grant(Role::Importer, $this->north);

        $this->upload(['A1;Ana Sintética;Hospital Norte;;;;;', 'A2;Bruno Sintético;Hospital Sul;;;;;'], $importer)->assertRedirect();
        $batch = $this->latestBatch();
        $this->assertSame(['ready' => 1, 'created' => 0, 'identical' => 0, 'conflict' => 0, 'rejected' => 1], $this->counts($batch));

        $this->actingAs($manager->user)->get(route('imports.index'))->assertForbidden();
        $this->upload(['A3;Carla;Hospital Norte;;;;;'], $manager)->assertForbidden();

        $this->actingAs($other->user)->get(route('imports.show', $batch))->assertForbidden();
        $this->actingAs($this->admin->user)->get(route('imports.show', $batch))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('counts.ready', 1)->has('items.data', 2));
    }

    public function test_lines_are_not_applied_if_the_importer_lost_access_after_the_preview(): void
    {
        $importer = Membership::factory()->for($this->admin->tenant)->create();
        $assignment = $importer->grant(Role::Importer, $this->north);
        $this->upload(['A1;Ana Sintética;Hospital Norte;;;;;'], $importer);
        $batch = $this->latestBatch();
        $importer->revokeRole($assignment);
        ImportBatch::withoutTenantScope()->whereKey($batch->id)->update(['status' => 'applying']);

        (new ApplyImportBatch($batch->tenant_id, $batch->id))->handle(app(CurrentTenant::class));

        $this->assertSame(0, Professional::withoutTenantScope()->count());
        $this->assertSame(1, $this->counts($batch)['conflict']);
    }

    public function test_formulas_are_rejected_and_quoted_line_breaks_are_kept(): void
    {
        $this->upload(['A1;=HYPERLINK("x");Hospital Norte;;;;;', "A2;\"Ana\nSintética\";Hospital Norte;;;;;"])->assertSessionHasNoErrors();
        $batch = $this->latestBatch();

        $this->assertSame(2, $batch->received);
        $this->assertSame(['ready' => 1, 'created' => 0, 'identical' => 0, 'conflict' => 0, 'rejected' => 1], $this->counts($batch));
    }

    public function test_batches_of_another_tenant_are_not_found(): void
    {
        $otherAdmin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->upload(['A1;Ana Sintética;Hospital Norte;;;;;']);
        $batch = $this->latestBatch();

        $this->actingAs($otherAdmin->user)->get(route('imports.show', $batch))->assertNotFound();
        $this->post(route('imports.confirm', $batch))->assertNotFound();
    }
}
