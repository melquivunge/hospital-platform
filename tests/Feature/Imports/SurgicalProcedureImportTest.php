<?php

namespace Tests\Feature\Imports;

use App\Jobs\ApplyImportBatch;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\Membership;
use App\Models\SurgicalProcedure;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Step 5b.1b: the surgical procedures imported by CSV, with the V1 flow (preview without writes, confirmation,
 * resumable apply, nothing existing ever changed).
 */
class SurgicalProcedureImportTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
    }

    /**
     * @param  list<string>  $lines
     */
    private function upload(array $lines, ?Membership $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->admin)->user)->post(route('imports.store'), [
            'type' => 'surgical_procedures', 'source_system' => 'Sistema antigo',
            'file' => UploadedFile::fake()->createWithContent('procedimentos.csv', implode("\r\n", ['procedure_name;duration_minutes;size;code', ...$lines])),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function statuses(ImportBatch $batch): array
    {
        return DB::table('import_items')->where('import_batch_id', $batch->id)->orderBy('line')->pluck('status', 'line')->all();
    }

    public function test_procedures_are_previewed_and_applied_without_changing_what_exists(): void
    {
        $this->actingAs($this->admin->user)->post(route('surgical-procedures.store'), ['name' => 'Apendicectomia', 'duration_minutes' => 60, 'size' => 'medium'])->assertSessionHasNoErrors();
        $this->post(route('surgical-procedures.store'), ['name' => 'Herniorrafia inguinal', 'duration_minutes' => 90, 'size' => 'medium'])->assertSessionHasNoErrors();
        $this->upload([
            'Colecistectomia videolaparoscópica;120;Grande;31005497',
            'APENDICECTOMIA;+60;medio;',
            'Herniorrafia inguinal;45;pequeno;',
            'Biópsia;3;pequeno;',
            'Drenagem;30;enorme;',
            '=Cesariana;60;médio;',
            'Artroscopia;1,5;médio;',
            'colecistectomia videolaparoscópica;120;grande;',
            'Sutura;5;pequeno;-A1',
            'Cesariana;1440;médio;',
        ])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->assertSame([2 => 'ready', 3 => 'identical', 4 => 'conflict', 5 => 'rejected', 6 => 'rejected', 7 => 'rejected', 8 => 'rejected', 9 => 'rejected', 10 => 'rejected', 11 => 'ready'], $this->statuses($batch));
        // Registered on the screen between preview and apply: a conflict, nothing changed.
        $this->post(route('surgical-procedures.store'), ['name' => 'Cesariana', 'duration_minutes' => 50, 'size' => 'medium'])->assertSessionHasNoErrors();
        $this->assertSame(['Procedimento já cadastrado com valores diferentes (duração, porte). Nada foi alterado.'], json_decode((string) DB::table('import_items')->where('line', 4)->value('messages'), true));
        $this->assertSame(3, DB::table('surgical_procedures')->count());

        Bus::fake();
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        Bus::assertDispatched(ApplyImportBatch::class, function (ApplyImportBatch $job) use ($batch) {
            $job->handle(app(CurrentTenant::class));
            $job->handle(app(CurrentTenant::class));

            return $job->batchId === $batch->id;
        });
        $procedure = SurgicalProcedure::withoutTenantScope()->where('name_key', 'colecistectomia videolaparoscópica')->sole();
        $this->assertSame([120, 'large', '31005497', true], [$procedure->duration_minutes, $procedure->size, $procedure->code, $procedure->active]);
        $this->assertSame(4, DB::table('surgical_procedures')->count());
        $this->assertSame('conflict', $this->statuses($batch)[11]);
        $this->assertSame(50, SurgicalProcedure::withoutTenantScope()->where('name', 'Cesariana')->value('duration_minutes'));
        $this->assertEquals(['size' => 'large', 'import_batch_id' => $batch->id, 'line' => 2], AuditEvent::where('action', 'surgical_procedure.created')->where('subject_id', $procedure->id)->sole()->context);
        $this->assertSame('applied', $batch->fresh()->status);
    }

    public function test_only_who_keeps_the_network_catalog_imports_procedures(): void
    {
        $manager = Membership::factory()->for($this->admin->tenant)->create();
        $manager->grant(Role::UnitManager, Unit::factory()->for($this->admin->tenant)->create());
        $this->upload(['Apendicectomia;60;médio;'], $manager)->assertForbidden();
        $this->assertSame(0, DB::table('import_batches')->count());
        $this->upload(['Apendicectomia;60;médio;'])->assertRedirect();
        $this->actingAs($manager->user)->post(route('imports.confirm', ImportBatch::withoutTenantScope()->sole()))->assertForbidden();
        DB::table('import_items')->delete();
        DB::table('import_batches')->delete();

        // Losing the admin role after the preview stops the apply.
        $this->upload(['Apendicectomia;60;médio;'])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->admin->roleAssignments()->delete();
        $this->admin->grant(Role::Importer, Unit::factory()->for($this->admin->tenant)->create());
        (new ApplyImportBatch($batch->tenant_id, $batch->id))->handle(app(CurrentTenant::class));
        $this->assertSame([2 => 'conflict'], $this->statuses($batch));
        $this->assertSame(0, DB::table('surgical_procedures')->count());
    }
}
