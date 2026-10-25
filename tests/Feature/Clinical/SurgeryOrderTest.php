<?php

namespace Tests\Feature\Clinical;

use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Membership;
use App\Models\Sector;
use App\Models\Surgery;
use App\Models\SurgicalProcedure;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Surgery orders (step 5b.2, ADR-0009): the professional who attends orders a procedure of the catalog with the side,
 * the urgency, the wished date and the clinical indication, signed with the password; only the author cancels, with
 * a reason.
 */
class SurgeryOrderTest extends ClinicalTestCase
{
    private SurgicalProcedure $procedure;

    private SurgicalProcedure $inactive;

    private Visit $visit;

    private string $today;

    private Membership $stranger;

    private SurgicalProcedure $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set($this->patient->tenant);
        $this->procedure = SurgicalProcedure::query()->create(['name' => 'Apendicectomia', 'duration_minutes' => 60, 'size' => 'medium']);
        $this->inactive = SurgicalProcedure::query()->create(['name' => 'Antigo', 'duration_minutes' => 60, 'size' => 'small', 'active' => false]);
        // Another client, created before the first request (the client of a request stays set in a test).
        app(CurrentTenant::class)->set(null);
        $this->stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        app(CurrentTenant::class)->set($this->stranger->tenant);
        $this->foreign = SurgicalProcedure::query()->create(['name' => 'Apendicectomia', 'duration_minutes' => 60, 'size' => 'medium']);
        app(CurrentTenant::class)->set(null);
        $this->today = now($this->unit->timezone)->toDateString();

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dor abdominal.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    private function order(array $overrides = []): array
    {
        return ['surgical_procedure_id' => $this->procedure->id, 'laterality' => 'none', 'urgency' => 'urgent', 'desired_date' => $this->today, 'indication' => ' Apendicite aguda. ', 'password' => 'password', ...$overrides];
    }

