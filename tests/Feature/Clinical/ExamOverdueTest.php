<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Exam;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\Membership;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 5.3b (ADR-0008): an order open with an item not collected for more than 7 days reaches the unit managers once
 * a day of the unit (from 7 h), and the manager sees it without clinical content.
 */
class ExamOverdueTest extends ClinicalTestCase
{
    private Visit $visit;

    private Exam $blood;

    private Membership $lab;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set($this->patient->tenant);
        $this->blood = Exam::query()->create(['type' => 'laboratory', 'name' => 'Sorologia sigilosa', 'specimen' => 'Sangue']);
        app(CurrentTenant::class)->set(null);
        $this->lab = Membership::factory()->for($this->unit->tenant)->create();
        $this->lab->grant(Role::Laboratory, $this->unit);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Febre.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    private function order(string $priority = 'routine'): ExamOrder
    {
        $this->actingAs($this->doctorAccount->user)->post(route('exam-orders.visit.store', $this->visit), ['exam_ids' => [$this->blood->id], 'priority' => $priority, 'indication' => 'Febre.', 'password' => 'password'])->assertSessionHasNoErrors();

        return ExamOrder::withoutTenantScope()->latest('id')->firstOrFail();
    }

    /**
     * @return array<int, list<string>> user id => titles
     */
    private function inbox(): array
    {
        (new DeliverNotifications)->handle();

        return DB::table('user_notifications')->orderBy('id')->get()->groupBy('user_id')->map(fn ($rows) => $rows->pluck('title')->all())->all();
    }

    public function test_orders_without_collection_for_seven_days_reach_the_managers_once_a_day(): void
    {
        $forgotten = $this->order();
        $collected = $this->order();
        $cancelled = $this->order();
        $this->actingAs($this->lab->user)->post(route('exam-queue.collect', ExamOrderItem::withoutGlobalScopes()->where('exam_order_id', $collected->id)->sole()))->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->post(route('exam-orders.cancel', $cancelled), ['reason' => 'Engano.'])->assertSessionHasNoErrors();
        $this->travel(6)->days();
        $this->order();

        // Six days in: nothing yet.
        $this->artisan('exams:overdue')->assertSuccessful();
        $this->assertSame(0, AuditEvent::where('action', 'exam.overdue_orders')->count());

        // Seven days and one hour after the first orders, at 10 h in the unit: only the forgotten one counts.
        $this->travelTo('2026-11-20 13:00:00');
        $this->artisan('exams:overdue')->assertSuccessful();
        $this->artisan('exams:overdue')->assertSuccessful();
        $this->assertEquals(['unit_id' => $this->unit->id, 'date' => '2026-11-20', 'orders' => 1], AuditEvent::where('action', 'exam.overdue_orders')->sole()->context);
        $this->assertSame([$this->manager->user_id => ['Exames: 1 pedido(s) sem coleta há mais de 7 dias']], $this->inbox());

        // The manager sees when, where and who ordered; never the exam or the patient.
        $this->actingAs($this->manager->user)->get(route('exam-overdue.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page
            ->component('exam-overdue/index')->has('orders', 1)->where('orders.0.id', $forgotten->id)->where('orders.0.pending', 1)
            ->where('orders.0.place', 'Urgência')->where('orders.0.days', 7)->where('orders.0.author', $this->doctorAccount->user->name));
        $page = $this->get(route('exam-overdue.index', ['unit' => $this->unit->id]))->getContent();
        $this->assertStringNotContainsString('Sorologia', $page);
        $this->assertStringNotContainsString('Joana', $page);

        // A manager of the other unit asking for this one gets their own unit, never these orders.
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::UnitManager, $this->other);
        $this->actingAs($elsewhere->user)->get(route('exam-overdue.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page
            ->where('unitId', $this->other->id)->has('units', 1)->has('orders', 0));

        // Another day, another alert.
        $this->travelTo('2026-11-21 13:00:00');
        $this->artisan('exams:overdue')->assertSuccessful();
        $this->assertSame(2, AuditEvent::where('action', 'exam.overdue_orders')->count());
    }

    public function test_before_seven_in_the_unit_nothing_and_without_a_manager_the_network_admins_hear(): void
    {
        $this->order('urgent');
        // 9 h UTC is 6 h in São Paulo.
        $this->travelTo('2026-11-21 09:00:00');
        $this->artisan('exams:overdue')->assertSuccessful();
        $this->assertSame(0, AuditEvent::where('action', 'exam.overdue_orders')->count());

        $this->manager->revoke();
        $admin = Membership::query()->withoutGlobalScopes()->where('tenant_id', $this->unit->tenant_id)->whereHas('roleAssignments', fn ($query) => $query->withoutGlobalScopes()->where('role', Role::NetworkAdmin))->firstOrFail();
        $this->travelTo('2026-11-21 10:30:00');
        $this->artisan('exams:overdue')->assertSuccessful();
        $this->assertSame([$admin->user_id => ['Exames: 1 pedido(s) sem coleta há mais de 7 dias']], $this->inbox());
        $this->actingAs($admin->user)->get(route('exam-overdue.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page->has('orders', 1)->where('orders.0.priority', 'urgent'));
        $this->actingAs($this->reception->user)->get(route('exam-overdue.index'))->assertForbidden();
        $this->actingAs($this->lab->user)->get(route('exam-overdue.index'))->assertForbidden();
    }
}
