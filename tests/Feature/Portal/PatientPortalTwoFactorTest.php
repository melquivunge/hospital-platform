<?php

namespace Tests\Feature\Portal;

use App\Models\AuditEvent;
use App\Models\PatientAccount;
use App\Notifications\PortalAccessLink;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7a.1c: the optional second factor of the patient's account — turning it on with the password and a code,
 * the code at sign-in and after a new password, the recovery codes, and turning it off.
 */
class PatientPortalTwoFactorTest extends ClinicalTestCase
{
    private const PASSWORD = 'Senha-forte-123';

    private string $slug;

    /** The authenticator's time step used last: each code must be newer than the one before (no replay). */
    private int $step = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->patient->forceFill(['cpf' => '52998224725', 'email' => 'joana@example.com'])->save();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;

        // Joana has a portal account and is signed in.
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $this->patient), ['document_checked' => '1'])->assertSessionHasNoErrors();
        auth()->guard('web')->logout();
        $this->post(route('portal.setup.store', ['portal' => $this->slug, 'token' => $this->lastLinkToken()]), ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        $this->assertAuthenticated('patient');
    }

    private function lastLinkToken(): string
    {
        $token = '';
        Notification::assertSentOnDemand(PortalAccessLink::class, function (PortalAccessLink $notification) use (&$token) {
            $token = basename($notification->url);

            return true;
        });

        return $token;
    }

    private function account(): PatientAccount
    {
        return PatientAccount::withoutTenantScope()->sole();
    }

    /**
     * A code the authenticator app would show, each one from a newer time step than the last.
     */
    private function totp(): string
    {
        $engine = new Google2FA;
        // ponytail: the app accepts one step either side of now; starting at now keeps two codes valid even if the clock ticks.
        $this->step = $this->step === 0 ? (int) $engine->getTimestamp() : $this->step + 1;

        return $engine->oathTotp(Fortify::currentEncrypter()->decrypt((string) $this->account()->two_factor_secret), $this->step);
    }

    private function url(string $name): string
    {
        return route($name, ['portal' => $this->slug]);
    }

    /**
     * @return list<string>
     */
    private function turnOn(): array
    {
        $this->post($this->url('portal.two-factor.enable'), ['password' => self::PASSWORD])->assertRedirect($this->url('portal.security'));
        $response = $this->post($this->url('portal.two-factor.confirm'), ['code' => $this->totp()])->assertSessionHasNoErrors();
        /** @var list<string> $codes */
        $codes = $response->getSession()?->get('portal.recovery_codes');

        return $codes;
    }

    private function signInWithPassword(): void
    {
        $this->post($this->url('portal.logout'));
        $this->post($this->url('portal.login.store'), ['email' => 'joana@example.com', 'password' => self::PASSWORD])->assertRedirect($this->url('portal.challenge'));
        $this->assertGuest('patient');
    }

    public function test_turning_it_on_needs_the_password_and_a_code_from_the_app(): void
    {
        $this->get($this->url('portal.security'))->assertInertia(fn (Assert $page) => $page->component('portal/security')->where('enabled', false)->where('setup', null));
        $this->post($this->url('portal.two-factor.enable'), ['password' => 'errada'])->assertSessionHasErrors('password');
        $this->assertNull($this->account()->two_factor_secret);

        $this->post($this->url('portal.two-factor.enable'), ['password' => self::PASSWORD]);
        $this->get($this->url('portal.security'))->assertInertia(fn (Assert $page) => $page->where('enabled', false)->has('setup.qr')->has('setup.secret'));
        // Not on until a code from the app proves it was set up.
        $this->post($this->url('portal.two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($this->account()->hasEnabledTwoFactorAuthentication());

        $codes = $this->turnOn();
        $this->assertCount(8, $codes);
        $this->assertTrue($this->account()->hasEnabledTwoFactorAuthentication());
        // Stored encrypted, never in the clear.
        $this->assertStringNotContainsString($codes[0], (string) $this->account()->getRawOriginal('two_factor_recovery_codes'));
        // Shown once.
        $this->get($this->url('portal.security'))->assertInertia(fn (Assert $page) => $page->where('enabled', true)->where('setup', null)->where('recoveryCodes', $codes));
        $this->get($this->url('portal.security'))->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', null));
        $this->assertContains('patient_portal.two_factor_enabled', AuditEvent::pluck('action')->all());
    }

    public function test_signing_in_asks_for_the_code_and_a_used_code_does_not_work_again(): void
    {
        $this->turnOn();
        $this->signInWithPassword();
        $this->get($this->url('portal.home'))->assertRedirect($this->url('portal.login'));

        $this->post($this->url('portal.challenge.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $code = $this->totp();
        $this->post($this->url('portal.challenge.store'), ['code' => $code])->assertRedirect($this->url('portal.home'));
        $this->assertAuthenticatedAs($this->account(), 'patient');

        $this->signInWithPassword();
        $this->post($this->url('portal.challenge.store'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('patient');
    }

    public function test_a_recovery_code_works_once(): void
    {
        $codes = $this->turnOn();
        $this->signInWithPassword();
        $this->post($this->url('portal.challenge.store'), ['recovery_code' => $codes[0]])->assertRedirect($this->url('portal.home'));
        $this->assertNotContains($codes[0], $this->account()->recoveryCodes());
        $this->assertContains('patient_portal.recovery_code_used', AuditEvent::pluck('action')->all());

        $this->signInWithPassword();
        $this->post($this->url('portal.challenge.store'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest('patient');
    }

    public function test_wrong_codes_lock_the_account_and_the_wait_has_a_limit(): void
    {
        $this->turnOn();
        $this->signInWithPassword();

        for ($i = 0; $i < 5; $i++) {
            $this->post($this->url('portal.challenge.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        // Even the right code waits now, and a new password sign-in does not reset it.
        $this->signInWithPassword();
        $this->post($this->url('portal.challenge.store'), ['code' => $this->totp()])->assertSessionHasErrors(['code' => 'Muitas tentativas. Tente de novo em 15 minuto(s).']);
        $this->assertGuest('patient');

        // The pending sign-in itself lasts ten minutes.
        $this->travel(11)->minutes();
        $this->post($this->url('portal.challenge.store'), ['code' => '000000'])->assertRedirect($this->url('portal.login'));
        $this->get($this->url('portal.challenge'))->assertRedirect($this->url('portal.login'));
    }

    public function test_a_new_password_from_the_e_mail_link_still_asks_for_the_code(): void
    {
        $this->turnOn();
        $this->post($this->url('portal.logout'));
        $this->post($this->url('portal.forgot.store'), ['email' => 'joana@example.com']);

        $this->post(route('portal.setup.store', ['portal' => $this->slug, 'token' => $this->lastLinkToken()]), ['password' => 'Outra-senha-456', 'password_confirmation' => 'Outra-senha-456'])
            ->assertRedirect($this->url('portal.challenge'));
        $this->assertGuest('patient');
    }

    public function test_new_recovery_codes_replace_the_old_ones_and_turning_it_off_needs_the_password(): void
    {
        $old = $this->turnOn();
        $response = $this->post($this->url('portal.two-factor.recovery-codes'), ['password' => self::PASSWORD]);
        /** @var list<string> $new */
        $new = $response->getSession()?->get('portal.recovery_codes');
        $this->assertSame([], array_values(array_intersect($old, $new)));

        $this->delete($this->url('portal.two-factor.disable'), ['password' => 'errada'])->assertSessionHasErrors('password');
        $this->assertTrue($this->account()->hasEnabledTwoFactorAuthentication());
        $this->delete($this->url('portal.two-factor.disable'), ['password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->assertNull($this->account()->two_factor_secret);

        // Back to the password alone.
        $this->post($this->url('portal.logout'));
        $this->post($this->url('portal.login.store'), ['email' => 'joana@example.com', 'password' => self::PASSWORD])->assertRedirect($this->url('portal.home'));
        $this->assertSame(
            ['patient_portal.two_factor_enabled', 'patient_portal.recovery_codes_regenerated', 'patient_portal.two_factor_disabled'],
            AuditEvent::where('action', 'like', 'patient_portal.%two_factor%')->orWhere('action', 'like', 'patient_portal.recovery%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_a_code_typed_with_a_space_works(): void
    {
        $this->post($this->url('portal.two-factor.enable'), ['password' => self::PASSWORD]);
        $code = $this->totp();
        $this->post($this->url('portal.two-factor.confirm'), ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertSessionHasNoErrors();
        $this->assertTrue($this->account()->hasEnabledTwoFactorAuthentication());
    }

    public function test_a_new_password_ends_a_pending_sign_in(): void
    {
        $this->turnOn();
        $this->signInWithPassword();
        $this->account()->forceFill(['password' => 'Outra-senha-456'])->save();

        $this->post($this->url('portal.challenge.store'), ['code' => $this->totp()])->assertRedirect($this->url('portal.login'));
        $this->assertGuest('patient');
    }

    public function test_wrong_passwords_on_this_page_are_limited_per_account(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.3.0.{$i}"])->post($this->url('portal.two-factor.enable'), ['password' => 'errada'])->assertSessionHasErrors(['password' => 'Senha incorreta.']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.4.0.1'])->post($this->url('portal.two-factor.enable'), ['password' => self::PASSWORD])->assertSessionHasErrors(['password' => 'Muitas tentativas. Tente de novo em 15 minuto(s).']);
        $this->assertNull($this->account()->two_factor_secret);
    }

    public function test_the_security_page_is_for_the_signed_in_patient_only(): void
    {
        $this->post($this->url('portal.logout'));
        $this->get($this->url('portal.security'))->assertRedirect($this->url('portal.login'));
        $this->post($this->url('portal.two-factor.enable'), ['password' => self::PASSWORD])->assertRedirect($this->url('portal.login'));
        // A challenge page without a pending sign-in goes back to the sign-in.
        $this->get($this->url('portal.challenge'))->assertRedirect($this->url('portal.login'));
    }
}
