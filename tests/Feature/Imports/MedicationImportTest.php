<?php

namespace Tests\Feature\Imports;

use App\Jobs\ApplyImportBatch;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\Medication;
use App\Models\MedicationInteraction;
use App\Models\Membership;
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

/**
 * Step 4b.1b: the medication catalog and its interactions imported by CSV, with the V1 flow
 * (preview without writes, confirmation, resumable apply, nothing existing ever changed).
 */
class MedicationImportTest extends TestCase
{
    use RefreshDatabase;

    private const MEDICATIONS = 'active_ingredient;form;strength;dose_unit;routes;controlled;high_alert;max_single_dose;max_daily_dose';

    private const INTERACTIONS = 'ingredient_a;ingredient_b;severity;note';

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
        $csv = implode("\r\n", [$type === 'medications' ? self::MEDICATIONS : self::INTERACTIONS, ...$lines]);

        return $this->actingAs(($as ?? $this->admin)->user)->post(route('imports.store'), [
            'type' => $type, 'source_system' => 'Padronização da farmácia',
            'file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
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

    public function test_the_catalog_is_previewed_then_applied_without_changing_what_exists(): void
    {
        $this->actingAs($this->admin->user)->post(route('medications.store'), [
            'active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'max_daily_dose' => 4000,
        ])->assertSessionHasNoErrors();
        $this->post(route('medications.store'), [
            'active_ingredient' => 'Dipirona', 'form' => 'Solução injetável', 'strength' => '500 mg/mL', 'dose_unit' => 'mg', 'routes' => ['iv'], 'max_daily_dose' => 4000,
        ])->assertSessionHasNoErrors();

        $this->upload('medications', [
            'Dipirona;comprimido;500 mg;mg;oral;não;não;;4000',           // identical (case of the form ignored)
            'Dipirona;Solução injetável;500 mg/mL;mg;iv;não;não;;3000',   // conflict: different maximum
            'Heparina;Solução injetável;5.000 UI/mL;UI;iv|sc;não;sim;10000;40000',
            'Morfina;Solução injetável;10 mg/mL;mg;iv|sc|im;sim;sim;10,5;60',
            'Morfina;solução injetável;10 mg/mL;mg;iv;sim;sim;;',         // repeated in the file
            'Varfarina;Comprimido;5 mg;colher;boca;talvez;não;0,0001;',   // invalid unit, route, flag and dose
            '=HYPERLINK("x");Comprimido;1 mg;mg;oral;;;;',                // formula injection
            'Captopril;Comprimido;25 mg;mg;oral;;;100;50',                // daily below single
            'Enalapril;Comprimido;10 mg;MG;oral;;;10;1.000',              // "1.000": a thousand or one? refused
        ])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->assertSame(2, Medication::withoutTenantScope()->count());
        $this->assertSame(['ready' => 2, 'identical' => 1, 'conflict' => 1, 'rejected' => 5], array_intersect_key($batch->counts(), array_flip(['ready', 'identical', 'conflict', 'rejected'])));

        $this->confirm($batch);
        $this->assertSame('applied', $batch->fresh()->status);
        $morphine = Medication::withoutTenantScope()->where('ingredient_key', 'morfina')->sole();
        $this->assertSame([['iv', 'sc', 'im'], true, true, '10.500', '60.000'], [$morphine->routes, $morphine->controlled, $morphine->high_alert, $morphine->max_single_dose, $morphine->max_daily_dose]);
        $this->assertSame(['4000.000', '4000.000'], Medication::withoutTenantScope()->where('ingredient_key', 'dipirona')->pluck('max_daily_dose')->all());
        $this->assertStringContainsString('ambíguo', implode(' ', $batch->items()->where('line', 10)->sole()->messages));
        $this->assertSame(2, AuditEvent::where('action', 'medication.created')->whereNotNull('context->import_batch_id')->count());

        // Applying again changes nothing.
        (new ApplyImportBatch($batch->tenant_id, $batch->id))->handle(app(CurrentTenant::class));
        $this->assertSame(4, Medication::withoutTenantScope()->count());
    }

    public function test_interactions_need_both_ingredients_in_the_catalog(): void
    {
        $this->upload('medications', ['Heparina;Solução injetável;5.000 UI/mL;UI;iv|sc;;sim;;', 'Varfarina;Comprimido;5 mg;mg;oral;;sim;;'])->assertRedirect();
        $this->confirm(ImportBatch::withoutTenantScope()->sole());

        $this->upload('medication_interactions', [
            'Varfarina;heparina;Grave;"Risco de sangramento; monitorar coagulação."',
            'heparina;varfarina;moderate;Repetido em outra ordem.',
            'Varfarina;Amiodarona;grave;Fora do catálogo.',
            'Varfarina;Varfarina;grave;Igual.',
            'Heparina;Varfarina;forte;Gravidade inválida.',
        ])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->latest('id')->firstOrFail();
        $this->assertSame(['ready' => 1, 'rejected' => 4], array_filter(array_intersect_key($batch->counts(), array_flip(['ready', 'identical', 'conflict', 'rejected']))));
        $this->confirm($batch);

        $interaction = MedicationInteraction::withoutTenantScope()->sole();
        $this->assertSame(['heparina', 'varfarina', 'severe', 'Risco de sangramento; monitorar coagulação.', $this->admin->user_id], [$interaction->ingredient_a, $interaction->ingredient_b, $interaction->severity, $interaction->note, $interaction->created_by_user_id]);

        // An unquoted separator inside the note would cut it: the whole file is refused instead.
        $this->upload('medication_interactions', ['Varfarina;Heparina;grave;Risco; corte'])->assertSessionHasErrors('file');
        $this->actingAs($this->admin->user)->get(route('imports.show', $batch))->assertInertia(fn (Assert $page) => $page->where('items.data.0.name', 'heparina + varfarina'));
    }

    public function test_only_who_keeps_the_catalog_imports_it(): void
    {
        app(CurrentTenant::class)->set(null);
        $importer = Membership::factory()->for($this->admin->tenant)->create();
        $importer->grant(Role::Importer, Unit::factory()->for($this->admin->tenant)->create());
        $this->upload('medications', ['Dipirona;Comprimido;500 mg;mg;oral;;;;'], $importer)->assertSessionHasErrors('type');
        $this->actingAs($importer->user)->get(route('imports.create'))->assertInertia(fn (Assert $page) => $page->missing('columns.medications')->has('columns.professionals'));

        // A batch previewed by the admin cannot be confirmed by someone who lost the role; the job refuses too.
        $this->upload('medications', ['Dipirona;Comprimido;500 mg;mg;oral;;;;'])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->admin->roleAssignments()->where('role', Role::NetworkAdmin)->delete();
        $this->admin->grant(Role::Importer, Unit::factory()->for($this->admin->tenant)->create());
        app(CurrentTenant::class)->set(null);
        $this->actingAs($this->admin->user)->post(route('imports.confirm', $batch))->assertForbidden();
        (new ApplyImportBatch($batch->tenant_id, $batch->id))->handle(app(CurrentTenant::class));
        $this->assertSame(0, Medication::withoutTenantScope()->count());
        $this->assertSame('conflict', $batch->items()->sole()->status);
    }
}
