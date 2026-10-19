<?php

namespace Tests\Feature\Clinical;

use App\Http\Controllers\AdmissionController;
use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Unit;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 4.9 (decisions after the security review of 4.8): discharge before a bed and in transit, a new
 * responsible at the reception of a transfer, and only functions that attend patients call from the queue.
 */
class InpatientLooseEndsTest extends ClinicalTestCase
{
    private Membership $originBeds;

    private Membership $destinationBeds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originBeds = Membership::factory()->for($this->unit->tenant)->create();
        $this->originBeds->grant(Role::BedManagement, $this->unit);
        $this->destinationBeds = Membership::factory()->for($this->unit->tenant)->create();
        $this->destinationBeds->grant(Role::BedManagement, $this->other);
    }

    private function bed(Unit $unit, string $code): Bed
    {
        $sector = Sector::withoutGlobalScopes()->where('unit_id', $unit->id)->firstOrFail();
        $this->actingAs($this->manager->user)->post(route('beds.store', $unit), ['sector_id' => $sector->id, 'room' => '1', 'code' => $code, 'type' => 'ward'])->assertSessionHasNoErrors();

        return Bed::withoutGlobalScopes()->where('code', $code)->sole();
    }

    /** Requested by the doctor from the emergency visit, still without a bed. */
    private function requested(Bed $bed): Admission
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id]);
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()), ['outcome' => 'admission_requested', 'requested_sector_id' => $bed->sector_id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();

        return Admission::withoutTenantScope()->latest('id')->firstOrFail();
    }

    /**
     * Admitted at the origin, transfer accepted by the destination and departed: in transit.
     *
     * @return array{Admission, AdmissionTransfer, Bed, Bed}
     */
    private function inTransit(): array
    {
        $origin = $this->bed($this->unit, 'O-1');
        $destination = $this->bed($this->other, 'D-1');
        $admission = $this->requested($origin);
        $this->actingAs($this->originBeds->user)->post(route('admissions.allocate', $admission), ['bed_id' => $origin->id])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->post(route('transfers.store', $admission), ['to_unit_id' => $this->other->id, 'requested_sector_id' => $destination->sector_id, 'responsible_professional_id' => $this->nurse->id])->assertSessionHasNoErrors();
        $transfer = AdmissionTransfer::withoutTenantScope()->sole();
        $this->actingAs($this->destinationBeds->user)->post(route('transfers.accept', $transfer), ['bed_id' => $destination->id])->assertSessionHasNoErrors();
        $this->actingAs($this->originBeds->user)->post(route('transfers.depart', $transfer))->assertSessionHasNoErrors();

        return [$admission->fresh(), $transfer, $origin, $destination];
    }

    public function test_a_patient_waiting_for_a_bed_is_discharged_and_leaves_the_emergency_department(): void
    {
        $admission = $this->requested($this->bed($this->unit, 'A-1'));
        // Only the responsible doctor discharges: not bed management, not another professional.
        foreach ([$this->originBeds, $this->nurseAccount] as $member) {
            $this->actingAs($member->user)->post(route('ward.discharge', $admission), ['type' => 'evasion', 'occurred_at' => now($this->unit->timezone)->format('Y-m-d\TH:i')])->assertForbidden();
        }
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->has('discharge.types', 5));

        $this->travel(5)->minutes();
        $this->post(route('ward.discharge', $admission), ['type' => 'evasion', 'occurred_at' => now($this->unit->timezone)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $admission = $admission->fresh();
        $this->assertSame(['discharged', 'evasion', null, null], [$admission->status, $admission->discharge_type, $admission->bed_id, $admission->admitted_at]);
        $this->assertSame('evaded', EmergencyEpisode::withoutTenantScope()->sole()->status);

        // Out of the bed management list and of the census; the patient can arrive again.
        $this->actingAs($this->originBeds->user)->get(route('admissions.index'))->assertInertia(fn (Assert $page) => $page->has('admissions', 0));
        $this->get(route('census.index'))->assertInertia(fn (Assert $page) => $page->where('total.discharges', 0));
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
    }

    public function test_a_patient_in_transit_is_discharged_and_the_reserved_bed_is_freed(): void
    {
        [$admission, $transfer, $origin, $destination] = $this->inTransit();
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page->has('discharge')->where('handOver', null));

        // Not before the departure.
        $this->travel(10)->minutes();
        $this->post(route('ward.discharge', $admission), ['type' => 'death', 'occurred_at' => $transfer->fresh()->departed_at->subMinutes(5)->setTimezone($this->unit->timezone)->format('Y-m-d\TH:i')])->assertSessionHasErrors('occurred_at');
        $this->post(route('ward.discharge', $admission), ['type' => 'death', 'occurred_at' => now($this->unit->timezone)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame(['discharged', 'death'], [$admission->fresh()->status, $admission->fresh()->discharge_type]);
        $transfer = $transfer->fresh();
        $this->assertSame(['cancelled', null], [$transfer->status, $transfer->reserved_bed_id]);
        $this->assertNotNull($transfer->departed_at);
        $this->assertSame(['cleaning', 'free'], [$origin->fresh()->status, $destination->fresh()->status]);
        $this->actingAs($this->destinationBeds->user)->post(route('transfers.receive', $transfer))->assertSessionHasErrors('transfer');
    }

    public function test_the_destination_names_another_responsible_when_the_chosen_one_left(): void
    {
        [$admission, $transfer] = $this->inTransit();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $account = Membership::factory()->for($this->unit->tenant)->create();
        $account->grant(Role::Professional, $this->other);
        $substitute = Professional::factory()->create(['base_unit_id' => $this->other->id, 'name' => 'Dr. Plantão']);
        $substitute->forceFill(['membership_id' => $account->id])->save();
        Qualification::request($substitute, $this->other, ['job_function_id' => JobFunction::query()->firstOrFail()->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::query()->where('unit_id', $this->other->id)->firstOrFail()->id])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);
        $this->nurseAccount->revoke();

        $this->actingAs($this->destinationBeds->user)->post(route('transfers.receive', $transfer))->assertSessionHasErrors('transfer');
        // A professional not qualified at the destination is refused too.
        $this->post(route('transfers.receive', $transfer), ['responsible_professional_id' => $this->doctor->id])->assertSessionHasErrors('transfer');
        $this->post(route('transfers.receive', $transfer), ['responsible_professional_id' => $substitute->id])->assertSessionHasNoErrors();
        $this->assertSame(['admitted', $substitute->id], [$admission->fresh()->status, $admission->fresh()->responsible_professional_id]);
        $this->assertSame($substitute->id, $transfer->fresh()->responsible_professional_id);
        $this->actingAs($account->user)->get(route('ward.show', $admission))->assertOk();
    }

    public function test_only_functions_that_attend_patients_call_from_the_queue(): void
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id]);
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $function = JobFunction::withoutTenantScope()->sole();
        $admin = Membership::withoutGlobalScopes()->whereHas('roleAssignments', fn ($query) => $query->where('role', Role::NetworkAdmin))->firstOrFail();
        $this->actingAs($admin->user)->put(route('job-functions.update', $function), ['name' => $function->name, 'attends_patients' => false])->assertSessionHasNoErrors();
        $this->assertFalse($function->fresh()->attends_patients);

        // Still classifies, but neither sees the call nor calls.
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.'])->assertSessionHasNoErrors();
        $this->get(route('emergency.index'))->assertInertia(fn (Assert $page) => $page->where('canTriage', true)->where('canCall', false));
        $this->post(route('emergency.call', $episode))->assertForbidden();
        $this->assertSame(0, Visit::withoutTenantScope()->count());

        $this->actingAs($admin->user)->put(route('job-functions.update', $function), ['name' => $function->name, 'attends_patients' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.call', $episode))->assertRedirect();
        $this->assertSame(1, Visit::withoutTenantScope()->count());

        // Nor is a function that does not attend patients named responsible for an admission.
        $this->actingAs($admin->user)->put(route('job-functions.update', $function), ['name' => $function->name, 'attends_patients' => false])->assertSessionHasNoErrors();
        $this->assertSame([], AdmissionController::requestOptions($this->unit->id)['professionals']);
        $this->actingAs($admin->user)->put(route('job-functions.update', $function), ['name' => $function->name, 'attends_patients' => 'talvez'])->assertSessionHasErrors();
    }
}
