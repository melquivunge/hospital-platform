<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Exam;
use App\Models\ExamCriticalAlert;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\ExamResult;
use App\Models\Membership;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Critical results (step 5.5, ADR-0008): whoever releases marks it; the orderer hears it highlighted and
 * acknowledges it on the result; without that in 1 hour the unit manager hears it, once per result.
 */
class ExamCriticalTest extends ClinicalTestCase
{
    private Visit $visit;

    private Membership $lab;

    private ExamOrderItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set($this->patient->tenant);
        $potassium = Exam::query()->create(['type' => 'laboratory', 'name' => 'Potássio', 'specimen' => 'Sangue']);
        app(CurrentTenant::class)->set(null);
        $this->lab = Membership::factory()->for($this->unit->tenant)->create();
        $this->lab->grant(Role::Laboratory, $this->unit);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Fraqueza.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
        $this->post(route('exam-orders.visit.store', $this->visit), ['exam_ids' => [$potassium->id], 'priority' => 'urgent', 'indication' => 'Fraqueza.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->item = ExamOrderItem::withoutGlobalScopes()->where('exam_order_id', ExamOrder::withoutTenantScope()->sole()->id)->sole();
        $this->actingAs($this->lab->user)->post(route('exam-queue.collect', $this->item))->assertSessionHasNoErrors();
    }

    private function releaseCritical(): ExamResult
    {
        $this->actingAs($this->lab->user)->post(route('exam-results.release', $this->item), ['version' => 1, 'report' => 'Potássio 7,1 mEq/L.', 'critical' => '1', 'password' => 'password'])->assertSessionHasNoErrors();

        return ExamResult::withoutGlobalScopes()->sole();
    }

    /**
     * @return list<array{int, string, bool}>
     */
    private function inbox(): array
    {
        (new DeliverNotifications)->handle();

        return DB::table('user_notifications')->orderBy('id')->get()->map(fn ($row) => [$row->user_id, $row->title, (bool) $row->urgent])->all();
    }

    public function test_the_orderer_hears_it_highlighted_and_only_who_ordered_or_attends_acknowledges_once(): void
    {
        $result = $this->releaseCritical();
        $this->assertTrue($result->critical);
        $this->assertSame([[$this->doctorAccount->user_id, 'Resultado crítico de um exame que você pediu: registre a ciência', true]], $this->inbox());
        $this->actingAs($this->doctorAccount->user)->get(route('notifications.index'));
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('urgentNotifications', 1)
            ->where('examResults.0.versions.0.critical', true)->where('examResults.0.versions.0.canAcknowledge', true)->where('examResults.0.versions.0.acknowledgedAt', null));

        // Neither the laboratory that released it, nor the nurse, nor the manager.
        foreach ([$this->lab, $this->nurseAccount, $this->manager] as $stranger) {
            $this->actingAs($stranger->user)->post(route('exam-results.acknowledge', $result))->assertForbidden();
        }
        $this->actingAs($this->lab->user)->get(route('exam-queue.show', ExamOrder::withoutTenantScope()->sole()))->assertInertia(fn (Assert $page) => $page->where('items.0.results.0.canAcknowledge', false));

        $this->actingAs($this->doctorAccount->user)->post(route('exam-results.acknowledge', $result))->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Ciência registrada.');
        $this->post(route('exam-results.acknowledge', $result))->assertSessionHasErrors(["acknowledge{$result->id}" => 'A ciência deste resultado já foi registrada.']);
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('urgentNotifications', 0)->where('examResults.0.versions.0.acknowledgedBy', $this->doctorAccount->user->name)
            ->where('examResults.0.versions.0.canAcknowledge', false));
        $this->assertEquals(['unit_id' => $this->unit->id, 'escalated' => false], AuditEvent::where('action', 'exam_result.critical_acknowledged')->sole()->context);

