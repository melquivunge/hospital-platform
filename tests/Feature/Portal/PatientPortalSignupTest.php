<?php

namespace Tests\Feature\Portal;

use App\Models\AuditEvent;
use App\Models\PatientAccount;
use App\Notifications\PortalSignupCode;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
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
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
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
        $this->verify($code)->assertSessionHasErrors('code');
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
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$i}"])->start();
        }
        Notification::assertSentOnDemandTimes(PortalSignupCode::class, 3);

        $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->start();
        Notification::assertSentOnDemandTimes(PortalSignupCode::class, 3);
    }
}
