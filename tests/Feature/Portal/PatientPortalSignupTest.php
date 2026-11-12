<?php

namespace Tests\Feature\Portal;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Notifications\PortalAccessLink;
use App\Notifications\PortalSignupCode;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Step 7a.1b (contract, decision 4): self sign-up with the CPF and the birth date, proven by a code sent
 * only to the e-mail already on file, with the same answer whether or not the data match.
 */
class PatientPortalSignupTest extends ClinicalTestCase
{
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->patient->forceFill(['cpf' => '52998224725', 'email' => 'joana@example.com'])->save();
        $this->slug = $this->unit->tenant()->firstOrFail()->slug;
    }

    private function start(string $cpf = '529.982.247-25', string $birthDate = '1990-01-02'): void
    {
        $this->post(route('portal.signup.store', ['portal' => $this->slug]), ['cpf' => $cpf, 'birth_date' => $birthDate])
            ->assertRedirect(route('portal.signup.code', ['portal' => $this->slug]));
    }

    private function sentCode(): string
    {
        $code = null;
        Notification::assertSentOnDemand(PortalSignupCode::class, function (PortalSignupCode $notification, array $channels, AnonymousNotifiable $notifiable) use (&$code) {
            $code = $notification->code;

            return $notifiable->routes['mail'] === 'joana@example.com';
        });
        $this->assertIsString($code);

        return $code;
    }

    /**
     * @return TestResponse<Response>
     */
    private function verify(string $code): TestResponse
    {
        return $this->post(route('portal.signup.verify', ['portal' => $this->slug]), ['code' => $code]);
    }

    public function test_the_code_goes_to_the_e_mail_on_file_and_leads_to_choosing_the_password(): void
    {
        $this->start();
        $code = $this->sentCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $account = PatientAccount::withoutTenantScope()->sole();
        $this->assertNull($account->invited_by_user_id);
        $this->assertNotSame($code, $account->signup_code_hash);

        $response = $this->verify($code);
        $token = basename((string) $response->headers->get('Location'));
        $response->assertRedirect(route('portal.setup', ['portal' => $this->slug, 'token' => $token]));

        $this->post(route('portal.setup.store', ['portal' => $this->slug, 'token' => $token]), ['password' => 'Senha-forte-123', 'password_confirmation' => 'Senha-forte-123'])
            ->assertRedirect(route('portal.home', ['portal' => $this->slug]));
        $this->assertAuthenticatedAs($account, 'patient');
        $this->assertSame(
            ['patient_portal.signup_code_sent', 'patient_portal.signup_verified', 'patient_portal.password_set'],
            AuditEvent::where('action', 'like', 'patient_portal.%')->orderBy('id')->pluck('action')->all(),
        );
        // The patient's own steps have no staff actor.
        $this->assertSame([null], AuditEvent::where('action', 'like', 'patient_portal.%')->pluck('actor_user_id')->unique()->values()->all());

        // With a password, no new code.
        $this->start();
        Notification::assertSentOnDemandTimes(PortalSignupCode::class, 1);
    }

    public function test_the_answer_is_the_same_when_the_data_match_nobody(): void
    {
        $this->start(birthDate: '1991-01-02');
        Notification::assertNothingSent();
        $this->assertSame(0, PatientAccount::withoutTenantScope()->count());

        // Same next screen, same error for any code.
        $this->get(route('portal.signup.code', ['portal' => $this->slug]))->assertInertia(fn (Assert $page) => $page->component('portal/signup-code'));
        $this->verify('123456')->assertSessionHasErrors(['code' => 'Código incorreto ou vencido. Confira o e-mail ou peça um novo código.']);
    }

    public function test_no_code_for_a_minor_or_a_record_without_e_mail(): void
    {
        foreach ([['birth_date' => '2015-05-05'], ['email' => null]] as $change) {
            $this->patient->forceFill(['email' => 'joana@example.com', 'birth_date' => '1990-01-02', ...$change])->save();
            $this->start(birthDate: (string) $this->patient->birth_date?->toDateString());
        }

        Notification::assertNothingSent();
        $this->assertSame(0, PatientAccount::withoutTenantScope()->count());
    }

    public function test_the_code_expires_and_allows_five_attempts(): void
    {
        $this->start();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->verify($wrong)->assertSessionHasErrors('code');
        }
        // The sixth try ends the sign-up with a message on the first screen.
        $this->verify($code)->assertRedirect(route('portal.signup', ['portal' => $this->slug]))->assertSessionHas('status', 'Tentativas esgotadas. Peça um novo código.');
        $this->assertNull(PatientAccount::withoutTenantScope()->sole()->setup_token_hash);

        // A new code, then too late.
        Notification::fake();
        $this->travel(2)->hours();
        $this->start();
        $code = $this->sentCode();
        $this->travel(11)->minutes();
        $this->verify($code)->assertSessionHasErrors('code');
        $this->assertNull(PatientAccount::withoutTenantScope()->sole()->setup_token_hash);
    }

    public function test_requests_per_cpf_are_limited_from_any_address(): void
    {
        // Each after the previous code expired, so each would send one.
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])->start();
            $this->travel(11)->minutes();
        }
        Notification::assertSentOnDemandTimes(PortalSignupCode::class, 3);

        $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->start();
        Notification::assertSentOnDemandTimes(PortalSignupCode::class, 3);
    }

    public function test_asking_again_keeps_a_code_still_valid(): void
    {
        $this->start();
        $code = $this->sentCode();
        $this->start();
        $this->start();

        // No new e-mail: whoever knows the CPF and the birth date cannot replace the patient's code.
        Notification::assertSentOnDemandTimes(PortalSignupCode::class, 1);
        $this->verify($code)->assertRedirect();
        $this->assertNotNull(PatientAccount::withoutTenantScope()->sole()->setup_token_hash);
    }

    public function test_a_pending_invitation_is_replaced_and_a_new_code_resets_the_attempts(): void
    {
        $this->actingAs($this->reception->user, 'web')->post(route('patients.portal-invite', $this->patient), ['document_checked' => '1'])->assertSessionHasNoErrors();
        $invitationToken = '';
        Notification::assertSentOnDemand(PortalAccessLink::class, function (PortalAccessLink $notification) use (&$invitationToken) {
            $invitationToken = basename($notification->url);

            return true;
        });
        auth()->guard('web')->logout();
        Notification::fake();

        $this->start();
        $first = $this->sentCode();
        $this->verify($first === '000000' ? '111111' : '000000')->assertSessionHasErrors('code');
        Notification::fake();
        $this->travel(2)->hours();
        $this->start();
        $second = $this->sentCode();
        $this->assertSame(0, PatientAccount::withoutTenantScope()->sole()->signup_code_attempts);
        $this->verify($first)->assertSessionHasErrors('code');

        $this->verify($second)->assertRedirect();
        // The same account, now with a new single-use link; the invitation's link no longer works.
        $this->assertSame(1, PatientAccount::withoutTenantScope()->count());
        $this->get(route('portal.setup', ['portal' => $this->slug, 'token' => $invitationToken]))->assertInertia(fn (Assert $page) => $page->where('valid', false));
    }

    public function test_an_e_mail_used_by_another_patients_account_or_another_network_sends_nothing(): void
    {
        // Another patient here already has a portal account with this e-mail.
        $this->actingAs($this->reception->user, 'web')->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Outra Pessoa', 'birth_date' => '1970-01-01', 'sex' => 'male'])->assertSessionHasNoErrors();
        $other = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        $other->forceFill(['cpf' => '11144477735', 'email' => 'joana@example.com'])->save();
        $this->post(route('patients.portal-invite', $other), ['document_checked' => '1'])->assertSessionHasNoErrors();
        auth()->guard('web')->logout();
        Notification::fake();

        $this->start();
        Notification::assertNothingSent();

        // The same CPF and birth date mean nothing on another network's portal.
        app(CurrentTenant::class)->set(null);
        $elsewhere = Membership::factory()->withRole(Role::NetworkAdmin)->create()->tenant()->firstOrFail();
        $this->post(route('portal.signup.store', ['portal' => $elsewhere->slug]), ['cpf' => '529.982.247-25', 'birth_date' => '1990-01-02'])
            ->assertRedirect(route('portal.signup.code', ['portal' => $elsewhere->slug]));
        Notification::assertNothingSent();
    }

    public function test_the_code_stops_working_when_the_record_changes(): void
    {
        $this->start();
        $code = $this->sentCode();
        $this->patient->forceFill(['email' => 'outro@example.com'])->save();

        $this->verify($code)->assertSessionHasErrors('code');
        $this->assertNull(PatientAccount::withoutTenantScope()->sole()->setup_token_hash);
    }
}
