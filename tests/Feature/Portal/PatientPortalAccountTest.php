<?php

namespace Tests\Feature\Portal;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Unit;
use App\Notifications\PortalAccessLink;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7a.1a (ADR-0012): the patient's portal account — the reception's invitation, the single-use link,
 * sign-in, and the separation from the staff app.
 */
class PatientPortalAccountTest extends ClinicalTestCase
{
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->patient->forceFill(['cpf' => '52998224725', 'email' => 'Joana.Prado@example.com'])->save();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;
    }

    /**
     * Invites Joana and returns the link token from the e-mail.
     */
    private function invite(): string
    {
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $this->patient), ['document_checked' => '1'])->assertSessionHasNoErrors();
        $token = null;
        Notification::assertSentOnDemand(PortalAccessLink::class, function (PortalAccessLink $notification, array $channels, AnonymousNotifiable $notifiable) use (&$token) {
            $token = basename($notification->url);

            return $notifiable->routes['mail'] === 'Joana.Prado@example.com' && $notification->invitation;
        });
        $this->assertIsString($token);
        auth()->guard('web')->logout();

        return $token;
    }

    private function activate(): void
    {
        $this->post(route('portal.setup.store', ['portal' => $this->slug, 'token' => $this->invite()]), ['password' => 'Senha-forte-123', 'password_confirmation' => 'Senha-forte-123'])
            ->assertRedirect(route('portal.home', ['portal' => $this->slug]));
        auth()->guard('patient')->logout();
    }

    public function test_the_reception_invites_after_checking_the_document_and_the_link_is_single_use(): void
    {
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $this->patient))->assertSessionHasErrors('document_checked');
        $token = $this->invite();

        $account = PatientAccount::withoutTenantScope()->sole();
        $this->assertNull($account->password);
        $this->assertSame(hash('sha256', $token), $account->setup_token_hash);
        $this->assertSame(['patient_portal.invited'], AuditEvent::where('action', 'like', 'patient_portal.%')->pluck('action')->all());
        // The account's state shows on the patient's page, never the link.
        $this->actingAs($this->reception->user, 'web')->get(route('patients.show', $this->patient))->assertInertia(fn (Assert $page) => $page->where('portal', 'invited'));
        auth()->guard('web')->logout();

        $this->get(route('portal.setup', ['portal' => $this->slug, 'token' => $token]))->assertInertia(fn (Assert $page) => $page->component('portal/setup')->where('valid', true));
        $this->post(route('portal.setup.store', ['portal' => $this->slug, 'token' => $token]), ['password' => 'Senha-forte-123', 'password_confirmation' => 'Senha-forte-123'])
            ->assertRedirect(route('portal.home', ['portal' => $this->slug]));
        $this->assertAuthenticatedAs($account, 'patient');
        $this->assertGuest('web');
        $this->get(route('portal.home', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->component('portal/home')->where('patient.name', 'Joana Prado'));

        // Used once.
        auth()->guard('patient')->logout();
        $this->get(route('portal.setup', ['portal' => $this->slug, 'token' => $token]))->assertInertia(fn (Assert $page) => $page->where('valid', false));
        $this->post(route('portal.setup.store', ['portal' => $this->slug, 'token' => $token]), ['password' => 'Outra-senha-123', 'password_confirmation' => 'Outra-senha-123'])->assertSessionHasErrors('password');

        // An active account is not invited again.
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $this->patient), ['document_checked' => '1'])->assertSessionHasErrors('portal');
    }

    public function test_the_invitation_needs_a_complete_adult_record_with_an_e_mail(): void
    {
        $cases = [
            'provisional' => ['cpf' => null],
            'no e-mail' => ['email' => null],
            'minor' => ['birth_date' => '2015-05-05'],
        ];

        foreach ($cases as $change) {
            $this->patient->forceFill(['cpf' => '52998224725', 'email' => 'Joana.Prado@example.com', 'birth_date' => '1990-01-02', ...$change])->save();
            $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $this->patient), ['document_checked' => '1'])->assertSessionHasErrors('portal');
        }

        $this->assertSame(0, PatientAccount::withoutTenantScope()->count());
        Notification::assertNothingSent();
        // Who cannot edit the record cannot invite.
        $this->actingAs($this->manager->user, 'web')->post(route('patients.portal-invite', $this->patient), ['document_checked' => '1'])->assertForbidden();
    }

    public function test_the_link_expires_after_72_hours(): void
    {
        $token = $this->invite();
        $this->travel(73)->hours();

        $this->get(route('portal.setup', ['portal' => $this->slug, 'token' => $token]))->assertInertia(fn (Assert $page) => $page->where('valid', false));
    }

    public function test_sign_in_is_generic_limited_and_ends_after_30_idle_minutes(): void
    {
        $this->activate();
        $login = route('portal.login.store', ['portal' => $this->slug]);

        // Same answer for a wrong password and an unknown e-mail.
        $this->post($login, ['email' => 'joana.prado@example.com', 'password' => 'errada'])->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);
        $this->post($login, ['email' => 'ninguem@example.com', 'password' => 'errada'])->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);

        // The e-mail's case does not matter.
        $this->post($login, ['email' => 'JOANA.PRADO@example.com', 'password' => 'Senha-forte-123'])->assertRedirect(route('portal.home', ['portal' => $this->slug]));
        $this->assertAuthenticated('patient');

        $this->travel(31)->minutes();
        $this->get(route('portal.home', ['portal' => $this->slug]))->assertRedirect(route('portal.login', ['portal' => $this->slug]));
        $this->assertGuest('patient');

        for ($i = 0; $i < 5; $i++) {
            $this->post($login, ['email' => 'joana.prado@example.com', 'password' => 'errada']);
        }
        $this->post($login, ['email' => 'joana.prado@example.com', 'password' => 'Senha-forte-123'])->assertSessionHasErrors('email');
        $this->assertGuest('patient');
    }

    public function test_the_portal_and_the_staff_app_never_accept_each_other(): void
    {
        $this->activate();
        $this->post(route('portal.login.store', ['portal' => $this->slug]), ['email' => 'joana.prado@example.com', 'password' => 'Senha-forte-123']);

        // The patient is not a staff user.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('patients.index'))->assertRedirect(route('login'));

        // Another network's portal does not know this account.
        app(CurrentTenant::class)->set(null);
        $elsewhere = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $elsewhereSlug = $elsewhere->tenant()->firstOrFail()->slug;
        Unit::factory()->for($elsewhere->tenant()->firstOrFail())->create();
        $this->get(route('portal.home', ['portal' => $elsewhereSlug]))->assertRedirect(route('portal.login', ['portal' => $elsewhereSlug]));
        $this->get(route('portal.home', ['portal' => 'nao-existe']))->assertNotFound();

        // A staff session does not open the portal.
        auth()->guard('patient')->logout();
        $this->actingAs($this->reception->user, 'web')->get(route('portal.home', ['portal' => $this->slug]))->assertRedirect(route('portal.login', ['portal' => $this->slug]));
    }

    public function test_forgotten_password_answers_the_same_and_sends_only_to_an_active_account(): void
    {
        $this->activate();
        Notification::fake();
        $forgot = route('portal.forgot.store', ['portal' => $this->slug]);

        $this->post($forgot, ['email' => 'ninguem@example.com'])->assertSessionHas('status', 'Se este e-mail tiver acesso ao portal, enviamos um link para criar uma nova senha.');
        Notification::assertNothingSent();

        $this->post($forgot, ['email' => 'joana.prado@example.com'])->assertSessionHas('status', 'Se este e-mail tiver acesso ao portal, enviamos um link para criar uma nova senha.');
        Notification::assertSentOnDemand(PortalAccessLink::class, fn (PortalAccessLink $notification) => ! $notification->invitation);
    }

    public function test_after_a_merge_the_account_shows_the_kept_record(): void
    {
        $this->activate();
        $this->actingAs($this->reception->user, 'web')->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana Prado Lima', 'birth_date' => '1990-01-02', 'sex' => 'female'])->assertSessionHasNoErrors();
        $kept = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->manager->user, 'web')->post(route('patient-merges.store'), ['a_id' => $kept->id, 'b_id' => $this->patient->id, 'primary_id' => $kept->id, 'reason' => 'Mesmo documento.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($kept->id, $this->patient->fresh()?->merged_into_id);
        auth()->guard('web')->logout();

        $this->post(route('portal.login.store', ['portal' => $this->slug]), ['email' => 'joana.prado@example.com', 'password' => 'Senha-forte-123']);
        $this->get(route('portal.home', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->where('patient.name', 'Joana Prado Lima'));
    }
}
