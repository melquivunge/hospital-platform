<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\BillingAccountItem;
use App\Models\BillingItem;
use App\Models\ClinicalDocument;
use App\Models\EmergencyEpisode;
use App\Models\Exam;
use App\Models\ExamAnalyte;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\ExamResult;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Exam results (step 5.4, ADR-0008): released by the team of the item's type after the collection, signed with the
 * password, never changed; a rectification is the next version with its reason. The imaging report needs a CRM.
 * The release ends the team's access to the record and tells the orderer without clinical data.
 */
class ExamResultTest extends ClinicalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private Visit $visit;

    private Membership $lab;

    private Membership $imaging;

    private Membership $otherLab;

    private ExamOrder $order;

    /** @var array<string, ExamAnalyte> */
    private array $analytes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app(CurrentTenant::class)->set($this->patient->tenant);
        $blood = Exam::query()->create(['type' => 'laboratory', 'name' => 'Hemograma', 'specimen' => 'Sangue']);
        $xray = Exam::query()->create(['type' => 'imaging', 'name' => 'Raio X de tórax', 'specimen' => 'Tórax']);
        $this->analytes['hb'] = $blood->analytes()->create(['name' => 'Hemoglobina', 'unit' => 'g/dL', 'reference_min' => 12, 'reference_max' => 16, 'position' => 1]);
        $this->analytes['leu'] = $blood->analytes()->create(['name' => 'Leucócitos', 'unit' => '/mm³', 'reference_min' => 4000, 'reference_max' => 11000, 'position' => 2]);
        $this->analytes['hiv'] = $blood->analytes()->create(['name' => 'Anti-HIV', 'reference_text' => 'Não reagente', 'position' => 3]);
        app(CurrentTenant::class)->set(null);
        foreach (['lab' => [Role::Laboratory, $this->unit], 'imaging' => [Role::Imaging, $this->unit], 'otherLab' => [Role::Laboratory, $this->other]] as $property => [$role, $unit]) {
            $this->{$property} = Membership::factory()->for($this->unit->tenant)->create();
            $this->{$property}->grant($role, $unit);
        }

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Febre.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
        $this->post(route('clinical-documents.store', $this->visit), ['file' => UploadedFile::fake()->createWithContent('ecg.pdf', self::PDF)])->assertSessionHasNoErrors();
        $this->post(route('exam-orders.visit.store', $this->visit), ['exam_ids' => [$blood->id, $xray->id], 'priority' => 'routine', 'indication' => 'Febre.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->order = ExamOrder::withoutTenantScope()->sole();
    }

    private function item(string $type): ExamOrderItem
    {
        return ExamOrderItem::withoutGlobalScopes()->where('exam_order_id', $this->order->id)->where('exam_type', $type)->sole();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data = []): array
    {
        return [
            'version' => 1, 'password' => 'password', 'report' => 'Sem atipias.',
            'values' => [$this->analytes['hb']->id => '13,5', $this->analytes['leu']->id => '15200', $this->analytes['hiv']->id => 'Não reagente'],
            ...$data,
        ];
    }

    public function test_the_laboratory_releases_once_after_the_collection_and_its_access_to_the_record_ends(): void
    {
        $item = $this->item('laboratory');
        $bag = "result{$item->id}";
        $this->actingAs($this->lab->user)->post(route('exam-results.release', $item), $this->payload())
            ->assertSessionHasErrorsIn($bag, ['report' => 'Registre a coleta antes do resultado.']);
        try {
            DB::transaction(fn () => DB::table('exam_results')->insert([
                'tenant_id' => $item->tenant_id, 'patient_id' => $this->order->patient_id, 'exam_order_id' => $this->order->id, 'exam_order_item_id' => $item->id,
                'version' => 1, 'report' => 'Antes da coleta.', 'released_by_user_id' => $this->lab->user_id, 'signature_method' => 'password', 'released_at' => now(),
            ]));
            $this->fail('The database must refuse a result before the collection.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        $this->post(route('exam-queue.collect', $item))->assertSessionHasNoErrors();
        $this->post(route('exam-results.release', $item), $this->payload(['password' => 'errada', 'file' => UploadedFile::fake()->createWithContent('a.pdf', self::PDF)]))->assertSessionHasErrorsIn($bag, ['password']);
        $this->assertSame([], Storage::disk('local')->allFiles("clinical/{$item->tenant_id}/exam-results"));
        $this->post(route('exam-results.release', $this->item('imaging')), $this->payload())->assertForbidden();
        $this->post(route('exam-results.release', $item), $this->payload(['values' => [$this->analytes['hb']->id + 1000 => '1']]))
            ->assertSessionHasErrorsIn($bag, ['report' => 'Um dos valores não pertence a este exame.']);
        $this->post(route('exam-results.release', $item), ['version' => 1, 'password' => 'password'])
            ->assertSessionHasErrorsIn($bag, ['report' => 'Informe ao menos um valor, as observações ou o PDF.']);
        $this->post(route('exam-results.release', $item), $this->payload(['file' => UploadedFile::fake()->createWithContent('laudo.pdf', '<html>not a pdf</html>')]))
            ->assertSessionHasErrorsIn($bag, ['file' => 'O conteúdo do arquivo não é um PDF válido.']);
        $this->assertSame(0, ExamResult::withoutGlobalScopes()->count());

        $this->post(route('exam-results.release', $item), $this->payload(['file' => UploadedFile::fake()->createWithContent('hemograma.pdf', self::PDF)]))
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Resultado liberado.');
        // A second release of version 1, as from a stale screen, is refused.
        $this->post(route('exam-results.release', $item), $this->payload())
            ->assertSessionHasErrorsIn($bag, ['report' => 'Este resultado já foi liberado por outra pessoa. Confira antes de retificar.']);
        $result = ExamResult::withoutGlobalScopes()->sole();
        $this->assertSame([['13.5000', null], ['15200.0000', 'high'], ['Não reagente', null]],
            $result->values()->withoutGlobalScopes()->get()->map(fn ($value) => [$value->value_numeric ?? $value->value_text, $value->flag])->all());
        // Ids and the version only: no exam, value or patient name.
        $this->assertEquals(['unit_id' => $this->unit->id, 'exam_order_id' => $this->order->id, 'version' => 1, 'critical' => false, 'author_user_id' => $this->doctorAccount->user_id, 'patient_id' => $this->patient->id],
            AuditEvent::where('action', 'exam_result.released')->sole()->context);

        (new DeliverNotifications)->handle();
        $this->assertSame([[$this->doctorAccount->user_id, 'Um resultado de exame que você pediu foi liberado', "/patients/{$this->patient->id}/record"]],
            DB::table('user_notifications')->get()->map(fn ($row) => [$row->user_id, $row->title, $row->url])->all());

        // The doctor reads it on the record, with the flag and the reference as released.
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->has('examResults', 1)->where('examResults.0.name', 'Hemograma')->where('examResults.0.versions.0.values.1', ['analyteId' => $this->analytes['leu']->id, 'name' => 'Leucócitos', 'value' => '15200', 'unit' => '/mm³', 'reference' => '4000 a 11000', 'flag' => 'high'])
            ->where('examResults.0.versions.0.values.0.value', '13,5')->where('examResults.0.versions.0.attachment.name', 'hemograma.pdf'));
        $this->get(route('exam-results.download', $result))->assertOk();

        // The laboratory: out of the queue, into the released list; the order and the result, never the record again.
        $document = ClinicalDocument::withoutGlobalScopes()->sole();
        $this->actingAs($this->lab->user)->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->has('orders', 0)->has('released', 1));
        $this->get(route('exam-queue.show', $this->order))->assertInertia(fn (Assert $page) => $page->where('open', false)->where('record', [])
            ->where('documents', [])->where('prescriptions', null)->where('items.0.results.0.version', 1)->where('items.0.nextVersion', 2));
        $this->get(route('clinical-documents.download', $document))->assertForbidden();
        $this->get(route('exam-results.download', $result))->assertOk();
        // The imaging item still waits: the imaging team keeps the record.
        $this->actingAs($this->imaging->user)->get(route('exam-queue.show', $this->order))->assertInertia(fn (Assert $page) => $page->where('open', true));
        $this->get(route('clinical-documents.download', $document))->assertOk();
        // Another unit's laboratory reaches neither.
        $this->actingAs($this->otherLab->user)->post(route('exam-results.release', $item), $this->payload(['version' => 2, 'reason' => 'Erro.']))->assertForbidden();
        $this->get(route('exam-results.download', $result))->assertForbidden();
        $this->actingAs($this->reception->user)->get(route('exam-results.download', $result))->assertForbidden();

        // Never changed nor deleted in the database, and no value added to it.
        $extra = fn () => DB::table('exam_result_values')->insert(['tenant_id' => $result->tenant_id, 'exam_result_id' => $result->id, 'exam_analyte_id' => $this->analytes['hb']->id,
            'analyte_name' => 'Extra', 'value_text' => 'Inserido depois']);
        $this->actingAs($this->reception->user)->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Rui Lima', 'birth_date' => '1970-05-06', 'sex' => 'male'])->assertSessionHasNoErrors();
        $rui = Patient::withoutTenantScope()->where('name', 'Rui Lima')->sole();
        // A result naming another patient than the order's.
        $otherPatient = fn () => DB::table('exam_results')->insert([...collect($result->getAttributes())->except(['id', 'version', 'patient_id', 'value_count'])->all(),
            'version' => 2, 'rectification_reason' => 'Outro paciente.', 'patient_id' => $rui->id]);
        foreach ([fn () => DB::table('exam_results')->update(['report' => 'Outro.']), fn () => DB::table('exam_result_values')->delete(), fn () => DB::table('exam_results')->delete(), $extra, $otherPatient] as $change) {
            try {
                // A savepoint each, so one refusal does not abort the next attempt.
                DB::transaction($change);
                $this->fail('A released result must never change.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_a_released_exam_is_billed_once_on_the_day_of_its_first_release(): void
    {
        $item = $this->item('laboratory');
        app(CurrentTenant::class)->set($this->unit->tenant);
        $table = PriceTable::query()->create(['name' => 'Particular', 'private' => true]);
        $billable = BillingItem::query()->create(['kind' => 'exam', 'exam_id' => $item->exam_id, 'name' => 'Hemograma completo', 'code' => '40304361']);
        PriceEntry::query()->create(['price_table_id' => $table->id, 'billing_item_id' => $billable->id, 'price_cents' => 2500, 'valid_from' => '2026-01-01', 'created_by_user_id' => $this->manager->user_id, 'created_at' => now()]);
        $account = BillingAccount::query()->sole();
        // Collected, not released: nothing to bill.
        app(CurrentTenant::class)->set(null);
        $this->actingAs($this->lab->user)->post(route('exam-queue.collect', $item))->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertSame(0, $account->generate());

        app(CurrentTenant::class)->set(null);
        // The release bills it at once; the rectification, a day later, bills nothing more.
        $this->post(route('exam-results.release', $item), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(1, BillingAccountItem::withoutTenantScope()->count());
        $this->travelTo('2026-11-14 12:00:00');
        $this->post(route('exam-results.release', $item), $this->payload(['version' => 2, 'reason' => 'Leucócitos digitados errado.']))->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertSame(0, $account->generate());
        $billed = BillingAccountItem::query()->sole();
        $this->assertSame(['exam_order_item', $item->id, '40304361', 'Hemograma completo', 2500, '2026-11-13'],
            [$billed->origin_type, $billed->origin_id, $billed->code, $billed->name, $billed->unit_price_cents, $billed->performed_on->toDateString()]);
    }

    public function test_a_rectification_is_the_next_version_with_a_reason_and_the_previous_stays(): void
    {
        $item = $this->item('laboratory');
        $bag = "result{$item->id}";
        $this->actingAs($this->lab->user)->post(route('exam-queue.collect', $item))->assertSessionHasNoErrors();
        $this->post(route('exam-results.release', $item), $this->payload(['file' => UploadedFile::fake()->createWithContent('hemograma.pdf', self::PDF)]))->assertSessionHasNoErrors();

        $this->post(route('exam-results.release', $item), $this->payload(['version' => 2]))->assertSessionHasErrorsIn($bag, ['reason' => 'Informe o motivo da retificação.']);
        $this->post(route('exam-results.release', $item), $this->payload(['version' => 3, 'reason' => 'Erro.']))
            ->assertSessionHasErrorsIn($bag, ['report' => 'O resultado mudou enquanto você editava. Confira a versão atual.']);
        $this->post(route('exam-results.release', $item), $this->payload(['version' => 2, 'reason' => 'Leucócitos digitados errado.', 'values' => [$this->analytes['leu']->id => '9800']]))
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Resultado retificado.');

        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->has('examResults.0.versions', 2)->where('examResults.0.versions.0.version', 2)->where('examResults.0.versions.0.reason', 'Leucócitos digitados errado.')
            ->where('examResults.0.versions.0.values', [['analyteId' => $this->analytes['leu']->id, 'name' => 'Leucócitos', 'value' => '9800', 'unit' => '/mm³', 'reference' => '4000 a 11000', 'flag' => null]])
            // The PDF stays unless replaced or removed.
            ->where('examResults.0.versions.0.attachment.name', 'hemograma.pdf')
            ->where('examResults.0.versions.1.values.1.flag', 'high'));
        $this->actingAs($this->lab->user)->post(route('exam-results.release', $item), $this->payload(['version' => 3, 'reason' => 'PDF de outro exame.', 'remove_attachment' => '1']))->assertSessionHasNoErrors();
        $this->assertNull(ExamResult::withoutGlobalScopes()->where('version', 3)->sole()->attachment_path);
        $this->assertNotNull(ExamResult::withoutGlobalScopes()->where('version', 1)->sole()->attachment_path);
        // The team reopens it while recent, as the "released" list; never an old order by id (security review 5.6).
        $this->travel(29)->days();
        $this->actingAs($this->lab->user)->get(route('exam-queue.show', $this->order))->assertOk();
        $this->travel(2)->days();
        $this->get(route('exam-queue.show', $this->order))->assertForbidden();
        $this->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->has('released', 0));

        (new DeliverNotifications)->handle();
        $this->assertSame(['Um resultado de exame que você pediu foi liberado', 'Um resultado de exame que você pediu foi retificado', 'Um resultado de exame que você pediu foi retificado'], DB::table('user_notifications')->orderBy('id')->pluck('title')->all());
    }

    public function test_values_are_numbers_with_a_flag_or_text(): void
    {
        $item = $this->item('laboratory');
        $this->actingAs($this->lab->user)->post(route('exam-queue.collect', $item))->assertSessionHasNoErrors();
        $this->post(route('exam-results.release', $item), $this->payload(['values' => [$this->analytes['hb']->id => '9,8', $this->analytes['leu']->id => '<4000', $this->analytes['hiv']->id => ' ']]))->assertSessionHasNoErrors();

        $this->assertSame([['9.8000', null, 'low'], [null, '<4000', null]], ExamResult::withoutGlobalScopes()->sole()->values()->withoutGlobalScopes()->get()
            ->map(fn ($value) => [$value->value_numeric, $value->value_text, $value->flag])->all());
    }

    public function test_the_imaging_report_needs_a_crm(): void
    {
        $item = $this->item('imaging');
        $this->actingAs($this->imaging->user)->post(route('exam-queue.collect', $item))->assertSessionHasNoErrors();
        $this->get(route('exam-queue.show', $this->order))->assertInertia(fn (Assert $page) => $page->where('items.0.canSign', false));
        $this->post(route('exam-results.release', $item), ['version' => 1, 'report' => 'Normal.', 'password' => 'password'])->assertForbidden();

        // A nurse's council is not enough.
        $professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'council' => 'COREN', 'council_number' => '123', 'council_uf' => 'SP']);
        $professional->forceFill(['membership_id' => $this->imaging->id])->save();
        $this->post(route('exam-results.release', $item), ['version' => 1, 'report' => 'Normal.', 'password' => 'password'])->assertForbidden();

        // A CRM without an approved qualification valid in the unit is not enough either (security review 5.6).
        $professional->forceFill(['council' => 'CRM'])->save();
        $this->post(route('exam-results.release', $item), ['version' => 1, 'report' => 'Normal.', 'password' => 'password'])->assertForbidden();
        app(CurrentTenant::class)->set($this->patient->tenant);
        Qualification::request($professional, $this->unit, ['job_function_id' => JobFunction::factory()->for($this->unit->tenant)->create()->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);
        $this->get(route('exam-queue.show', $this->order))->assertInertia(fn (Assert $page) => $page->where('items.0.canSign', true));
        $this->post(route('exam-results.release', $item), ['version' => 1, 'password' => 'password'])
            ->assertSessionHasErrorsIn("result{$item->id}", ['report' => 'Escreva o laudo ou anexe o PDF.']);
        // Values are never kept for imaging.
        $this->post(route('exam-results.release', $item), ['version' => 1, 'report' => 'Campos pulmonares livres.', 'values' => [$this->analytes['hb']->id => '1'], 'password' => 'password'])->assertSessionHasNoErrors();
        $result = ExamResult::withoutGlobalScopes()->sole();
        $this->assertSame($professional->id, $result->professional_id);
        $this->assertSame(0, $result->values()->withoutGlobalScopes()->count());
    }
}
