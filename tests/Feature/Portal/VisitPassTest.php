<?php

namespace Tests\Feature\Portal;

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Membership;
use App\Models\PatientAccount;
use App\Models\Visit;
use App\Models\VisitPass;
use App\Tenancy\Role;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7b.1 (contract, decision 4): the reception hands over a QR on arrival; it opens only that arrival's journey,
 * without an account, name or results, until revoked or 12 hours after the end.
 */
class VisitPassTest extends ClinicalTestCase
{
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;
    }

    /**
     * @param  array<string, int>  $route
     */
    private function issue(string $name, array $route): string
    {
        $response = $this->actingAs($this->reception->user, 'web')->post(route($name, $route));
        $pass = VisitPass::withoutTenantScope()->latest('id')->firstOrFail();
        $response->assertRedirect(route('visit-passes.show', $pass));
        /** @var array{id: int, token: string} $flash */
        $flash = $response->getSession()?->get('visit_pass_token');

        return $flash['token'];
    }

    private function journey(string $token): TestResponse
    {
        auth()->guard('web')->logout();

        return $this->get(route('portal.visit', ['portal' => $this->slug, 'token' => $token]));
    }

    public function test_an_arrived_appointment_gets_a_qr_with_its_journey_only(): void
    {
        $appointment = $this->arrived($this->doctor, $this->unit, modality: ['modality' => 'private']);
        $token = $this->issue('visit-passes.appointment', ['appointment' => $appointment->id]);
        $pass = VisitPass::withoutTenantScope()->sole();
        $this->assertNotSame($token, $pass->token_hash);
        $this->assertSame(1, AuditEvent::where('action', 'visit_pass.created')->count());

        // The QR is on the page once, right after it is made; a later visit has no token.
        $this->get(route('visit-passes.show', $pass))->assertInertia(fn (Assert $page) => $page->has('qr')->where('url', fn (string $url) => str_ends_with($url, "/portal/{$this->slug}/visita/{$token}")));
        $this->get(route('visit-passes.show', $pass))->assertInertia(fn (Assert $page) => $page->where('qr', null)->where('url', null));

        $this->journey($token)->assertInertia(fn (Assert $page) => $page->component('portal/visit')
            ->where('visit.unit', $this->unit->name)
            ->where('visit.current', 1)
            ->where('visit.steps', ['Chegou', 'Aguardando', 'Em atendimento', 'Encerrado'])
            ->missing('visit.patient'));
        $this->journey('outro-token')->assertInertia(fn (Assert $page) => $page->where('visit', null));
        $this->journey($token)->assertHeader('Referrer-Policy', 'no-referrer');

        // Seen and discharged: the visit closes, the QR still works for 12 hours, then not.
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('visits.open', $appointment))->assertSessionHasNoErrors();
        $this->journey($token)->assertInertia(fn (Assert $page) => $page->where('visit.current', 2));
        $visit = Visit::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('visits.close', $visit))->assertSessionHasNoErrors();
        $this->journey($token)->assertInertia(fn (Assert $page) => $page->where('visit.current', 3));
        $this->travel(13)->hours();
        $this->journey($token)->assertInertia(fn (Assert $page) => $page->where('visit', null));
    }

    public function test_no_qr_before_the_arrival_or_outside_the_receptions_units(): void
    {
        $this->actingAs($this->reception->user, 'web')->post(route('appointments.store', $this->unit), ['professional_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'starts_at' => '2026-11-16T09:00'])->assertSessionHasNoErrors();
        $scheduled = Appointment::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('visit-passes.appointment', $scheduled))->assertSessionHasErrors(['pass' => 'Confirme a chegada antes de gerar o QR.']);

        $lab = Membership::factory()->for($this->unit->tenant)->create();
        $lab->grant(Role::Laboratory, $this->unit);
        $this->actingAs($lab->user, 'web')->post(route('visit-passes.appointment', $scheduled))->assertForbidden();
        $this->assertSame(0, VisitPass::withoutTenantScope()->count());
    }

    public function test_the_emergency_journey_follows_the_episode_until_12_hours_after_the_end(): void
    {
        $this->actingAs($this->reception->user, 'web')->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $token = $this->issue('visit-passes.episode', ['episode' => $episode->id]);
        $this->journey($token)->assertInertia(fn (Assert $page) => $page->where('visit.current', 0)->where('visit.steps.4', 'Alta'));

        $this->actingAs($this->doctorAccount->user, 'web')->post(route('emergency.triage', $episode), ['level' => 3, 'complaint' => 'Febre.'])->assertSessionHasNoErrors();
        $this->journey($token)->assertInertia(fn (Assert $page) => $page->where('visit.current', 2));
        $this->actingAs($this->doctorAccount->user, 'web')->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->journey($token)->assertInertia(fn (Assert $page) => $page->where('visit.current', 3));

        // The patient leaves: still visible for 12 hours, then gone.
        $episode->refresh()->forceFill(['status' => 'discharged', 'ended_at' => now(), 'ended_by_user_id' => $this->doctorAccount->user_id])->save();
        $this->travel(11)->hours();
        $this->journey($token)->assertInertia(fn (Assert $page) => $page->where('visit.current', 4)->where('visit.steps.4', 'Alta'));
        $this->travel(2)->hours();
        $this->journey($token)->assertInertia(fn (Assert $page) => $page->where('visit', null));
    }

    public function test_an_old_portal_session_on_the_phone_does_not_block_the_qr(): void
    {
        $this->actingAs($this->reception->user, 'web')->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $token = $this->issue('visit-passes.episode', ['episode' => EmergencyEpisode::withoutTenantScope()->sole()->id]);
        $this->patient->forceFill(['email' => 'joana@example.com'])->save();
        $account = new PatientAccount;
        $account->forceFill(['tenant_id' => $this->patient->tenant_id, 'patient_id' => $this->patient->id, 'email' => 'joana@example.com', 'password' => 'Senha-forte-123'])->save();

        auth()->guard('web')->logout();
        // A portal session idle for hours: it ends, and the QR still opens.
        $this->actingAs($account, 'patient')->withSession(['portal.last_seen' => now()->subHours(3)->getTimestamp()]);
        $this->get(route('portal.visit', ['portal' => $this->slug, 'token' => $token]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('visit.current', 0));
        $this->assertGuest('patient');
    }

    public function test_a_new_qr_or_a_revocation_ends_the_old_one(): void
    {
        $this->actingAs($this->reception->user, 'web')->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $first = $this->issue('visit-passes.episode', ['episode' => $episode->id]);
        $second = $this->issue('visit-passes.episode', ['episode' => $episode->id]);
        $this->journey($first)->assertInertia(fn (Assert $page) => $page->where('visit', null));
        $this->journey($second)->assertInertia(fn (Assert $page) => $page->where('visit.current', 0));

        $pass = VisitPass::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->reception->user, 'web')->post(route('visit-passes.revoke', $pass))->assertSessionHasNoErrors();
        $this->post(route('visit-passes.revoke', $pass))->assertNotFound();
        $this->journey($second)->assertInertia(fn (Assert $page) => $page->where('visit', null));
        $this->assertSame(1, AuditEvent::where('action', 'visit_pass.revoked')->count());
    }
}