    public function test_the_attending_doctor_orders_a_surgery_signed_with_the_password(): void
    {
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('canOrder', true));
        $this->get(route('surgery-orders.visit', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->component('surgery-orders/index')->has('catalog', 1)->has('surgeries', 0)->where('today', $this->today));
        $this->assertTrue(DB::table('clinical_reads')->where('user_id', $this->doctorAccount->user_id)->where('visit_id', $this->visit->id)->exists());

        $store = fn (array $overrides) => $this->post(route('surgery-orders.visit.store', $this->visit), $this->order($overrides));
        $store(['password' => 'wrong'])->assertSessionHasErrorsIn('order', 'password');
        $this->assertEmpty(AuditEvent::where('action', 'surgery.sign_failed')->sole()->context);
        $store(['surgical_procedure_id' => $this->foreign->id])->assertSessionHasErrorsIn('order', 'surgical_procedure_id');
        $store(['surgical_procedure_id' => $this->inactive->id])->assertSessionHasErrorsIn('order', 'surgical_procedure_id');
        $store(['laterality' => ''])->assertSessionHasErrorsIn('order', 'laterality');
        $store(['urgency' => 'whenever'])->assertSessionHasErrorsIn('order', 'urgency');
        $store(['desired_date' => now($this->unit->timezone)->subDay()->toDateString()])->assertSessionHasErrorsIn('order', ['desired_date' => 'A data desejada não pode ser no passado.']);
        $store(['desired_date' => now($this->unit->timezone)->addDays(366)->toDateString()])->assertSessionHasErrorsIn('order', ['desired_date' => 'A data desejada deve estar dentro de um ano.']);
        $store(['indication' => ' '])->assertSessionHasErrorsIn('order', 'indication');
        $this->assertSame(0, Surgery::withoutTenantScope()->count());

        $store(['laterality' => 'none', 'urgency' => 'elective', 'desired_date' => now($this->unit->timezone)->addDays(365)->toDateString()])->assertSessionHasNoErrors();
        Surgery::withoutTenantScope()->delete();
        $store(['laterality' => 'right'])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Pedido de cirurgia feito.');
        $surgery = Surgery::withoutTenantScope()->sole();
        $this->assertSame(['Apendicectomia', 'right', 'urgent', $this->today, 'Apendicite aguda.', 'requested', $this->doctor->id, $this->unit->id, $this->visit->id, null],
            [$surgery->procedure_name, $surgery->laterality, $surgery->urgency, $surgery->desired_date->toDateString(), $surgery->indication, $surgery->status, $surgery->professional_id, $surgery->unit_id, $surgery->visit_id, $surgery->admission_id]);
        $this->assertEquals(['unit_id' => $this->unit->id, 'urgency' => 'urgent'], AuditEvent::where('action', 'surgery.requested')->latest('id')->firstOrFail()->context);

        // The order keeps the name as ordered when the catalog changes.
        DB::table('surgical_procedures')->where('id', $this->procedure->id)->update(['name' => 'Apendicectomia aberta']);
        $this->get(route('surgery-orders.visit', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('surgeries.0.procedure', 'Apendicectomia')->where('surgeries.0.canCancel', true));
    }

    public function test_only_who_attends_orders_and_only_the_author_cancels_with_a_reason(): void
    {
        $this->actingAs($this->nurseAccount->user)->get(route('surgery-orders.visit', $this->visit))->assertForbidden();
        $this->post(route('surgery-orders.visit.store', $this->visit), $this->order())->assertForbidden();

        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.visit.store', $this->visit), $this->order())->assertSessionHasNoErrors();
        $surgery = Surgery::withoutTenantScope()->sole();

        $this->actingAs($this->nurseAccount->user)->post(route('surgery-orders.cancel', $surgery), ['reason' => 'Engano.'])->assertForbidden();
        // Another client does not even find it.
        $this->actingAs($this->stranger->user)->post(route('surgery-orders.cancel', $surgery), ['reason' => 'Engano.'])->assertNotFound();
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.cancel', $surgery), ['reason' => ' '])->assertSessionHasErrorsIn("cancel{$surgery->id}", 'reason');
        $this->post(route('surgery-orders.cancel', $surgery), ['reason' => 'Tratamento clínico.'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'Tratamento clínico.'], [$surgery->fresh()->status, $surgery->fresh()->cancellation_reason]);
        $this->post(route('surgery-orders.cancel', $surgery), ['reason' => 'De novo.'])->assertSessionHasErrorsIn("cancel{$surgery->id}", ['reason' => 'Esta cirurgia não pode mais ser cancelada.']);
        $this->assertSame(1, AuditEvent::where('action', 'surgery.cancelled')->count());

        foreach (['UPDATE surgeries SET cancellation_reason = NULL', "UPDATE surgeries SET laterality = ''", 'UPDATE surgeries SET admission_id = NULL, visit_id = NULL'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("Must be refused: {$sql}");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_the_closed_visit_takes_no_more_orders_and_the_admission_does(): void
    {
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.visit.store', $this->visit), $this->order())->assertSessionHasNoErrors();
        $first = Surgery::withoutTenantScope()->sole();
        $sector = Sector::withoutGlobalScopes()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $sector->id, 'room' => '1', 'code' => 'L1', 'type' => 'ward'])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user);
        $this->post(route('visits.close', $this->visit), ['outcome' => 'admission_requested', 'requested_sector_id' => $sector->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->sole();

        $this->get(route('surgery-orders.visit', $this->visit))->assertForbidden();
        $this->post(route('surgery-orders.visit.store', $this->visit), $this->order())->assertForbidden();
        $this->post(route('surgery-orders.cancel', $first), ['reason' => 'Será pedida na internação.'])->assertSessionHasNoErrors();

        $this->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->where('canOrder', true));
        $this->get(route('surgery-orders.admission', $admission))->assertInertia(fn (Assert $page) => $page->where('encounter.type', 'admission')->has('surgeries', 0));
        $wished = now($this->unit->timezone)->addDays(10)->toDateString();
        $this->post(route('surgery-orders.admission.store', $admission), $this->order(['urgency' => 'elective', 'laterality' => 'bilateral', 'desired_date' => $wished]))->assertSessionHasNoErrors();
        $surgery = Surgery::withoutTenantScope()->latest('id')->firstOrFail();
        $this->assertSame([$admission->id, null, 'elective', $wished], [$surgery->admission_id, $surgery->visit_id, $surgery->urgency, $surgery->desired_date->toDateString()]);
        $this->actingAs($this->nurseAccount->user)->post(route('surgery-orders.cancel', $surgery), ['reason' => 'Engano.'])->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->post(route('surgery-orders.cancel', $surgery), ['reason' => 'Alta antes da cirurgia.'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $surgery->fresh()->status);
    }

    public function test_a_function_that_does_not_attend_patients_does_not_order(): void
    {
        DB::table('job_functions')->update(['attends_patients' => false]);
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('canOrder', false));
        $this->get(route('surgery-orders.visit', $this->visit))->assertForbidden();
        $this->post(route('surgery-orders.visit.store', $this->visit), $this->order())->assertForbidden();
        $this->assertSame(0, Surgery::withoutTenantScope()->count());
    }
}
