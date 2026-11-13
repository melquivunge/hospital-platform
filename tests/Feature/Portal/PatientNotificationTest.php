<?php

namespace Tests\Feature\Portal;

use App\Http\Controllers\Portal\PortalAuthController;
use App\Jobs\DeliverNotifications;
use App\Models\Appointment;
use App\Models\EmergencyEpisode;
use App\Models\Exam;
use App\Models\ExamOrderItem;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use App\Models\PatientNotification;
use App\Models\Visit;
use App\Notifications\PortalNews;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7b.3 (contract, decision 6): the portal's inbox from the same audit events as the staff's, for the patient and
 * the guardians of a linked minor; an e-mail with no content for the appointment, the result and the discharge.
 */
class PatientNotificationTest extends ClinicalTestCase
{
    private string $slug;

    private PatientAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;
        $this->patient->forceFill(['email' => 'joana@example.com'])->save();
        $this->account = $this->account($this->patient, 'joana@example.com');
    }

    private function deliver(): void
    {
        (new DeliverNotifications)->handle();
    }

    private function account(Patient $patient, string $email): PatientAccount
    {
        $account = new PatientAccount;
        $account->forceFill(['tenant_id' => $patient->tenant_id, 'patient_id' => $patient->id, 'email' => $email, 'password' => 'Senha-forte-123'])->save();

        return $account;
    }

    /**
     * @return list<string>
     */
    private function kinds(PatientAccount $account): array
    {
        $this->deliver();

        return PatientNotification::withoutTenantScope()->where('account_id', $account->id)->orderBy('id')->pluck('kind')->all();
    }

    public function test_a_booked_appointment_care_and_the_next_in_line_reach_the_portal(): void
    {
        $other = $this->registerPatient('Caio Lima');
        $caio = $this->account($other, 'caio@example.com');
        foreach ([[$this->patient, '08:20'], [$other, '08:40']] as [$patient, $time]) {
            $this->actingAs($this->reception->user, 'web')->post(route('appointments.store', $this->unit), ['professional_id' => $this->doctor->id, 'patient_id' => $patient->id, 'starts_at' => "2026-11-16T{$time}"])->assertSessionHasNoErrors();
        }
        $this->assertSame(['appointment'], $this->kinds($this->account));
        // The e-mail says only that there is news, never what.
        Notification::assertSentOnDemand(PortalNews::class, fn (PortalNews $news, array $channels, $notifiable) => $notifiable->routes['mail'] === 'joana@example.com'
            && ! str_contains($news->toMail($notifiable)->subject ?? '', 'consulta'));

        $this->travelTo('2026-11-16 10:30:00');
        foreach (Appointment::withoutTenantScope()->orderBy('starts_at')->get() as $appointment) {
            $this->post(route('appointments.arrive', $appointment), ['modality' => 'private'])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('visits.open', Appointment::withoutTenantScope()->where('patient_id', $this->patient->id)->sole()))->assertSessionHasNoErrors();
        $this->assertSame(['appointment', 'started'], $this->kinds($this->account));
        $this->assertSame(['appointment', 'next'], $this->kinds($caio));
    }

    public function test_the_guardian_hears_of_the_minor_and_a_discharge_is_told(): void
    {
        $minor = $this->registerPatient('Pedro Prado', '2015-05-06');
        (new PatientAccountLink)->forceFill(['tenant_id' => $minor->tenant_id, 'account_id' => $this->account->id, 'patient_id' => $minor->id, 'event' => 'linked', 'actor_user_id' => $this->reception->user_id])->save();
        $this->actingAs($this->reception->user, 'web')->post(route('emergency.arrive', $this->unit), ['patient_id' => $minor->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('emergency.triage', $episode), ['level' => 4, 'complaint' => 'Tosse.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->post(route('visits.close', Visit::withoutTenantScope()->sole()), ['cid_principal' => 'J06.9', 'outcome' => 'discharged'])->assertSessionHasNoErrors();

        $this->assertSame(['started', 'discharge'], $this->kinds($this->account));
        $this->assertSame(
            ['Menor sob sua responsabilidade: Seu atendimento começou', 'Menor sob sua responsabilidade: Sua alta do pronto-socorro foi registrada'],
            PatientNotification::withoutTenantScope()->orderBy('id')->pluck('title')->all(),
        );
    }

    public function test_a_released_result_is_told_but_a_critical_one_waits_for_the_team(): void
    {
        app(CurrentTenant::class)->set($this->patient->tenant);
        $blood = Exam::query()->create(['type' => 'laboratory', 'name' => 'Hemograma', 'specimen' => 'Sangue']);
        $hb = $blood->analytes()->create(['name' => 'Hemoglobina', 'unit' => 'g/dL', 'reference_min' => 12, 'reference_max' => 16, 'position' => 1]);
        app(CurrentTenant::class)->set(null);
        $lab = Membership::factory()->for($this->unit->tenant)->create();
        $lab->grant(Role::Laboratory, $this->unit);
        $this->actingAs($this->reception->user, 'web')->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Febre.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        foreach ([1, 2] as $order) {
            $this->post(route('exam-orders.visit.store', Visit::withoutTenantScope()->sole()), ['exam_ids' => [$blood->id], 'priority' => 'routine', 'indication' => 'Febre.', 'password' => 'password'])->assertSessionHasNoErrors();
        }
        [$normal, $critical] = ExamOrderItem::withoutGlobalScopes()->orderBy('id')->get()->all();
        $this->actingAs($lab->user, 'web');
        foreach ([[$normal, '13,5', null], [$critical, '5,1', '1']] as [$item, $value, $flag]) {
            $this->post(route('exam-queue.collect', $item))->assertSessionHasNoErrors();
            $this->post(route('exam-results.release', $item), array_filter(['version' => 1, 'password' => 'password', 'values' => [$hb->id => $value], 'critical' => $flag]))->assertSessionHasNoErrors();
        }

        $this->assertSame(['started', 'result'], $this->kinds($this->account));
    }

    public function test_the_inbox_lists_the_notices_and_marks_them_read(): void
    {
        $this->actingAs($this->reception->user, 'web')->post(route('appointments.store', $this->unit), ['professional_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'starts_at' => '2026-11-16T08:20'])->assertSessionHasNoErrors();
        $this->deliver();
        auth()->guard('web')->logout();
        $this->actingAs($this->account, 'patient')->withSession(['portal.last_seen' => now()->getTimestamp(), 'portal.password' => PortalAuthController::passwordFingerprint($this->account)]);

        $this->get(route('portal.home', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('unread', 1));
        $this->get(route('portal.notifications', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->component('portal/notifications')
            ->where('notices.0.title', 'Uma consulta foi marcada')->where('notices.0.unread', true)->where('unread', 0));
        $this->get(route('portal.notifications', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('notices.0.unread', false));
    }

    private function registerPatient(string $name, string $birthDate = '1985-03-04'): Patient
    {
        $this->actingAs($this->reception->user, 'web')->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => $name, 'birth_date' => $birthDate, 'sex' => 'male', 'confirm_new' => '1'])->assertSessionHasNoErrors();

        return Patient::withoutTenantScope()->latest('id')->firstOrFail();
    }
}
