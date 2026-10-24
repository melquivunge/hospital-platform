<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\ClinicalDocument;
use App\Models\EmergencyEpisode;
use App\Models\Exam;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\Prescription;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The exam queues and the execution (step 5.3, ADR-0008): the Laboratory and the Imaging roles see the open orders
 * of their type in their unit, read the record while the order is open, and collect or perform each item once.
 */
class ExamQueueTest extends ClinicalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private Visit $visit;

    private Membership $lab;

    private Membership $imaging;

    private Membership $otherLab;

    private ExamOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app(CurrentTenant::class)->set($this->patient->tenant);
        $blood = Exam::query()->create(['type' => 'laboratory', 'name' => 'Hemograma', 'specimen' => 'Sangue']);
        $xray = Exam::query()->create(['type' => 'imaging', 'name' => 'Raio X de tórax', 'specimen' => 'Tórax']);
        app(CurrentTenant::class)->set(null);
        foreach (['lab' => [Role::Laboratory, $this->unit], 'imaging' => [Role::Imaging, $this->unit], 'otherLab' => [Role::Laboratory, $this->other]] as $property => [$role, $unit]) {
            $this->{$property} = Membership::factory()->for($this->unit->tenant)->create();
            $this->{$property}->grant($role, $unit);
        }

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
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

    public function test_each_team_sees_its_type_in_its_unit_without_a_read_and_urgent_orders_come_first(): void
    {
        $this->travel(2)->hours();
        $this->post(route('exam-orders.visit.store', $this->visit), ['exam_ids' => [$this->item('laboratory')->exam_id], 'priority' => 'urgent', 'indication' => 'Piora.', 'password' => 'password'])->assertSessionHasNoErrors();
        $urgent = ExamOrder::withoutTenantScope()->latest('id')->firstOrFail();
        $this->travel(61)->minutes();

        $this->actingAs($this->lab->user)->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->component('exam-queue/index')
            ->where('types', ['laboratory' => 'Laboratório'])->has('orders', 2)
            ->where('orders.0.id', $urgent->id)->where('orders.0.late', true)->where('orders.0.patient', 'Joana Prado')->where('orders.0.place', 'Urgência')
            ->where('orders.1.id', $this->order->id)->where('orders.1.late', false)->where('orders.1.items', [['id' => $this->item('laboratory')->id, 'name' => 'Hemograma', 'type' => 'laboratory', 'collected' => false]]));
        $this->actingAs($this->imaging->user)->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->has('orders', 1)->where('orders.0.items.0.name', 'Raio X de tórax'));
        $this->actingAs($this->otherLab->user)->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->has('orders', 0));
        $this->actingAs($this->nurseAccount->user)->get(route('exam-queue.index'))->assertForbidden();
        $this->assertSame(0, DB::table('clinical_reads')->where('reason', 'exam')->count());
    }

    public function test_the_team_reads_the_record_and_collects_each_item_once(): void
    {
        $this->actingAs($this->lab->user)->get(route('exam-queue.show', $this->order))->assertInertia(fn (Assert $page) => $page->component('exam-queue/show')
            ->has('items', 1)->where('items.0.name', 'Hemograma')->has('documents', 1)->where('patient.name', 'Joana Prado')
            // The encounter's prescriptions, read-only.
            ->has('prescriptions.previous')->where('prescriptions.canPrescribe', false)->where('prescriptions.canSuspend', false)->where('prescriptions.administration', null));
        $this->assertSame(1, DB::table('clinical_reads')->where('user_id', $this->lab->user_id)->where('reason', 'exam')->where('exam_order_id', $this->order->id)->count());
        $this->get(route('clinical-documents.download', ClinicalDocument::withoutGlobalScopes()->sole()))->assertOk();

        $this->post(route('exam-queue.collect', $this->item('imaging')))->assertForbidden();
        $this->post(route('exam-queue.collect', $this->item('laboratory')))->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Coleta registrada.');
        $this->post(route('exam-queue.collect', $this->item('laboratory')))->assertSessionHasErrors(['collect' => 'Este exame já foi coletado.']);
        $this->assertSame($this->lab->user_id, $this->item('laboratory')->collected_by_user_id);
        $this->assertEquals(['unit_id' => $this->unit->id, 'exam_order_id' => $this->order->id], AuditEvent::where('action', 'exam_order_item.collected')->sole()->context);

        $this->actingAs($this->imaging->user)->post(route('exam-queue.collect', $this->item('imaging')))->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Realização registrada.');
        $this->actingAs($this->lab->user)->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->where('orders.0.items.0.collected', true));

        // Collected: the doctor no longer cancels, and the item never changes again.
        $this->actingAs($this->doctorAccount->user)->post(route('exam-orders.cancel', $this->order), ['reason' => 'Engano.'])
            ->assertSessionHasErrorsIn("cancel{$this->order->id}", ['reason' => 'Este pedido já tem exame coletado ou realizado e não pode mais ser cancelado.']);
        try {
            DB::table('exam_order_items')->where('id', $this->item('laboratory')->id)->update(['collected_at' => now()->subHour()]);
            $this->fail('A collected item must never change.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
    }

    public function test_another_unit_a_revoked_role_and_a_cancelled_order_close_the_record(): void
    {
        $document = ClinicalDocument::withoutGlobalScopes()->sole();
        $this->actingAs($this->otherLab->user)->get(route('exam-queue.show', $this->order))->assertForbidden();
        $this->post(route('exam-queue.collect', $this->item('laboratory')))->assertForbidden();
        $this->get(route('clinical-documents.download', $document))->assertForbidden();

        $this->lab->revokeRole($this->lab->roleAssignments()->withoutGlobalScopes()->sole());
        $this->actingAs($this->lab->user)->get(route('exam-queue.show', $this->order))->assertForbidden();

        $this->actingAs($this->imaging->user)->get(route('clinical-documents.download', $document))->assertOk();
        $this->actingAs($this->doctorAccount->user)->post(route('exam-orders.cancel', $this->order), ['reason' => 'Pedido em duplicidade.'])->assertSessionHasNoErrors();
        $this->actingAs($this->imaging->user)->get(route('exam-queue.show', $this->order))->assertForbidden();
        $this->post(route('exam-queue.collect', $this->item('imaging')))->assertSessionHasErrors(['collect' => 'Este pedido foi cancelado.']);
        $this->get(route('clinical-documents.download', $document))->assertForbidden();
        $this->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->has('orders', 0));
    }

    public function test_the_laboratory_never_reaches_an_imaging_only_order_and_a_revoked_account_reaches_nothing(): void
    {
        $this->actingAs($this->doctorAccount->user)->post(route('exam-orders.cancel', $this->order), ['reason' => 'Refeito.'])->assertSessionHasNoErrors();
        $this->post(route('exam-orders.visit.store', $this->visit), ['exam_ids' => [$this->item('imaging')->exam_id], 'priority' => 'routine', 'indication' => 'Tosse.', 'password' => 'password'])->assertSessionHasNoErrors();
        $imagingOnly = ExamOrder::withoutTenantScope()->latest('id')->firstOrFail();
        $item = ExamOrderItem::withoutGlobalScopes()->where('exam_order_id', $imagingOnly->id)->sole();
        $document = ClinicalDocument::withoutGlobalScopes()->sole();

        $this->actingAs($this->lab->user)->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->has('orders', 0));
        $this->get(route('exam-queue.show', $imagingOnly))->assertForbidden();
        $this->post(route('exam-queue.collect', $item))->assertForbidden();
        $this->get(route('clinical-documents.download', $document))->assertForbidden();
        $this->actingAs($this->reception->user)->get(route('clinical-documents.download', $document))->assertForbidden();

        $this->actingAs($this->imaging->user)->get(route('exam-queue.show', $imagingOnly))->assertOk();
        $this->imaging->revoke();
        $this->get(route('exam-queue.show', $imagingOnly))->assertForbidden();
        $this->post(route('exam-queue.collect', $item))->assertForbidden();
        $this->assertNull($item->fresh()->collected_at);
    }

    public function test_the_network_admin_sets_the_target_times_of_the_queues(): void
    {
        $admin = Membership::factory()->for($this->unit->tenant)->create();
        $admin->grant(Role::NetworkAdmin);
        $this->actingAs($admin->user)->get(route('exams.index'))->assertInertia(fn (Assert $page) => $page
            ->where('targets', ['exam_target_urgent_minutes' => 60, 'exam_target_routine_minutes' => 1440]));
        $this->put(route('exams.targets'), ['exam_target_urgent_minutes' => 0, 'exam_target_routine_minutes' => 10081])
            ->assertSessionHasErrorsIn('targets', ['exam_target_urgent_minutes', 'exam_target_routine_minutes']);
        $this->actingAs($this->lab->user)->put(route('exams.targets'), ['exam_target_urgent_minutes' => 30, 'exam_target_routine_minutes' => 30])->assertForbidden();

        $this->travel(31)->minutes();
        $this->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->where('orders.0.late', false));
        // The routine order waits 31 minutes: late past a 30-minute routine target, not under the urgent one.
        $this->actingAs($admin->user)->put(route('exams.targets'), ['exam_target_urgent_minutes' => 20, 'exam_target_routine_minutes' => 40])
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Tempos-alvo atualizados.');
        $this->assertEquals(['fields' => ['exam_target_urgent_minutes', 'exam_target_routine_minutes']], AuditEvent::where('action', 'exam_targets.updated')->sole()->context);
        $this->actingAs($this->lab->user)->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->where('orders.0.late', false));
        $this->actingAs($admin->user)->put(route('exams.targets'), ['exam_target_urgent_minutes' => 20, 'exam_target_routine_minutes' => 30])->assertSessionHasNoErrors();
        $this->put(route('exams.targets'), ['exam_target_urgent_minutes' => 20, 'exam_target_routine_minutes' => 30])->assertSessionHasNoErrors();
        $this->assertSame(2, AuditEvent::where('action', 'exam_targets.updated')->count());
        $this->actingAs($this->lab->user)->get(route('exam-queue.index'))->assertInertia(fn (Assert $page) => $page->where('orders.0.late', true));
    }

    public function test_the_execution_shows_the_signed_prescriptions_of_the_encounter_read_only(): void
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->forceFill(['council' => 'CRM', 'council_number' => '123456', 'council_uf' => 'SP'])->save();
        $medication = Medication::query()->create(['active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'], 'controlled' => false, 'high_alert' => false]);
        app(CurrentTenant::class)->set(null);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.prescriptions.start', $this->visit))->assertRedirect();
        $prescription = Prescription::withoutTenantScope()->where('status', 'draft')->sole();
        $this->post(route('prescriptions.items.store', $prescription), ['medication_id' => $medication->id, 'dose' => '500', 'route' => 'oral', 'schedule' => 'interval', 'frequency_hours' => 6, 'prn' => '0'])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertSessionHasNoErrors();
        // A new draft of the doctor stays out of the exam team's view.
        $this->post(route('visits.prescriptions.start', $this->visit))->assertRedirect();

        $this->actingAs($this->lab->user)->get(route('exam-queue.show', $this->order))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.id', $prescription->id)->where('prescriptions.active.items.0.medication', fn (string $name) => str_contains($name, 'Dipirona'))
            ->where('prescriptions.active.canCertify', false)->where('prescriptions.draftId', null)->where('prescriptions.canPrescribe', false));
    }
}
