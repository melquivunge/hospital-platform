<?php

namespace Tests\Feature\Clinical;

use App\Models\Appointment;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A network with two units, a doctor and a nurse with linked accounts, a reception desk, a manager and one
 * patient: the setting of the clinical record tests (steps 3.6a and 3.6b).
 */
abstract class ClinicalTestCase extends TestCase
{
    use RefreshDatabase;

    protected Membership $reception;

    protected Membership $doctorAccount;

    protected Membership $nurseAccount;

    protected Membership $manager;

    protected Unit $unit;

    protected Unit $other;

    protected Professional $doctor;

    protected Professional $nurse;

    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 12:00:00');

        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->other = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->manager = Membership::factory()->for($tenant)->create();
        $this->manager->grant(Role::UnitManager, $this->unit);
        $this->manager->grant(Role::UnitManager, $this->other);
        $this->reception = Membership::factory()->for($tenant)->create();
        $this->reception->grant(Role::Reception, $this->unit);
        $this->reception->grant(Role::Reception, $this->other);
        $this->doctorAccount = Membership::factory()->for($tenant)->create();
        $this->doctorAccount->grant(Role::Professional, $this->unit);
        $this->nurseAccount = Membership::factory()->for($tenant)->create();
        $this->nurseAccount->grant(Role::Professional, $this->other);

        app(CurrentTenant::class)->set($tenant);
        $function = JobFunction::factory()->for($tenant)->create();
        $this->doctor = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Dra. Ana']);
        $this->nurse = Professional::factory()->create(['base_unit_id' => $this->other->id, 'name' => 'Enf. Bia']);
        $this->doctor->forceFill(['membership_id' => $this->doctorAccount->id])->save();
        $this->nurse->forceFill(['membership_id' => $this->nurseAccount->id])->save();
        foreach ([[$this->doctor, $this->unit], [$this->nurse, $this->other]] as [$professional, $unit]) {
            Qualification::request($professional, $unit, ['job_function_id' => $function->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::factory()->for($unit)->create()->id])
                ->transitionTo(QualificationStatus::Approved, null);
        }
        app(CurrentTenant::class)->set(null);

        foreach ([[$this->doctor, $this->unit], [$this->nurse, $this->other]] as [$professional, $unit]) {
            $this->actingAs($this->manager->user)->post(route('schedules.rules.store', $unit), ['professional_id' => $professional->id, 'weekday' => 1, 'start' => '08:00', 'end' => '12:00', 'slot_minutes' => 20])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->reception->user)->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana Prado', 'birth_date' => '1990-01-02', 'sex' => 'female'])->assertSessionHasNoErrors();
        $this->patient = Patient::withoutTenantScope()->sole();
    }

    /**
     * Booked on Friday for Monday, then the patient arrives on Monday morning.
     *
     * @param  array<string, mixed>  $modality
     */
    protected function arrived(Professional $professional, Unit $unit, string $time = '08:20', array $modality = ['modality' => 'sus']): Appointment
    {
        $this->actingAs($this->reception->user)->post(route('appointments.store', $unit), ['professional_id' => $professional->id, 'patient_id' => $this->patient->id, 'starts_at' => "2026-11-16T{$time}"])->assertSessionHasNoErrors();
        $appointment = Appointment::withoutTenantScope()->latest('id')->firstOrFail();
        $this->travelTo('2026-11-16 10:30:00');
        $this->post(route('appointments.arrive', $appointment), $modality)->assertSessionHasNoErrors();

        return $appointment;
    }
}
