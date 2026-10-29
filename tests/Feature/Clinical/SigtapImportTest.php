<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\BillingItem;
use App\Models\Membership;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use ZipArchive;

/**
 * Step 6b.3 (ADR-0011): the network admin imports the official SIGTAP zip of a competence (a cut of the real 10/2026
 * table, tests/Fixtures/sigtap), read through the layouts it ships; a billable item carries its SIGTAP code.
 */
class SigtapImportTest extends ClinicalTestCase
{
    /**
     * A zip of the fixture files, without the ones named, with some contents replaced.
     *
     * @param  list<string>  $without
     * @param  array<string, string>  $replaced
     */
    private function zip(array $without = [], array $replaced = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sigtap').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach (glob(base_path('tests/Fixtures/sigtap/*.txt')) ?: [] as $file) {
            if (isset($replaced[basename($file)])) {
                $zip->addFromString(basename($file), $replaced[basename($file)]);
            } elseif (! in_array(basename($file), $without, true)) {
                $zip->addFile($file, basename($file));
            }
        }
        $zip->close();

        return new UploadedFile($path, 'TabelaUnificada_202610_v2610271043.zip', 'application/zip', null, true);
    }

    private function admin(): Membership
    {
        return Membership::query()->withoutGlobalScopes()->where('tenant_id', $this->unit->tenant_id)
            ->whereHas('roleAssignments', fn ($query) => $query->where('role', Role::NetworkAdmin->value))->firstOrFail();
    }

    public function test_the_official_zip_of_a_competence_is_imported_and_replaced(): void
    {
        // Only the network admin.
        $this->actingAs($this->manager->user)->get(route('sigtap.index'))->assertForbidden();
        $this->post(route('sigtap.store'), ['file' => $this->zip()])->assertForbidden();

        $this->actingAs($this->admin()->user);
        $this->post(route('sigtap.store'), ['file' => UploadedFile::fake()->createWithContent('tabela.csv', "a;b\n")])->assertSessionHasErrors('file');
        $this->post(route('sigtap.store'), ['file' => $this->zip(['rl_procedimento_cid_layout.txt'])])
            ->assertSessionHasErrors(['file' => 'O ZIP não tem rl_procedimento_cid.txt e o seu layout: use o arquivo oficial da SIGTAP (TabelaUnificada).']);
        // A layout without a column the import reads is refused with words, never a crash.
        $layout = (string) file_get_contents(base_path('tests/Fixtures/sigtap/tb_procedimento_layout.txt'));
        $this->post(route('sigtap.store'), ['file' => $this->zip([], ['tb_procedimento_layout.txt' => (string) preg_replace('/^VL_SH,.*$/m', '', $layout)])])
            ->assertSessionHasErrors(['file' => 'O layout de tb_procedimento não tem a coluna VL_SH como na tabela oficial.']);
        // Step 6b.8: far more lines than the official table (5,039 procedures) is refused before it fills the memory.
        $line = strtok((string) file_get_contents(base_path('tests/Fixtures/sigtap/tb_procedimento.txt')), "\n")."\n";
        $this->post(route('sigtap.store'), ['file' => $this->zip([], ['tb_procedimento.txt' => str_repeat($line, 50_001)])])
            ->assertSessionHasErrors(['file' => 'tb_procedimento.txt tem linhas demais para a tabela oficial.']);
        $this->assertSame(0, DB::table('sigtap_competences')->count());

        $this->post(route('sigtap.store'), ['file' => $this->zip()])->assertSessionHasNoErrors()->assertRedirect(route('sigtap.index'))
            ->assertInertiaFlash('toast.message', 'SIGTAP 10/2026 importada: 3 procedimentos.');
        $competence = DB::table('sigtap_competences')->sole();
        $this->assertSame(['2026-10-01', 3, 'TabelaUnificada_202610_v2610271043.zip'], [$competence->competence, $competence->procedure_count, $competence->file_name]);
        $procedures = DB::table('sigtap_procedures')->get()->keyBy('code');
        // The name read in ISO-8859-1 by byte position: accents and the following columns stay in place.
        $consultation = $procedures['0301010072'];
        $this->assertSame(['CONSULTA MEDICA EM ATENÇÃO ESPECIALIZADA', 'I', 0, 1571, 0, 1000, 0, '{01,02,07}'], [
            $consultation->name, $consultation->sex, $consultation->min_age_months, $consultation->max_age_months,
            $consultation->sh_cents, $consultation->sa_cents, $consultation->sp_cents, $consultation->registers,
        ]);
        $pneumonia = $procedures['0303140151'];
        $this->assertSame([50407, 7835, '{03}'], [$pneumonia->sh_cents, $pneumonia->sp_cents, $pneumonia->registers]);
        $this->assertStringContainsString('225125', $consultation->cbos);
        $this->assertNotSame('{}', $pneumonia->cids);
        $this->assertEquals(['competence' => '2026-10', 'procedures' => 3, 'sha256' => $competence->file_sha256, 'replaced' => false],
            AuditEvent::where('action', 'sigtap.imported')->sole()->context);
        $this->get(route('sigtap.index'))->assertInertia(fn (Assert $page) => $page->component('sigtap/index')
            ->where('competences.0.competence', '10/2026')->where('competences.0.procedures', 3));

        // DATASUS republishes a competence: importing it again replaces it.
        $this->post(route('sigtap.store'), ['file' => $this->zip()])->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'SIGTAP 10/2026 substituída: 3 procedimentos.');
        $this->assertSame([1, 3], [DB::table('sigtap_competences')->count(), DB::table('sigtap_procedures')->count()]);
    }

    public function test_a_billable_item_carries_its_sigtap_code(): void
    {
        $this->actingAs($this->admin()->user);
        $this->post(route('billing-items.store'), ['kind' => 'consultation', 'name' => 'Consulta', 'code' => '10101012', 'sigtap_code' => '0301'])
            ->assertSessionHasErrorsIn('newItem', ['sigtap_code' => 'O código SIGTAP tem 10 dígitos.']);
        $this->post(route('billing-items.store'), ['kind' => 'consultation', 'name' => 'Consulta', 'code' => '10101012', 'sigtap_code' => '0301010072'])->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $item = BillingItem::query()->sole();
        $this->assertSame('0301010072', $item->sigtap_code);
        $this->put(route('billing-items.update', $item), ['name' => 'Consulta', 'code' => '10101012', 'sigtap_code' => '', 'active' => true])->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->sigtap_code);
    }
}
