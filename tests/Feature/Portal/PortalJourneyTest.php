<?php

namespace Tests\Feature\Portal;

use App\Http\Controllers\Portal\PortalAuthController;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\Exam;
use App\Models\ExamOrderItem;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use App\Models\Sector;
use App\Models\Visit;
use App\Models\VisitPass;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Support\Facades\Storage;
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
        foreach (['08:20', '08:40', '09:00', '09:20'] as $time) {
            $this->actingAs($this->reception->user, 'web')->post(route('appointments.store', $this->unit), ['professional_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'starts_at' => "2026-11-16T{$time}"])->assertSessionHasNoErrors();
        }
        // Someone waiting for another professional does not count.
        $this->post(route('appointments.store', $this->other), ['professional_id' => $this->nurse->id, 'patient_id' => $this->patient->id, 'starts_at' => '2026-11-16T08:00'])->assertSessionHasNoErrors();
        $appointments = Appointment::withoutTenantScope()->where('professional_id', $this->doctor->id)->orderBy('starts_at')->get()->all();
        $this->travelTo('2026-11-16 10:30:00');
        foreach (Appointment::withoutTenantScope()->get() as $appointment) {
            $this->post(route('appointments.arrive', $appointment), ['modality' => 'private'])->assertSessionHasNoErrors();
        }
        [$first, $second, , $last] = $appointments;
        $token = $this->token('visit-passes.appointment', $last->id);
        $journey = fn () => $this->get(route('portal.visit', ['portal' => $this->slug, 'token' => $token]));

        // Three ahead and no visit closed today: the slot of the agenda (20 min) each.
        $journey()->assertInertia(fn (Assert $page) => $page->where('visit.position', 4)->where('visit.waitMinutes', 60)->where('visit.kind', 'appointment'));

        // The first is seen in 12 minutes: the day's average replaces the slot.
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('visits.open', $first))->assertSessionHasNoErrors();
        $this->travel(12)->minutes();
        $this->post(route('visits.close', Visit::withoutTenantScope()->sole()))->assertSessionHasNoErrors();
        $this->post(route('visits.open', $second))->assertSessionHasNoErrors();
        $journey()->assertInertia(fn (Assert $page) => $page->where('visit.position', 2)->where('visit.waitMinutes', 12));
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

    public function test_the_signed_in_portal_follows_the_exams_and_the_admission(): void
    {
        Storage::fake('local');
        app(CurrentTenant::class)->set($this->patient->tenant);
        $blood = Exam::query()->create(['type' => 'laboratory', 'name' => 'Hemograma', 'specimen' => 'Sangue']);
        $hb = $blood->analytes()->create(['name' => 'Hemoglobina', 'unit' => 'g/dL', 'reference_min' => 12, 'reference_max' => 16, 'position' => 1]);
        app(CurrentTenant::class)->set(null);
        $lab = Membership::factory()->for($this->unit->tenant)->create();
        $lab->grant(Role::Laboratory, $this->unit);
        $beds = Membership::factory()->for($this->unit->tenant)->create();
        $beds->grant(Role::BedManagement, $this->unit);
        $ward = Sector::withoutGlobalScopes()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->actingAs($this->manager->user, 'web')->post(route('beds.store', $this->unit), ['sector_id' => $ward->id, 'room' => '10', 'code' => '10-A', 'type' => 'ward'])->assertSessionHasNoErrors();

        // Emergency visit: an exam is ordered and an admission requested.
        $this->actingAs($this->reception->user, 'web')->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $visit = Visit::withoutTenantScope()->sole();
        $this->post(route('exam-orders.visit.store', $visit), ['exam_ids' => [$blood->id], 'priority' => 'routine', 'indication' => 'Dispneia.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->post(route('visits.close', $visit), ['cid_principal' => 'J18.9', 'outcome' => 'admission_requested', 'requested_sector_id' => $ward->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();

        $account = $this->account();
        $visits = fn () => $this->actingAs($account, 'patient')->get(route('portal.visits', ['portal' => $this->slug]));
        $this->get(route('portal.home', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('visits', 3));
        $visits()->assertInertia(fn (Assert $page) => $page->has('visits', 3)
            ->where('visits.1.kind', 'admission')->where('visits.1.current', 0)->where('visits.1.detail', $ward->name)->where('visits.1.position', null)
            ->where('visits.2.kind', 'exam')->where('visits.2.current', 0)->where('visits.2.detail', 'Hemograma'));

        // The bed is allocated, the blood collected and the result released.
        $admission = Admission::withoutTenantScope()->sole();
        $this->actingAs($beds->user, 'web')->post(route('admissions.allocate', $admission), ['bed_id' => Bed::withoutGlobalScopes()->sole()->id])->assertSessionHasNoErrors();
        $item = ExamOrderItem::withoutGlobalScopes()->sole();
        $this->actingAs($lab->user, 'web')->post(route('exam-queue.collect', $item))->assertSessionHasNoErrors();
        $visits()->assertInertia(fn (Assert $page) => $page->where('visits.1.current', 1)->where('visits.1.detail', "{$ward->name} · quarto 10, leito 10-A")->where('visits.2.current', 2));
        $this->actingAs($lab->user, 'web')->post(route('exam-results.release', $item), ['version' => 1, 'password' => 'password', 'values' => [$hb->id => '13,5']])->assertSessionHasNoErrors();
        $visits()->assertInertia(fn (Assert $page) => $page->where('visits.2.current', 3)->where('visits.2.ended', true));
        // Only the stay goes on: the emergency episode ended with the bed, the exam with its result.
        $this->get(route('portal.home', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('visits', 1));
    }

    private function account(): PatientAccount
    {
        auth()->guard('web')->logout();
        $this->patient->forceFill(['email' => 'joana@example.com'])->save();
        $account = new PatientAccount;
        $account->forceFill(['tenant_id' => $this->patient->tenant_id, 'patient_id' => $this->patient->id, 'email' => 'joana@example.com', 'password' => 'Senha-forte-123'])->save();
        $this->actingAs($account, 'patient')->withSession(['portal.last_seen' => now()->getTimestamp(), 'portal.password' => PortalAuthController::passwordFingerprint($account)]);

        return $account;
    }
}
