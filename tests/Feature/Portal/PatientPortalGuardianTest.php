<?php

namespace Tests\Feature\Portal;

use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use App\Notifications\PortalAccessLink;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7a.2 (ADR-0012): the reception links a guardian's portal account to a minor; the guardian sees the minor
 * in "Filhos menores" until the link ends or the minor turns 18. A stranger gets 404, the staff never gets in.
 */
class PatientPortalGuardianTest extends ClinicalTestCase
{
    private string $slug;

    private Patient $minor;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;
        // Joana (the clinical fixture, an adult) is the guardian; Pedro turns 18 on 15/11/2026.
        $this->patient->forceFill(['cpf' => '52998224725', 'email' => 'joana@example.com'])->save();
        $this->minor = $this->registerPatient('Pedro Prado', '2008-11-15');
        $this->activateAccount($this->patient);
    }

    private function registerPatient(string $name, string $birthDate): Patient
    {
        $this->actingAs($this->reception->user, 'web')->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => $name, 'birth_date' => $birthDate, 'sex' => 'male'])->assertSessionHasNoErrors();
        auth()->guard('web')->logout();

        return Patient::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function activateAccount(Patient $patient): PatientAccount
    {
        $patient->forceFill(['status' => 'complete'])->save();
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $patient), ['document_checked' => '1'])->assertSessionHasNoErrors();
        auth()->guard('web')->logout();
        $token = '';
        Notification::assertSentOnDemand(PortalAccessLink::class, function (PortalAccessLink $notification, array $channels, $notifiable) use (&$token, $patient) {
            if ($notifiable->routes['mail'] === $patient->email) {
                $token = basename($notification->url);
            }

            return true;
        });
        $this->post(route('portal.setup.store', ['portal' => $this->slug, 'token' => $token]), ['password' => 'Senha-forte-123', 'password_confirmation' => 'Senha-forte-123']);
        auth()->guard('patient')->logout();

        return PatientAccount::withoutTenantScope()->where('patient_id', $patient->id)->sole();
    }

    private function link(string $email = 'joana@example.com', ?Patient $patient = null): TestResponse
    {
        return $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-guardians.store', $patient ?? $this->minor), ['email' => $email, 'document_checked' => '1']);
    }

    private function asGuardian(): void
    {
        auth()->guard('web')->logout();
        $this->actingAs(PatientAccount::withoutTenantScope()->where('patient_id', $this->patient->id)->sole(), 'patient')
            ->withSession(['portal.last_seen' => now()->getTimestamp()]);
    }

    private function childUrl(?int $id = null): string
    {
        return route('portal.child', ['portal' => $this->slug, 'patient' => $id ?? $this->minor->id]);
    }

    public function test_the_guardian_sees_the_linked_minor(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->get(route('patients.show', $this->minor))->assertInertia(fn (Assert $page) => $page->where('portalGuardians.0.email', 'joana@example.com'));

        $this->asGuardian();
        $this->get(route('portal.home', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('children', [['id' => $this->minor->id, 'name' => 'Pedro Prado']]));
        $this->get($this->childUrl())->assertInertia(fn (Assert $page) => $page->component('portal/child')->where('child.name', 'Pedro Prado'));

        $link = PatientAccountLink::withoutTenantScope()->sole();
        $this->assertSame(['account_id' => $link->account_id, 'patient_id' => $this->minor->id], AuditEvent::where('action', 'patient_portal.guardian_linked')->sole()->context);
    }

    public function test_a_stranger_gets_404_and_the_staff_never_gets_in(): void
    {
        $this->link()->assertSessionHasNoErrors();

        // Another adult patient with a portal account, not linked to Pedro.
        $other = $this->registerPatient('Rui Costa', '1970-01-01');
        $other->forceFill(['cpf' => '11144477735', 'email' => 'rui@example.com'])->save();
        $this->actingAs($this->activateAccount($other), 'patient');
        $this->get($this->childUrl())->assertNotFound();
        $this->get(route('portal.home', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('children', []));
        // Nor the guardian's own record id through the minors' address.
        $this->get($this->childUrl($this->patient->id))->assertNotFound();
        auth()->guard('patient')->logout();

        // A staff session is not a portal session.
        $this->actingAs($this->reception->user, 'web')->get($this->childUrl())->assertRedirect(route('portal.login', ['portal' => $this->slug]));
    }

    public function test_ending_the_link_removes_the_minor_and_a_new_link_brings_it_back(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $account = PatientAccount::withoutTenantScope()->where('patient_id', $this->patient->id)->sole();
        $this->delete(route('patients.portal-guardians.destroy', [$this->minor, $account]))->assertSessionHasNoErrors();
        // Ending twice: nothing to end.
        $this->delete(route('patients.portal-guardians.destroy', [$this->minor, $account]))->assertNotFound();

        $this->asGuardian();
        $this->get($this->childUrl())->assertNotFound();

        $this->link()->assertSessionHasNoErrors();
        $this->asGuardian();
        $this->get($this->childUrl())->assertOk();
        $this->assertSame(['linked', 'ended', 'linked'], PatientAccountLink::withoutTenantScope()->orderBy('id')->pluck('event')->all());
    }

    public function test_the_link_stops_counting_at_18(): void
    {
        $this->link()->assertSessionHasNoErrors();
        $this->travelTo('2026-11-15 00:00:00');

        $this->asGuardian();
        $this->get($this->childUrl())->assertNotFound();
        $this->get(route('portal.home', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('children', []));

        // Now an adult: the reception sees the portal invitation instead of guardians.
        auth()->guard('patient')->logout();
        $this->actingAs($this->reception->user, 'web')->get(route('patients.show', $this->minor))->assertInertia(fn (Assert $page) => $page->where('portalGuardians', null)->where('portal', 'none'));
    }

    public function test_what_the_reception_cannot_link(): void
    {
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-guardians.store', $this->minor), ['email' => 'joana@example.com'])->assertSessionHasErrors('document_checked');
        $this->link('ninguem@example.com')->assertSessionHasErrors('email');
        // An adult has no guardian in the portal.
        $this->link(patient: $this->patient)->assertSessionHasErrors('guardian');

        // A pending invitation is not an active account.
        $this->minor->forceFill(['birth_date' => '2010-01-01'])->save();
        $pending = $this->registerPatient('Ana Lima', '1980-01-01');
        $pending->forceFill(['cpf' => '11144477735', 'email' => 'ana@example.com', 'status' => 'complete'])->save();
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $pending), ['document_checked' => '1'])->assertSessionHasNoErrors();
        $this->link('ana@example.com')->assertSessionHasErrors('email');

        $this->link()->assertSessionHasNoErrors();
        $this->link()->assertSessionHasErrors(['email' => 'Este responsável já está vinculado.']);
        $this->assertSame(1, PatientAccountLink::withoutTenantScope()->count());
    }

    public function test_the_history_is_insert_only(): void
    {
        $this->link()->assertSessionHasNoErrors();

        app(CurrentTenant::class)->set($this->unit->tenant()->firstOrFail());
        $this->assertSame(1, PatientAccountLink::query()->count());

        $this->expectException(QueryException::class);
        PatientAccountLink::query()->update(['event' => 'ended']);
    }
}