        // An acknowledged alert never escalates, and never changes again.
        $this->travel(2)->hours();
        $this->artisan('exams:critical')->assertSuccessful();
        $this->assertSame(0, AuditEvent::where('action', 'exam_result.critical_unacknowledged')->count());
        foreach ([fn () => DB::table('exam_critical_alerts')->update(['acknowledged_at' => null, 'acknowledged_by_user_id' => null]), fn () => DB::table('exam_critical_alerts')->delete()] as $change) {
            try {
                DB::transaction($change);
                $this->fail('An acknowledgement is never undone.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_without_an_acknowledgement_in_an_hour_the_manager_hears_it_once(): void
    {
        $result = $this->releaseCritical();
        $this->travel(59)->minutes();
        $this->artisan('exams:critical')->assertSuccessful();
        $this->assertSame(0, AuditEvent::where('action', 'exam_result.critical_unacknowledged')->count());

        $this->travel(2)->minutes();
        $this->artisan('exams:critical')->assertSuccessful();
        $this->artisan('exams:critical')->assertSuccessful();
        $alert = ExamCriticalAlert::withoutGlobalScopes()->sole();
        $this->assertEquals(['unit_id' => $this->unit->id, 'exam_result_id' => $result->id], AuditEvent::where('action', 'exam_result.critical_unacknowledged')->sole()->context);
        $this->assertSame([
            [$this->doctorAccount->user_id, 'Resultado crítico de um exame que você pediu: registre a ciência', true],
            [$this->manager->user_id, 'Resultado crítico de exame sem ciência há mais de 60 minutos', true],
        ], $this->inbox());

        // The manager: where, when and who ordered; never the exam or the value.
        $this->actingAs($this->manager->user)->get(route('exam-overdue.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page
            ->has('critical', 1)->where('critical.0.id', $alert->id)->where('critical.0.place', 'Urgência')->where('critical.0.escalated', true)
            ->where('critical.0.author', $this->doctorAccount->user->name));
        $page = $this->get(route('exam-overdue.index', ['unit' => $this->unit->id]))->getContent();
        $this->assertStringNotContainsString('Potássio', $page);
        $this->assertStringNotContainsString('Joana', $page);

        // Acknowledged late: off the manager's list, and the audit says it was escalated.
        $this->actingAs($this->doctorAccount->user)->post(route('exam-results.acknowledge', $result))->assertSessionHasNoErrors();
        $this->assertTrue(AuditEvent::where('action', 'exam_result.critical_acknowledged')->sole()->context['escalated']);
        $this->assertSame(0, DB::table('user_notifications')->where('urgent', true)->whereNull('read_at')->count());
        $this->actingAs($this->manager->user)->get(route('exam-overdue.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page->has('critical', 0));
    }

    public function test_acknowledged_before_delivery_the_notice_arrives_read_and_without_a_manager_the_network_admins_hear(): void
    {
        $result = $this->releaseCritical();
        $this->actingAs($this->doctorAccount->user)->post(route('exam-results.acknowledge', $result))->assertSessionHasNoErrors();
        $this->inbox();
        $this->assertSame(0, DB::table('user_notifications')->whereNull('read_at')->count());

        // A second critical result, nobody acknowledges it, and the unit has no manager.
        $this->actingAs($this->lab->user)->post(route('exam-results.release', $this->item), ['version' => 2, 'reason' => 'Repetido.', 'report' => 'Potássio 7,4.', 'critical' => '1', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->manager->revoke();
        $this->travel(61)->minutes();
        $this->artisan('exams:critical')->assertSuccessful();
        $admin = Membership::query()->withoutGlobalScopes()->where('tenant_id', $this->unit->tenant_id)->whereHas('roleAssignments', fn ($query) => $query->withoutGlobalScopes()->where('role', Role::NetworkAdmin))->firstOrFail();
        $this->assertContains([$admin->user_id, 'Resultado crítico de exame sem ciência há mais de 60 minutos', true], $this->inbox());
    }

    public function test_acknowledging_a_rectification_closes_the_open_alert_of_the_earlier_version(): void
    {
        $first = $this->releaseCritical();
        $this->actingAs($this->lab->user)->post(route('exam-results.release', $this->item), ['version' => 2, 'reason' => 'Valor trocado.', 'report' => 'Potássio 6,9.', 'critical' => '1', 'password' => 'password'])->assertSessionHasNoErrors();
        $second = ExamResult::withoutGlobalScopes()->where('version', 2)->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('exam-results.acknowledge', $second))->assertSessionHasNoErrors();

        $this->assertSame(0, ExamCriticalAlert::withoutGlobalScopes()->whereNull('acknowledged_at')->count());
        $this->assertSame([$first->id, $second->id], AuditEvent::where('action', 'exam_result.critical_acknowledged')->orderBy('id')->pluck('subject_id')->all());
        $this->travel(2)->hours();
        $this->artisan('exams:critical')->assertSuccessful();
        $this->assertSame(0, AuditEvent::where('action', 'exam_result.critical_unacknowledged')->count());
    }

    public function test_a_plain_result_opens_no_alert_and_a_critical_rectification_does(): void
    {
        $this->actingAs($this->lab->user)->post(route('exam-results.release', $this->item), ['version' => 1, 'report' => 'Normal.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame(0, ExamCriticalAlert::withoutGlobalScopes()->count());
        $this->post(route('exam-results.release', $this->item), ['version' => 2, 'reason' => 'Valor trocado.', 'report' => 'Potássio 7,1.', 'critical' => '1', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame(ExamResult::withoutGlobalScopes()->where('version', 2)->sole()->id, ExamCriticalAlert::withoutGlobalScopes()->sole()->exam_result_id);
    }
}
