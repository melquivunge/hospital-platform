<?php

namespace Tests\Feature\Clinical;

use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Exam;
use App\Models\ExamOrder;
use App\Models\Sector;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Exam orders (step 5.2): the professional who attends orders exams of the catalog with a priority and the clinical
 * indication, signed with the password; only the author cancels, with a reason.
 */
class ExamOrderTest extends ClinicalTestCase
{
    /** @var array<string, Exam> */
    private array $exams = [];

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set($this->patient->tenant);
        $this->exams['blood'] = Exam::query()->create(['type' => 'laboratory', 'name' => 'Hemograma', 'specimen' => 'Sangue']);
        $this->exams['xray'] = Exam::query()->create(['type' => 'imaging', 'name' => 'Raio X de tórax', 'specimen' => 'Tórax']);
        $this->exams['old'] = Exam::query()->create(['type' => 'laboratory', 'name' => 'Antigo', 'specimen' => 'Sangue', 'active' => false]);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Febre.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    private function order(array $overrides = []): array
    {
        return ['exam_ids' => [$this->exams['xray']->id, $this->exams['blood']->id], 'priority' => 'urgent', 'indication' => ' Febre persistente. ', 'password' => 'password', ...$overrides];
    }

    public function test_the_attending_doctor_orders_exams_signed_with_the_password(): void
    {
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('canOrder', true));
        $this->get(route('exam-orders.visit', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->component('exam-orders/index')->has('catalog', 2)->has('orders', 0));
        $this->assertTrue(DB::table('clinical_reads')->where('user_id', $this->doctorAccount->user_id)->where('visit_id', $this->visit->id)->exists());

        $this->post(route('exam-orders.visit.store', $this->visit), $this->order(['password' => 'wrong']))->assertSessionHasErrorsIn('order', 'password');
        $this->post(route('exam-orders.visit.store', $this->visit), $this->order(['exam_ids' => [$this->exams['old']->id]]))->assertSessionHasErrorsIn('order', 'exam_ids.0');
        $this->post(route('exam-orders.visit.store', $this->visit), $this->order(['exam_ids' => []]))->assertSessionHasErrorsIn('order', 'exam_ids');
        $this->assertSame(0, ExamOrder::withoutTenantScope()->count());

        $this->post(route('exam-orders.visit.store', $this->visit), $this->order())->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Pedido de exames feito.');
        $order = ExamOrder::withoutTenantScope()->sole();
        $this->assertSame(['urgent', 'Febre persistente.', 'requested', $this->doctor->id, $this->unit->id], [$order->priority, $order->indication, $order->status, $order->professional_id, $order->unit_id]);
        $this->assertSame(['Raio X de tórax', 'Hemograma'], $order->items()->withoutGlobalScopes()->pluck('exam_name')->all());
        $audit = AuditEvent::where('action', 'exam_order.created')->sole();
        $this->assertEquals(['unit_id' => $this->unit->id, 'items' => 2, 'priority' => 'urgent'], $audit->context);
    }

    public function test_only_who_attends_orders_and_only_the_author_cancels_with_a_reason(): void
    {
        $this->actingAs($this->nurseAccount->user)->get(route('exam-orders.visit', $this->visit))->assertForbidden();
        $this->post(route('exam-orders.visit.store', $this->visit), $this->order())->assertForbidden();

        $this->actingAs($this->doctorAccount->user)->post(route('exam-orders.visit.store', $this->visit), $this->order())->assertSessionHasNoErrors();
        $order = ExamOrder::withoutTenantScope()->sole();

        $this->actingAs($this->nurseAccount->user)->post(route('exam-orders.cancel', $order), ['reason' => 'Engano.'])->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->post(route('exam-orders.cancel', $order), ['reason' => ' '])->assertSessionHasErrorsIn("cancel{$order->id}", 'reason');
        $this->post(route('exam-orders.cancel', $order), ['reason' => 'Pedido em duplicidade.'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'Pedido em duplicidade.'], [$order->fresh()->status, $order->fresh()->cancellation_reason]);
        $this->post(route('exam-orders.cancel', $order), ['reason' => 'De novo.'])->assertSessionHasErrorsIn("cancel{$order->id}", ['reason' => 'Este pedido não pode mais ser cancelado.']);
        $this->assertSame(1, AuditEvent::where('action', 'exam_order.cancelled')->count());

        try {
            DB::table('exam_orders')->update(['cancellation_reason' => null]);
            $this->fail('A cancelled order without a reason must be refused.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
    }

    public function test_the_order_follows_the_encounter_into_the_admission_and_the_author_still_cancels(): void
    {
        $this->actingAs($this->doctorAccount->user)->post(route('exam-orders.visit.store', $this->visit), $this->order())->assertSessionHasNoErrors();
        $first = ExamOrder::withoutTenantScope()->sole();
        $sector = Sector::withoutGlobalScopes()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $sector->id, 'room' => '1', 'code' => 'L1', 'type' => 'ward'])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user);
        $this->post(route('visits.close', $this->visit), ['outcome' => 'admission_requested', 'requested_sector_id' => $sector->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->sole();

        // The closed visit takes no more orders; its author still cancels what is left open.
        $this->get(route('exam-orders.visit', $this->visit))->assertForbidden();
        $this->post(route('exam-orders.visit.store', $this->visit), $this->order())->assertForbidden();
        $this->post(route('exam-orders.cancel', $first), ['reason' => 'Será pedido na internação.'])->assertSessionHasNoErrors();

        $this->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->where('canOrder', true));
        $this->get(route('exam-orders.admission', $admission))->assertInertia(fn (Assert $page) => $page->where('encounter.type', 'admission')->has('orders', 0));
        $this->post(route('exam-orders.admission.store', $admission), $this->order(['exam_ids' => [$this->exams['blood']->id], 'priority' => 'routine']))->assertSessionHasNoErrors();
        $order = ExamOrder::withoutTenantScope()->latest('id')->firstOrFail();
        $this->assertSame([$admission->id, null, 'routine'], [$order->admission_id, $order->visit_id, $order->priority]);
    }

    public function test_a_function_that_does_not_attend_patients_does_not_order_and_a_wrong_password_is_audited(): void
    {
        $this->actingAs($this->doctorAccount->user)->post(route('exam-orders.visit.store', $this->visit), $this->order(['password' => 'wrong']))->assertSessionHasErrorsIn('order', 'password');
        $this->assertSame(1, AuditEvent::where('action', 'exam_order.sign_failed')->count());

        DB::table('job_functions')->update(['attends_patients' => false]);
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('canOrder', false));
        $this->get(route('exam-orders.visit', $this->visit))->assertForbidden();
        $this->post(route('exam-orders.visit.store', $this->visit), $this->order())->assertForbidden();
        $this->assertSame(0, ExamOrder::withoutTenantScope()->count());
    }
}
