<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_reset_link_response_does_not_reveal_whether_email_exists(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $known = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email]);
        $unknown = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'ninguem@example.com']);

        foreach ([$known, $unknown] as $response) {
            $response->assertRedirect(route('password.request'))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', __('passwords.sent'));
        }

        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_json_and_broker_throttled_responses_match_success(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        // Second request for the same user hits the broker throttle; it must still look like success.
        foreach ([$user->email, $user->email, 'ninguem@example.com'] as $email) {
            $this->postJson(route('password.email'), ['email' => $email])
                ->assertOk()
                ->assertExactJson(['message' => __('passwords.sent')]);
        }

        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_reset_link_requests_are_rate_limited_per_ip(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->post(route('password.email'), ['email' => "pessoa{$i}@example.com"])
                ->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => 'pessoa6@example.com'])
            ->assertTooManyRequests();
    }
}
