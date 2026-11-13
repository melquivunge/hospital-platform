<?php

namespace Tests\Feature\Portal;

use App\Http\Controllers\Portal\PortalAuthController;
use App\Models\Appointment;
use App\Models\EmergencyEpisode;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use App\Models\Visit;
use App\Models\VisitPass;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7b.2 (contract, "Regras de posição e tempo"): the position in the queue and the expected wait, the same on the
 * QR and in the signed-in portal.
 */
class PortalJourneyTest extends ClinicalTestCase
{
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;
    }

    private function registerPatient(string $name, string $birthDate): Patient
    {
        $this->actingAs($this->reception->user, 'web')->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => $name, 'birth_date' => $birthDate, 'sex' => 'male', 'confirm_new' => '1'])->assertSessionHasNoErrors();

        return Patient::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function token(string $route, int $id): string
    {
        /** @var array{token: string} $flash */
        $flash = $this->actingAs($this->reception->user, 'web')->post(route($route, $id))->getSession()?->get('visit_pass_token');

        return $flash['token'];
    }

    public function test_the_appointment_queue_counts_those_who_arrived_for_the_same_professional(): void
    {
        foreach (['08:20', '08:40'] as $time) {
            $this->actingAs($this->reception->user, 'web')->post(route('appointments.store', $this->unit), ['professional_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'starts_at' => "2026-11-16T{$time}"])->assertSessionHasNoErrors();
        }
        [$first, $second] = Appointment::withoutTenantScope()->orderBy('starts_at')->get()->all();
        $this->travelTo('2026-11-16 10:30:00');
        foreach ([$second, $first] as $appointment) {
            $this->post(route('appointments.arrive', $appointment), ['modality' => 'private'])->assertSessionHasNoErrors();
        }
        $token = $this->token('visit-passes.appointment', $second->id);

        // One ahead and no visit closed today: the slot of the agenda (20 min).
        $this->get(route('portal.visit', ['portal' => $this->slug, 'token' => $token]))->assertInertia(fn (Assert $page) => $page
            ->where('visit.position', 2)->where('visit.waitMinutes', 20)->where('visit.kind', 'appointment'));

        // The first is seen in 12 minutes: the average replaces the slot, then the second is next.
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('visits.open', $first))->assertSessionHasNoErrors();
        $this->get(route('portal.visit', ['portal' => $this->slug, 'token' => $token]))->assertInertia(fn (Assert $page) => $page->where('visit.position', 1)->where('visit.waitMinutes', 0));
        $this->travel(12)->minutes();
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('visits.close', Visit::withoutTenantScope()->sole()))->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('visits.open', $second))->assertSessionHasNoErrors();
        $this->get(route('portal.visit', ['portal' => $this->slug, 'token' => $token]))->assertInertia(fn (Assert $page) => $page->where('visit.position', null)->where('visit.current', 2));
    }

    public function test_the_emergency_queue_follows_severity_with_the_target_as_ceiling(): void
    {
        $other = $this->registerPatient('Caio Lima', '1985-03-04');
        $this->actingAs($this->reception->user, 'web')->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $this->travel(5)->minutes();
        $this->post(route('emergency.arrive', $this->unit), ['patient_id' => $other->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        [$mine, $theirs] = EmergencyEpisode::withoutTenantScope()->orderBy('id')->get()->all();
        $token = $this->token('visit-passes.episode', $mine->id);

        // Not classified yet: no position.
        $this->get(route('portal.visit', ['portal' => $this->slug, 'token' => $token]))->assertInertia(fn (Assert $page) => $page->where('visit.position', null)->where('visit.current', 0));

        // Arrived later but more serious: goes ahead. The yellow target (60 min) is the ceiling.
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('emergency.triage', $mine), ['level' => 3, 'complaint' => 'Febre.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.triage', $theirs), ['level' => 2, 'complaint' => 'Dor no peito.'])->assertSessionHasNoErrors();
        $this->get(route('portal.visit', ['portal' => $this->slug, 'token' => $token]))->assertInertia(fn (Assert $page) => $page
            ->where('visit.position', 2)->where('visit.waitMinutes', 60)->where('visit.level', 'Urgente'));

        $this->post(route('emergency.call', $theirs))->assertSessionHasNoErrors();
        $this->get(route('portal.visit', ['portal' => $this->slug, 'token' => $token]))->assertInertia(fn (Assert $page) => $page->where('visit.position', 1));
    }

    public function test_the_signed_in_portal_shows_the_arrivals_of_the_patient_and_of_the_linked_minors(): void
    {
        $minor = $this->registerPatient('Pedro Prado', '2015-05-06');
        $stranger = $this->registerPatient('Caio Lima', '1985-03-04');
        foreach ([$minor, $stranger] as $patient) {
            $this->post(route('emergency.arrive', $this->unit), ['patient_id' => $patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        }
        $this->patient->forceFill(['email' => 'joana@example.com'])->save();
        $account = new PatientAccount;
        $account->forceFill(['tenant_id' => $this->patient->tenant_id, 'patient_id' => $this->patient->id, 'email' => 'joana@example.com', 'password' => 'Senha-forte-123'])->save();
        (new PatientAccountLink)->forceFill(['tenant_id' => $this->patient->tenant_id, 'account_id' => $account->id, 'patient_id' => $minor->id, 'event' => 'linked', 'actor_user_id' => $this->reception->user_id])->save();
        $this->arrived($this->doctor, $this->unit, modality: ['modality' => 'private']);

        auth()->guard('web')->logout();
        $this->actingAs($account, 'patient')->withSession(['portal.last_seen' => now()->getTimestamp(), 'portal.password' => PortalAuthController::passwordFingerprint($account)]);
        $this->get(route('portal.home', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('visits', 2));
        $this->get(route('portal.visits', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->component('portal/visits')
            ->has('visits', 2)
            ->where('visits.0.kind', 'appointment')->where('visits.0.who', null)->where('visits.0.position', 1)
            ->where('visits.1.kind', 'emergency')->where('visits.1.who', 'Pedro Prado')
            ->missing('visits.0.endedAt'));

        // Discharged: still there for 12 hours, then gone.
        $episode = EmergencyEpisode::withoutTenantScope()->where('patient_id', $minor->id)->sole();
        $episode->forceFill(['status' => 'discharged', 'ended_at' => now(), 'ended_by_user_id' => $this->doctorAccount->user_id])->save();
        $this->get(route('portal.visits', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('visits.1.ended', true)->where('visits.1.current', 4));
        $this->travel(VisitPass::HOURS_AFTER_END + 1)->hours();
        $this->withSession(['portal.last_seen' => now()->getTimestamp()])->get(route('portal.visits', ['portal' => $this->slug]))
            ->assertInertia(fn (Assert $page) => $page->has('visits', 1)->where('visits.0.who', null));
    }
}
