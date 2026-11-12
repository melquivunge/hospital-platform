<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PatientAccount;
use App\Notifications\PortalAccessLink;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7a.1a (ADR-0012): sign-in, sign-out and the single-use link of the patient portal. Answers never
 * say whether an e-mail has an account (contract, section 5).
 */
class PortalAuthController extends Controller
{
    private const ATTEMPTS = 5;

    public function showLogin(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('patient')->check()) {
            return to_route('portal.home', ['portal' => self::slug()]);
        }

        return Inertia::render('portal/login', ['network' => self::network(), 'status' => $request->session()->get('status')]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        $key = 'portal-login|'.self::slug().'|'.mb_strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages(['email' => 'Muitas tentativas. Tente de novo em '.RateLimiter::availableIn($key).' segundos.']);
        }

        $account = self::byEmail($data['email']);

        if ($account === null || $account->password === null || ! Hash::check($data['password'], $account->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }

        RateLimiter::clear($key);
        Auth::guard('patient')->login($account);
        $request->session()->regenerate();
        $request->session()->put('portal.last_seen', now()->getTimestamp());
        $account->forceFill(['last_login_at' => now()])->save();
        AuditEvent::record('patient_portal.login', $account->tenant_id, $account);

        return to_route('portal.home', ['portal' => self::slug()]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('patient')->logout();
        $request->session()->forget('portal.last_seen');
        $request->session()->regenerateToken();

        return to_route('portal.login', ['portal' => self::slug()]);
    }

    public function showForgot(Request $request): Response
    {
        return Inertia::render('portal/forgot', ['network' => self::network(), 'status' => $request->session()->get('status')]);
    }

    public function forgot(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $account = self::byEmail($data['email']);

        // Only an account that already chose a password: a pending invitation is renewed by the reception.
        if ($account !== null && $account->password !== null) {
            self::sendLink($account, invitation: false);
        }

        return back()->with('status', 'Se este e-mail tiver acesso ao portal, enviamos um link para criar uma nova senha.');
    }

    public function showSetup(string $portal, string $token): Response
    {
        return Inertia::render('portal/setup', [
            'network' => self::network(),
            'token' => $token,
            'valid' => PatientAccount::forSetupToken($token) !== null,
        ]);
    }

    public function setup(Request $request, string $portal, string $token): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'confirmed', Password::defaults()]]);
        $account = PatientAccount::forSetupToken($token);

        if ($account === null) {
            throw ValidationException::withMessages(['password' => 'Este link venceu ou já foi usado. Peça um novo.']);
        }

        $account->forceFill(['password' => $data['password'], 'setup_token_hash' => null, 'setup_token_expires_at' => null, 'last_login_at' => now()])->save();
        AuditEvent::record('patient_portal.password_set', $account->tenant_id, $account);
        Auth::guard('patient')->login($account);
        $request->session()->regenerate();
        $request->session()->put('portal.last_seen', now()->getTimestamp());

        return to_route('portal.home', ['portal' => self::slug()]);
    }

    /**
     * The invitation (reception) and the forgotten password share one link, sent to the account's e-mail.
     */
    public static function sendLink(PatientAccount $account, bool $invitation): void
    {
        $tenant = app(CurrentTenant::class)->require();
        $url = route('portal.setup', ['portal' => $tenant->slug, 'token' => $account->issueSetupToken()]);
        AuditEvent::record($invitation ? 'patient_portal.invited' : 'patient_portal.reset_requested', $account->tenant_id, $account);
        Notification::route('mail', $account->email)->notify(new PortalAccessLink($url, $tenant->name, $invitation));
    }

    private static function byEmail(string $email): ?PatientAccount
    {
        return PatientAccount::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
    }

    private static function slug(): string
    {
        return app(CurrentTenant::class)->require()->slug;
    }

    /**
     * @return array{name: string, slug: string}
     */
    private static function network(): array
    {
        $tenant = app(CurrentTenant::class)->require();

        return ['name' => $tenant->name, 'slug' => $tenant->slug];
    }
}
