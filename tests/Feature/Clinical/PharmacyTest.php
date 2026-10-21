<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 4b.3a (ADR-0005): the unit's pharmacy reviews each item of the valid prescription, sees only the
 * prescription, the allergies and the patient's identification, and every opening is in the read trail.
 */
class PharmacyTest extends ClinicalTestCase
{
    private Visit $visit;

    private Prescription $prescription;

    private Membership $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->forceFill(['council' => 'CRM', 'council_number' => '123456', 'council_uf' => 'SP'])->save();
        $medications = [];
        foreach ([['Dipirona', '500 mg', ['oral']], ['Ceftriaxona', '1 g', ['iv']]] as [$ingredient, $strength, $routes]) {
            $medications[] = Medication::query()->create(['active_ingredient' => $ingredient, 'form' => 'Comprimido', 'strength' => $strength, 'dose_unit' => 'mg', 'routes' => $routes, 'controlled' => false, 'high_alert' => false]);
        }
        app(CurrentTenant::class)->set(null);
        $this->pharmacist = Membership::factory()->for($this->unit->tenant)->create();
        $this->pharmacist->grant(Role::Pharmacy, $this->unit);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Febre.']);
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Penicilina', 'severity' => 'severe'])->assertSessionHasNoErrors();
        $this->post(route('visits.prescriptions.start', $this->visit))->assertRedirect();
        $this->prescription = Prescription::withoutTenantScope()->sole();
        foreach ([[$medications[0], 'oral', 500], [$medications[1], 'iv', 1000]] as [$medication, $route, $dose]) {
            $this->post(route('prescriptions.items.store', $this->prescription), ['medication_id' => $medication->id, 'dose' => $dose, 'route' => $route, 'schedule' => 'interval', 'frequency_hours' => 12, 'prn' => '0'])->assertSessionHasNoErrors();
        }
        $this->post(route('prescriptions.sign', $this->prescription), ['password' => 'password'])->assertSessionHasNoErrors();
    }

    public function test_the_pharmacist_opens_only_the_prescription_and_every_opening_is_logged(): void
    {
        $reads = DB::table('clinical_reads')->count();
        $this->actingAs($this->pharmacist->user)->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->component('pharmacy/index')
            ->has('prescriptions', 1)->where('prescriptions.0.patient', 'Joana Prado')->where('prescriptions.0.pending', 2));
        $this->assertSame($reads, DB::table('clinical_reads')->count());

        $this->get(route('pharmacy.show', $this->prescription))->assertInertia(fn (Assert $page) => $page->component('pharmacy/show')
            ->has('items', 2)->where('allergies.0.substance', 'Penicilina')->where('patient', ['name' => 'Joana Prado', 'age' => 36, 'sex' => 'female'])
            ->missing('record')->missing('documents')->missing('drafts'));
        $read = DB::table('clinical_reads')->latest('id')->first();
        $this->assertSame(['pharmacy', $this->prescription->id, $this->pharmacist->user_id], [$read->reason, $read->prescription_id, $read->user_id]);
        // The auditor's access trail labels it as a pharmacy read, not an emergency.
        $auditor = Membership::factory()->for($this->unit->tenant)->create();
        $auditor->grant(Role::Auditor);
        $this->actingAs($auditor->user)->get(route('clinical-access.index'))->assertInertia(fn (Assert $page) => $page->where('reads.0.reason', 'pharmacy'));
        $this->actingAs($this->pharmacist->user);
        // The pharmacy reaches neither the record nor the visit.
        $this->get(route('visits.show', $this->visit))->assertForbidden();
        $this->get(route('clinical-record.show', $this->patient))->assertForbidden();
    }

    public function test_each_item_is_reviewed_once_and_a_refusal_reaches_the_prescriber(): void
    {
        [$dipyrone, $ceftriaxone] = PrescriptionItem::withoutTenantScope()->orderBy('id')->get()->all();
        $this->actingAs($this->pharmacist->user);
        $this->post(route('pharmacy.review', $dipyrone), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->post(route('pharmacy.review', $dipyrone), ['decision' => 'refused', 'note' => 'x'])->assertSessionHasErrors('decision', null, "review{$dipyrone->id}");
        $this->post(route('pharmacy.review', $ceftriaxone), ['decision' => 'refused'])->assertSessionHasErrors('note', null, "review{$ceftriaxone->id}");
        $this->post(route('pharmacy.review', $ceftriaxone), ['decision' => 'refused', 'note' => 'Paciente alérgica a penicilina: risco cruzado.'])->assertSessionHasNoErrors();

        $audit = AuditEvent::where('action', 'prescription_item.reviewed')->latest('id')->firstOrFail();
        $this->assertEquals(['decision' => 'refused', 'prescription_id' => $this->prescription->id, 'author_user_id' => $this->doctorAccount->user_id, 'visit_id' => $this->visit->id], $audit->context);
        (new DeliverNotifications)->handle();
        $notification = DB::table('user_notifications')->where('user_id', $this->doctorAccount->user_id)->where('audit_event_id', $audit->id)->sole();
        $this->assertSame(['A farmácia recusou um item da sua prescrição', "/visits/{$this->visit->id}"], [$notification->title, $notification->url]);

        $this->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->where('prescriptions.0.pending', 0));
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.items.0.review.label', 'Aprovado')
            ->where('prescriptions.active.items.1.review.note', 'Paciente alérgica a penicilina: risco cruzado.'));

        app(CurrentTenant::class)->set($this->unit->tenant);
        try {
            DB::transaction(fn () => DB::table('prescription_reviews')->where('prescription_item_id', $dipyrone->id)->update(['decision' => 'refused', 'note' => 'x']));
            $this->fail('A review changed.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }
    }

    public function test_only_the_units_pharmacy_and_only_the_valid_prescription(): void
    {
        $item = PrescriptionItem::withoutTenantScope()->orderBy('id')->firstOrFail();
        // Pharmacy of another unit, and no pharmacy role at all.
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Pharmacy, $this->other);
        $this->actingAs($elsewhere->user)->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->has('prescriptions', 0));
        $this->get(route('pharmacy.show', $this->prescription))->assertForbidden();
        $this->post(route('pharmacy.review', $item), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->nurseAccount->user)->get(route('pharmacy.index'))->assertForbidden();

        // A suspended item is not reviewed.
        $this->actingAs($this->doctorAccount->user)->post(route('prescription-items.suspend', $item), ['reason' => 'Sem febre.'])->assertSessionHasNoErrors();
        $this->actingAs($this->pharmacist->user)->post(route('pharmacy.review', $item), ['decision' => 'approved'])->assertSessionHasErrors('decision', null, "review{$item->id}");

        // Replaced, expired or with the visit closed, the prescription is out of reach.
        $this->actingAs($this->doctorAccount->user)->post(route('visits.prescriptions.start', $this->visit))->assertRedirect();
        $this->post(route('prescriptions.sign', Prescription::withoutTenantScope()->where('status', 'draft')->sole()), ['password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($this->pharmacist->user)->get(route('pharmacy.show', $this->prescription))->assertForbidden();
        $this->post(route('pharmacy.review', PrescriptionItem::withoutTenantScope()->where('prescription_id', $this->prescription->id)->latest('id')->firstOrFail()), ['decision' => 'approved'])->assertForbidden();
        $newest = Prescription::withoutTenantScope()->latest('id')->firstOrFail();
        $this->get(route('pharmacy.show', $newest))->assertOk();
        $this->travel(25)->hours();
        $this->get(route('pharmacy.show', $newest))->assertForbidden();
        $this->travelBack();

        // The role revoked: nothing.
        $this->pharmacist->roleAssignments()->where('role', Role::Pharmacy)->delete();
        $this->get(route('pharmacy.index'))->assertForbidden();
    }
}
