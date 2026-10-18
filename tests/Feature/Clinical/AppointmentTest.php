<?php

namespace Tests\Feature\Clinical;

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Insurer;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Membership $reception;

    private Membership $manager;

    private Unit $unit;

    private Unit $other;

    private Professional $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        // Friday; Monday 16/11/2026 is in the future.
        $this->travelTo('2026-11-13 12:00:00');

        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->other = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->reception = Membership::factory()->for($tenant)->create();
        $this->reception->grant(Role::Reception, $this->unit);
        $this->manager = Membership::factory()->for($tenant)->create();
        $this->manager->grant(Role::UnitManager, $this->unit);

        app(CurrentTenant::class)->set($tenant);
        $this->doctor = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Dra. Ana']);
        Qualification::request($this->doctor, $this->unit, ['job_function_id' => JobFunction::factory()->for($tenant)->create()->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::factory()->for($this->unit)->create()->id])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);

        // Monday 08:00–10:00, 20 minutes each: six slots.
        $this->actingAs($this->manager->user)->post(route('schedules.rules.store', $this->unit), ['professional_id' => $this->doctor->id, 'weekday' => 1, 'start' => '08:00', 'end' => '10:00', 'slot_minutes' => 20])->assertSessionHasNoErrors();
        $this->actingAs($this->reception->user)->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana Prado', 'birth_date' => '1990-01-02', 'sex' => 'female'])->assertSessionHasNoErrors();
        $this->patient = Patient::withoutTenantScope()->sole();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function booking(array $data = []): array
    {
        return ['professional_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'starts_at' => '2026-11-16T08:20', ...$data];
    }

    public function test_reception_books_a_free_slot_once_and_walk_ins_are_explicit(): void
    {
        $this->post(route('appointments.store', $this->unit), $this->booking())->assertSessionHasNoErrors();
        $appointment = Appointment::withoutTenantScope()->sole();
        $this->assertSame(['2026-11-16 11:20', '2026-11-16 11:40', false], [$appointment->starts_at->utc()->format('Y-m-d H:i'), $appointment->ends_at->utc()->format('Y-m-d H:i'), $appointment->walk_in]);

        // Same slot again, a time off the grid, the past or a day without grid are refused.
        $this->post(route('appointments.store', $this->unit), $this->booking())->assertSessionHasErrors('starts_at');
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T08:10']))->assertSessionHasErrors('starts_at');
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-09T08:00']))->assertSessionHasErrors('starts_at');
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-17T08:00']))->assertSessionHasErrors('starts_at');

        // A walk-in may overlap, inside the professional's hours, and is marked as such.
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T08:25', 'walk_in' => true]))->assertSessionHasNoErrors();
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T11:00', 'walk_in' => true]))->assertSessionHasErrors('starts_at');
        $this->assertSame(1, Appointment::withoutTenantScope()->where('walk_in', true)->count());

        $this->get(route('appointments.index', ['professional' => $this->doctor->id, 'date' => '2026-11-16']))->assertInertia(fn (Assert $page) => $page
            ->component('appointments/index')->has('slots', 6)->where('slots.1.state', 'booked')->where('slots.0.state', 'free')
            ->has('appointments', 2)->where('appointments.0.patient', 'Joana Prado'));

        // The audit trail keeps ids, never the patient's name.
        $context = AuditEvent::where('action', 'appointment.created')->firstOrFail()->context;
        $this->assertSame($this->patient->id, $context['patient_id']);
        $this->assertStringNotContainsString('Joana', (string) json_encode($context));
    }

    public function test_blocked_periods_close_slots(): void
    {
        $this->actingAs($this->manager->user)->post(route('schedules.blocks.store', $this->unit), [
            'professional_id' => $this->doctor->id, 'starts_at' => '2026-11-16T09:00', 'ends_at' => '2026-11-16T10:00', 'reason' => 'Reunião',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->reception->user)->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T09:20']))->assertSessionHasErrors('starts_at');
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T09:30', 'walk_in' => true]))->assertSessionHasErrors('starts_at');
        $this->get(route('appointments.index', ['date' => '2026-11-16']))->assertInertia(fn (Assert $page) => $page->where('slots.3.state', 'blocked'));
    }

    public function test_cancel_needs_a_reason_and_rescheduling_links_the_new_booking(): void
    {
        $this->post(route('appointments.store', $this->unit), $this->booking())->assertSessionHasNoErrors();
        $first = Appointment::withoutTenantScope()->sole();

        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T08:40', 'rescheduled_from_id' => $first->id]))->assertSessionHasErrors('reason');
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T08:40', 'rescheduled_from_id' => $first->id, 'reason' => 'Pedido da paciente']))->assertSessionHasNoErrors();
        $second = Appointment::withoutTenantScope()->latest('id')->firstOrFail();
        $first = Appointment::withoutTenantScope()->findOrFail($first->id);
        $this->assertSame([$first->id, 'cancelled', 'Pedido da paciente'], [$second->rescheduled_from_id, $first->status, $first->cancel_reason]);
        // An already closed booking cannot be rescheduled again.
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T09:00', 'rescheduled_from_id' => $first->id, 'reason' => 'De novo']))->assertSessionHasErrors('starts_at');

        $this->post(route('appointments.cancel', $second), [])->assertSessionHasErrors('reason');
        $this->post(route('appointments.cancel', $second), ['reason' => 'Desistiu'])->assertSessionHasNoErrors();
        $this->post(route('appointments.cancel', $second), ['reason' => 'De novo'])->assertSessionHasErrors('reason');
        // The freed slot can be booked again.
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T08:40']))->assertSessionHasNoErrors();
        $this->assertSame(2, AuditEvent::where('action', 'appointment.cancelled')->count());
    }

    public function test_no_show_only_after_the_time(): void
    {
        $this->post(route('appointments.store', $this->unit), $this->booking())->assertSessionHasNoErrors();
        $appointment = Appointment::withoutTenantScope()->sole();

        $this->post(route('appointments.no-show', $appointment))->assertSessionHasErrors('starts_at');
        $this->travelTo('2026-11-16 12:00:00');
        $this->post(route('appointments.no-show', $appointment))->assertSessionHasNoErrors();
        $this->assertSame('no_show', Appointment::withoutTenantScope()->findOrFail($appointment->id)->status);
        $this->post(route('appointments.cancel', $appointment), ['reason' => 'Tarde'])->assertSessionHasErrors('reason');
    }

    public function test_a_walk_in_never_ends_after_the_window_and_a_taken_slot_keeps_the_original_booking(): void
    {
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T09:50', 'walk_in' => true]))->assertSessionHasNoErrors();
        $this->assertSame('13:00', Appointment::withoutTenantScope()->sole()->ends_at->utc()->format('H:i'));

        $this->post(route('appointments.store', $this->unit), $this->booking())->assertSessionHasNoErrors();
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T08:40']))->assertSessionHasNoErrors();
        $first = Appointment::withoutTenantScope()->where('starts_at', '2026-11-16 11:20:00+00')->sole();
        // Rescheduling into a taken slot fails as a whole: the original booking is not cancelled.
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T08:40', 'rescheduled_from_id' => $first->id, 'reason' => 'Troca']))->assertSessionHasErrors('starts_at');
        $this->assertSame('scheduled', Appointment::withoutTenantScope()->findOrFail($first->id)->status);
    }

    public function test_bookings_go_to_the_primary_registration_of_a_merged_patient(): void
    {
        $this->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana P. Prado', 'birth_date' => '1990-01-02', 'sex' => 'female', 'confirm_new' => true])->assertSessionHasNoErrors();
        $duplicate = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('appointments.store', $this->unit), $this->booking(['patient_id' => $duplicate->id]))->assertSessionHasNoErrors();
        $booked = Appointment::withoutTenantScope()->sole();

        app(CurrentTenant::class)->set($this->unit->tenant);
        Patient::merge($this->patient, $duplicate, 'Mesma pessoa');
        app(CurrentTenant::class)->set(null);

        // Booking with the duplicate id, or rescheduling a booking made before the merge, lands on the primary.
        $this->post(route('appointments.store', $this->unit), $this->booking(['patient_id' => $duplicate->id, 'starts_at' => '2026-11-16T09:00']))->assertSessionHasNoErrors();
        $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => '2026-11-16T09:20', 'rescheduled_from_id' => $booked->id, 'reason' => 'Unificado']))->assertSessionHasNoErrors();
        $this->assertSame([$duplicate->id, $this->patient->id, $this->patient->id], Appointment::withoutTenantScope()->orderBy('id')->pluck('patient_id')->all());
    }

    public function test_arrival_records_the_modality_on_the_day_and_the_queue_reflects_it(): void
    {
        foreach (['08:20', '08:40', '09:00'] as $time) {
            $this->post(route('appointments.store', $this->unit), $this->booking(['starts_at' => "2026-11-16T{$time}"]))->assertSessionHasNoErrors();
        }
        [$first, $second, $third] = Appointment::withoutTenantScope()->orderBy('starts_at')->get()->all();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $insurer = Insurer::query()->create(['name' => 'Saúde Boa', 'ans_code' => '123456']);
        $inactive = Insurer::query()->create(['name' => 'Antigo', 'active' => false]);
        app(CurrentTenant::class)->set(null);

        // Only on the day of the consultation.
        $this->post(route('appointments.arrive', $first), ['modality' => 'private'])->assertSessionHasErrors('modality');
        $this->travelTo('2026-11-16 10:30:00');

        $this->post(route('appointments.arrive', $first), ['modality' => 'private'])->assertSessionHasNoErrors();
        $this->post(route('appointments.arrive', $first), ['modality' => 'sus'])->assertSessionHasErrors('modality');

        $card = ['modality' => 'insurance', 'insurer_id' => $insurer->id, 'insurance_card_number' => '0012 3456 7890', 'insurance_card_valid_until' => '2027-03-31'];
        $this->post(route('appointments.arrive', $second), ['modality' => 'insurance'])->assertSessionHasErrors(['insurer_id', 'insurance_card_number', 'insurance_card_valid_until']);
        $this->post(route('appointments.arrive', $second), [...$card, 'insurance_card_valid_until' => '2026-11-15'])->assertSessionHasErrors('insurance_card_valid_until');
        $this->post(route('appointments.arrive', $second), [...$card, 'insurer_id' => $inactive->id])->assertSessionHasErrors('insurer_id');
        $this->post(route('appointments.arrive', $second), $card)->assertSessionHasNoErrors();
        $second = Appointment::withoutTenantScope()->findOrFail($second->id);
        $this->assertSame(['arrived', 'insurance', $insurer->id, '0012 3456 7890', '2027-03-31'], [$second->status, $second->modality, $second->insurer_id, $second->insurance_card_number, $second->insurance_card_valid_until?->toDateString()]);
        $this->assertStringNotContainsString('7890', (string) json_encode(AuditEvent::where('action', 'appointment.arrived')->pluck('context')));

        // After arrival there is no cancelling nor no-show.
        $this->post(route('appointments.cancel', $first), ['reason' => 'Engano'])->assertSessionHasErrors('reason');
        $this->post(route('appointments.no-show', $first))->assertSessionHasErrors('starts_at');
        $this->post(route('appointments.cancel', $third), ['reason' => 'Desmarcou por telefone'])->assertSessionHasNoErrors();

        $this->get(route('appointments.queue'))->assertInertia(fn (Assert $page) => $page->component('appointments/queue')
            ->has('appointments', 3)->has('insurers', 1)
            ->where('appointments.0.status', 'arrived')->where('appointments.0.modality', 'private')->where('appointments.0.arrivedAt', '07:30')
            ->where('appointments.1.insurer', 'Saúde Boa')
            ->where('appointments.2.status', 'cancelled')->where('appointments.2.cancelReason', 'Desmarcou por telefone'));
    }

    public function test_only_the_units_reception_sees_the_queue_and_confirms_arrival(): void
    {
        $this->post(route('appointments.store', $this->unit), $this->booking())->assertSessionHasNoErrors();
        $appointment = Appointment::withoutTenantScope()->sole();
        $this->travelTo('2026-11-16 10:30:00');

        $this->actingAs($this->manager->user)->get(route('appointments.queue'))->assertForbidden();
        $this->post(route('appointments.arrive', $appointment), ['modality' => 'private'])->assertForbidden();
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Reception, $this->other);
        $this->actingAs($elsewhere->user)->post(route('appointments.arrive', $appointment), ['modality' => 'private'])->assertForbidden();
        $this->get(route('appointments.queue'))->assertInertia(fn (Assert $page) => $page->has('appointments', 0));
    }

    public function test_only_the_units_reception_books(): void
    {
        $this->actingAs($this->manager->user)->get(route('appointments.index'))->assertForbidden();
        $this->post(route('appointments.store', $this->unit), $this->booking())->assertForbidden();

        $this->actingAs($this->reception->user)->post(route('appointments.store', $this->other), $this->booking())->assertForbidden();
        $this->post(route('appointments.store', $this->unit), $this->booking())->assertSessionHasNoErrors();
        $appointment = Appointment::withoutTenantScope()->sole();

        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Reception, $this->other);
        $this->actingAs($elsewhere->user)->post(route('appointments.cancel', $appointment), ['reason' => 'x'])->assertForbidden();

        // Another client's desk does not even find the booking.
        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->create();
        $stranger->grant(Role::Reception, Unit::factory()->for($stranger->tenant)->create());
        $this->actingAs($stranger->user)->post(route('appointments.cancel', $appointment), ['reason' => 'x'])->assertNotFound();
    }

    public function test_the_database_refuses_two_live_bookings_in_the_same_slot(): void
    {
        $this->post(route('appointments.store', $this->unit), $this->booking())->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = (array) DB::table('appointments')->first();
        unset($row['id']);

        DB::table('appointments')->insert([...$row, 'walk_in' => true]);
        $this->expectException(QueryException::class);
        DB::table('appointments')->insert($row);
    }
}
