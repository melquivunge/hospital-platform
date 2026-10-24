<?php

namespace Tests\Feature\Imports;

use App\Jobs\ApplyImportBatch;
use App\Models\Exam;
use App\Models\ImportBatch;
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
use Tests\TestCase;

/**
 * Step 5.1b: the exam catalog and the analytes of its laboratory exams imported by CSV, with the V1 flow
 * (preview without writes, confirmation, resumable apply, nothing existing ever changed).
 */
class ExamImportTest extends TestCase
{
    use RefreshDatabase;

    private const EXAMS = 'exam_type;exam_name;specimen;preparation;code';

    private const ANALYTES = 'exam_name;analyte;analyte_unit;reference_min;reference_max;reference_text';

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
    private function upload(string $type, array $lines, ?Membership $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->admin)->user)->post(route('imports.store'), [
            'type' => $type, 'source_system' => 'Sistema antigo',
            'file' => UploadedFile::fake()->createWithContent('exames.csv', implode("\r\n", [$type === 'exams' ? self::EXAMS : self::ANALYTES, ...$lines])),
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
        // The job applies as the batch's creator and signs out.
        $this->actingAs($this->admin->user);
    }

    /**
     * @return array<int, string>
     */
    private function statuses(ImportBatch $batch): array
    {
        return DB::table('import_items')->where('import_batch_id', $batch->id)->orderBy('line')->pluck('status', 'line')->all();
    }

    public function test_exams_then_analytes_are_previewed_and_applied_without_changing_what_exists(): void
    {
        $this->actingAs($this->admin->user)->post(route('exams.store'), ['type' => 'imaging', 'name' => 'Raio X de tórax', 'specimen' => 'Tórax'])->assertSessionHasNoErrors();
        $this->post(route('exams.store'), ['type' => 'imaging', 'name' => 'Ultrassom de abdome', 'specimen' => 'Abdome'])->assertSessionHasNoErrors();
        $this->upload('exams', [
            'laboratório;Hemograma completo;Sangue;;40304361',
            'Imagem;raio x de tórax;Tórax;;',
            'imagem;Ultrassom de abdome;Pelve;;',
            'cirurgia;Apendicectomia;Abdome;;',
            'laboratorio;=Glicose;Sangue;;',
            'laboratorio;Hemograma completo;Sangue;;',
        ])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->assertSame([2 => 'ready', 3 => 'identical', 4 => 'conflict', 5 => 'rejected', 6 => 'rejected', 7 => 'rejected'], $this->statuses($batch));
        $this->assertSame(2, DB::table('exams')->count());
        $this->confirm($batch);
        $hemogram = Exam::withoutTenantScope()->where('name', 'Hemograma completo')->sole();
        $this->assertSame(['laboratory', 'Sangue', '40304361'], [$hemogram->type, $hemogram->specimen, $hemogram->code]);

        $this->travel(1)->minutes();
        $this->upload('exam_analytes', [
            'Hemograma completo;Hemoglobina;g/dL;12;16;',
            'hemograma completo;Leucócitos;/mm³;4000;11000;Adultos',
            'Hemograma completo;Plaquetas;/mm³;450000;150000;',
            'Raio X de tórax;Opacidade;;;;',
            'Hemograma completo;hemoglobina;g/dL;12;16;',
            'Hemograma completo;Hematócrito;%;1.000;;',
        ])->assertRedirect();
        $analytes = ImportBatch::withoutTenantScope()->latest('id')->firstOrFail();
        $this->assertSame([2 => 'ready', 3 => 'ready', 4 => 'rejected', 5 => 'rejected', 6 => 'rejected', 7 => 'rejected'], $this->statuses($analytes));
        $this->confirm($analytes);
        $this->assertSame([['Hemoglobina', 1, '16.0000'], ['Leucócitos', 2, '11000.0000']], DB::table('exam_analytes')->orderBy('position')->get()->map(fn ($row) => [$row->name, $row->position, $row->reference_max])->all());

        // The same file again: identical, nothing doubled; different values are a conflict.
        $this->travel(1)->minutes();
        $this->upload('exam_analytes', ['Hemograma completo;Hemoglobina;g/dL;12;16;', 'Hemograma completo;Leucócitos;/mm³;3500;11000;Adultos'])->assertRedirect();
        $this->assertSame([2 => 'identical', 3 => 'conflict'], $this->statuses(ImportBatch::withoutTenantScope()->latest('id')->firstOrFail()));
        $this->assertSame(2, DB::table('exam_analytes')->count());
    }

    public function test_only_who_keeps_the_network_catalog_imports_exams(): void
    {
        $manager = Membership::factory()->for($this->admin->tenant)->create();
        $manager->grant(Role::UnitManager, Unit::factory()->for($this->admin->tenant)->create());
        $this->upload('exams', ['laboratório;Glicose;Sangue;;'], $manager)->assertForbidden();
        $this->upload('exam_analytes', ['Glicose;Glicose;mg/dL;70;99;'], $manager)->assertForbidden();
        $this->assertSame(0, DB::table('import_batches')->count());
    }
}
