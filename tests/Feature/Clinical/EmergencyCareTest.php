<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\ClinicalNote;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class EmergencyCareTest extends ClinicalTestCase
{
    /**
     * Arrived at the doctor's unit and, unless told otherwise, classified by the doctor.
     */
    private function waiting(bool $classified = true): EmergencyEpisode
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        if ($classified) {
            $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Febre há 3 dias.'])->assertSessionHasNoErrors();
        }

        return $episode;
    }

    /**
     * A second professional with an approved qualification at the doctor's unit.
     */
    private function colleague(): Membership
    {
        $account = Membership::factory()->for($this->unit->tenant)->create();
        $account->grant(Role::Professional, $this->unit);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Dr. Caio']);
        $professional->forceFill(['membership_id' => $account->id])->save();
        Qualification::request($professional, $this->unit, ['job_function_id' => JobFunction::query()->firstOrFail()->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [Sector::query()->where('unit_id', $this->unit->id)->firstOrFail()->id])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);

        return $account;
    }

    public function test_the_doctor_calls_attends_and_discharges_with_the_delivery_3_record(): void
    {
        $episode = $this->waiting();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.call', $episode));
        $visit = Visit::withoutTenantScope()->sole();
        $this->assertSame([$episode->id, null, $this->doctor->id], [$visit->emergency_episode_id, $visit->appointment_id, $visit->professional_id]);
        $this->assertSame('in_care', $episode->fresh()->status);

        // Same notes, signature and read trail as the outpatient visit.
        $this->get(route('visits.show', $visit))->assertInertia(fn (Assert $page) => $page->component('visits/show')->where('visit.emergency', true));
        $this->assertSame(1, DB::table('clinical_reads')->where('visit_id', $visit->id)->count());
        $this->post(route('visits.notes.store', $visit), ['body' => 'Hidratação e antitérmico.']);
        $note = ClinicalNote::withoutTenantScope()->sole();

        $this->post(route('visits.close', $visit))->assertSessionHasErrors('outcome');
        $this->post(route('visits.close', $visit), ['outcome' => 'discharged'])->assertSessionHasErrors('visit');
        $this->post(route('clinical-notes.sign', $note), ['body' => 'Hidratação e antitérmico.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->post(route('visits.close', $visit), ['outcome' => 'discharged'])->assertRedirect(route('emergency.index', ['unit' => $this->unit->id]));

        $episode = $episode->fresh();
        $this->assertSame(['discharged', $this->doctorAccount->user_id], [$episode->status, $episode->ended_by_user_id]);
        $this->assertNotNull($episode->ended_at);
        $this->assertSame('discharged', AuditEvent::where('action', 'emergency.ended')->sole()->context['outcome']);
        // The record no longer opens, and the patient can arrive again.
        $this->get(route('clinical-record.show', $this->patient))->assertRedirect(route('clinical-record.emergency', $this->patient));
        $this->waiting(false);
    }

    public function test_two_calls_end_in_one_visit_and_only_qualified_professionals_call_classified_patients(): void
    {
        $episode = $this->waiting(false);
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.call', $episode))->assertSessionHasErrors('call');

        $this->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        foreach ([$this->reception, $this->manager, $this->nurseAccount] as $member) {
            $this->actingAs($member->user)->post(route('emergency.call', $episode))->assertForbidden();
        }

        $this->actingAs($this->doctorAccount->user)->post(route('emergency.call', $episode));
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('emergency.call', $episode))->assertRedirect(route('visits.show', $visit));
        $this->actingAs($this->colleague()->user)->post(route('emergency.call', $episode))->assertSessionHasErrors(['call' => 'Este paciente já está em atendimento com Dra. Ana.']);
        $this->assertSame(1, Visit::withoutTenantScope()->count());
        $this->assertSame(1, AuditEvent::where('action', 'visit.opened')->count());

        // The queue tells who attends; only the attending doctor gets the way back to the visit.
        $this->get(route('emergency.index'))->assertInertia(fn (Assert $page) => $page
            ->where('episodes.0.status', 'in_care')->where('episodes.0.attendedBy', 'Dra. Ana')->where('episodes.0.myVisitId', null));
        $this->actingAs($this->doctorAccount->user)->get(route('emergency.index'))->assertInertia(fn (Assert $page) => $page->where('episodes.0.myVisitId', $visit->id));
        $this->get(route('visits.index'))->assertInertia(fn (Assert $page) => $page->has('emergencies', 1)->where('emergencies.0.id', $visit->id));
    }

    public function test_evasion_from_the_queue_and_the_other_outcomes(): void
    {
        $episode = $this->waiting();
        $this->actingAs($this->manager->user)->post(route('emergency.evade', $episode))->assertForbidden();
        $this->actingAs($this->reception->user)->post(route('emergency.evade', $episode))->assertSessionHasNoErrors();
        $this->assertSame(['evaded', $this->reception->user_id], [$episode->fresh()->status, $episode->fresh()->ended_by_user_id]);
        $this->post(route('emergency.evade', $episode))->assertSessionHasErrors('call');

        // In care, evasion from the queue is refused: the doctor records it as the outcome.
        $second = $this->waiting();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.call', $second));
        $this->actingAs($this->reception->user)->post(route('emergency.evade', $second))->assertSessionHasErrors('call');
        $this->actingAs($this->doctorAccount->user)->post(route('visits.close', Visit::withoutTenantScope()->sole()), ['outcome' => 'evaded'])->assertSessionHasNoErrors();
        $this->assertSame('evaded', $second->fresh()->status);

        // An admission request (sector and doctor, step 4.4a) keeps the patient in the department until the bed.
        $ward = Sector::query()->withoutGlobalScopes()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $ward->id, 'room' => '1', 'code' => '1-A', 'type' => 'ward'])->assertSessionHasNoErrors();
        $third = $this->waiting();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.call', $third));
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()), ['outcome' => 'admission_requested', 'requested_sector_id' => $ward->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $this->assertSame('admission_requested', $third->fresh()->status);
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->other), ['patient_id' => $this->patient->id])->assertSessionHasErrors('patient_id');
        $this->get(route('emergency.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page->where('episodes.0.status', 'admission_requested'));
    }

    public function test_the_database_keeps_one_origin_and_never_moves_a_visit(): void
    {
        $episode = $this->waiting();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.call', $episode));
        $appointment = $this->arrived($this->doctor, $this->unit);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = (array) DB::table('visits')->first();
        unset($row['id']);

        foreach ([
            fn () => DB::table('visits')->insert([...$row, 'emergency_episode_id' => null]),
            fn () => DB::table('visits')->insert([...$row, 'appointment_id' => $appointment->id]),
            // From NULL to a value: `<>` would let it through; the guard compares with IS DISTINCT FROM.
            fn () => DB::table('visits')->update(['appointment_id' => $appointment->id, 'emergency_episode_id' => null]),
            fn () => DB::table('emergency_episodes')->update(['status' => 'discharged']),
        ] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('The database accepted a change it must refuse.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['P0001', '23514']);
            }
        }
    }
}
