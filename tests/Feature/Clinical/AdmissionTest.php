<?php

namespace Tests\Feature\Clinical;

use App\Http\Controllers\AdmissionController;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Sector;
use App\Models\Unit;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class AdmissionTest extends ClinicalTestCase
{
    private Membership $bedManager;

    private Sector $ward;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bedManager = Membership::factory()->for($this->unit->tenant)->create();
        $this->bedManager->grant(Role::BedManagement, $this->unit);
        $this->ward = Sector::withoutGlobalScopes()->where('unit_id', $this->unit->id)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bed(string $code, array $attributes = [], ?Unit $unit = null): Bed
    {
        $unit ??= $this->unit;
        $sector = Sector::withoutGlobalScopes()->where('unit_id', $unit->id)->firstOrFail();
        $this->actingAs($this->manager->user)->post(route('beds.store', $unit), ['sector_id' => $sector->id, 'room' => '10', 'code' => $code, 'type' => 'ward', ...$attributes])->assertSessionHasNoErrors();

        return Bed::withoutGlobalScopes()->where('code', $code)->sole();
    }

    /**
     * Arrival, classification and the emergency visit, closed with an admission request.
     */
    private function requested(bool $isolation = false): Admission
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $visit = Visit::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('visits.close', $visit), ['outcome' => 'admission_requested', 'requested_sector_id' => $this->ward->id, 'responsible_professional_id' => $this->doctor->id, 'isolation' => $isolation])->assertSessionHasNoErrors();

        return Admission::withoutTenantScope()->latest('id')->firstOrFail();
    }

    public function test_the_emergency_doctor_requests_and_bed_management_admits(): void
    {
        $bed = $this->bed('10-A');
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'sus']);
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $visit = Visit::withoutTenantScope()->sole();

        $this->get(route('visits.show', $visit))->assertInertia(fn (Assert $page) => $page
            ->where('admissionOptions.sectors.0.id', $this->ward->id)->where('admissionOptions.me', $this->doctor->id));
        // The sector and the responsible doctor are required, and must belong to the unit.
        $this->post(route('visits.close', $visit), ['outcome' => 'admission_requested'])->assertSessionHasErrors(['requested_sector_id', 'responsible_professional_id']);
        $this->post(route('visits.close', $visit), ['outcome' => 'admission_requested', 'requested_sector_id' => $this->ward->id, 'responsible_professional_id' => $this->nurse->id])->assertSessionHasErrors('responsible_professional_id');
        $this->assertNull($visit->fresh()->closed_at);
        $this->post(route('visits.close', $visit), ['outcome' => 'admission_requested', 'requested_sector_id' => $this->ward->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->sole();
        $this->assertSame(['requested', $this->ward->id, $this->doctor->id], [$admission->status, $admission->requested_sector_id, $admission->responsible_professional_id]);

        $this->actingAs($this->bedManager->user)->get(route('admissions.index'))->assertInertia(fn (Assert $page) => $page->component('admissions/index')
            ->where('admissions.0.patient', 'Joana Prado')->where('admissions.0.status', 'requested')->where('freeBeds.0.id', $bed->id)
            ->missing('admissions.0.complaint'));
        $this->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();

        $this->assertSame(['admitted', $bed->id], [$admission->fresh()->status, $admission->fresh()->bed_id]);
        $this->assertSame('occupied', $bed->fresh()->status);
        $this->assertSame('admitted', $episode->fresh()->status);
        $this->assertSame(1, DB::table('bed_events')->where('bed_id', $bed->id)->where('to_status', 'occupied')->count());
        $this->assertSame(1, AuditEvent::where('action', 'admission.admitted')->count());
        // A second allocation of the same admission is refused.
        $second = $this->bed('10-B');
        $this->actingAs($this->bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => $second->id])->assertSessionHasErrors('bed_id');
        $this->assertSame('free', $second->fresh()->status);
    }

    public function test_a_bed_outside_the_rule_needs_a_recorded_justification(): void
    {
        $male = $this->bed('10-M', ['sex_restriction' => 'male']);
        $isolation = $this->bed('10-I', ['isolation' => true]);
        $admission = $this->requested(isolation: true);

        $this->actingAs($this->bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => $male->id])->assertSessionHasErrors('compatibility_justification');
        $this->assertSame('free', $male->fresh()->status);
        $this->post(route('admissions.allocate', $admission), ['bed_id' => $male->id, 'compatibility_justification' => 'Único leito livre; biombo instalado.'])->assertSessionHasNoErrors();
        $this->assertSame('Único leito livre; biombo instalado.', $admission->fresh()->compatibility_justification);
        $context = AuditEvent::where('action', 'admission.admitted')->sole()->context;
        $this->assertTrue($context['outside_rule']);
        $this->assertStringNotContainsString('biombo', (string) json_encode($context));
        $this->assertSame('free', $isolation->fresh()->status);
    }

    public function test_only_bed_management_of_the_unit_sees_and_allocates(): void
    {
        $bed = $this->bed('10-A');
        $admission = $this->requested();
        $otherUnit = Membership::factory()->for($this->unit->tenant)->create();
        $otherUnit->grant(Role::BedManagement, $this->other);

        foreach ([$this->reception, $this->manager, $this->doctorAccount] as $member) {
            $this->actingAs($member->user)->get(route('admissions.index'))->assertForbidden();
            $this->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertForbidden();
        }
        $this->actingAs($otherUnit->user)->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertForbidden();
        $elsewhere = $this->bed('X-1', [], $this->other);
        $this->actingAs($this->bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => $elsewhere->id])->assertSessionHasErrors('bed_id');
        $this->bedManager->revoke();
        $this->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertForbidden();
        $this->assertSame('requested', $admission->fresh()->status);
    }

    public function test_merged_registrations_never_hold_two_active_admissions_and_revoked_accounts_are_not_responsible(): void
    {
        $this->bed('10-A');
        $this->requested();
        // A duplicate registration of the same person, also admitted through the emergency department.
        $this->actingAs($this->reception->user)->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana P. Prado', 'birth_date' => '1990-01-02', 'sex' => 'female', 'confirm_new' => true])->assertSessionHasNoErrors();
        $duplicate = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $first = Admission::query()->sole();
        Admission::query()->whereKey($first->id)->update(['patient_id' => $duplicate->id]);
        EmergencyEpisode::query()->whereKey($first->emergency_episode_id)->update(['patient_id' => $duplicate->id]);
        app(CurrentTenant::class)->set(null);
        $this->requested();
        $this->actingAs($this->manager->user)->post(route('patient-merges.store'), ['a_id' => $this->patient->id, 'b_id' => $duplicate->id, 'primary_id' => $this->patient->id, 'reason' => 'Mesma pessoa.'])->assertSessionHasErrors('merge');

        // A revoked account is no longer offered nor accepted as responsible.
        $this->doctorAccount->revoke();
        $this->assertSame([], array_column(AdmissionController::requestOptions($this->unit->id)['professionals'], 'id'));
    }

    public function test_the_database_refuses_two_patients_in_a_bed_and_two_active_admissions(): void
    {
        $bed = $this->bed('10-A');
        $admission = $this->requested();
        $this->actingAs($this->bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();
        $this->actingAs($this->reception->user)->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Rui Nunes'])->assertSessionHasNoErrors();
        $other = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = (array) DB::table('admissions')->first();
        unset($row['id'], $row['emergency_episode_id']);

        foreach ([
            // Another patient in the same bed.
            fn () => DB::table('admissions')->insert([...$row, 'patient_id' => $other->id]),
            // A second active admission of the same patient.
            fn () => DB::table('admissions')->insert([...$row, 'status' => 'requested', 'bed_id' => null, 'admitted_at' => null, 'admitted_by_user_id' => null]),
            // Admitted without a bed.
            fn () => DB::table('admissions')->update(['bed_id' => null]),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('The database accepted a change it must refuse.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['23505', '23514']);
            }
        }
    }
}
