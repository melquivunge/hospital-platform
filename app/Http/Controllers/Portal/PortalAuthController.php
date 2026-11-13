<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Jobs\SendPortalAccessMail;
use App\Models\AuditEvent;
use App\Models\PatientAccount;
use App\Notifications\PortalAccessLink;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    /** Per account in 15 minutes, from any address. */
    private const ACCOUNT_ATTEMPTS = 20;

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
        // Also per account, whatever the IP: rotating addresses does not buy more guesses.
        $accountKey = 'portal-login-account|'.self::slug().'|'.mb_strtolower($data['email']);

        foreach ([[$key, self::ATTEMPTS], [$accountKey, self::ACCOUNT_ATTEMPTS]] as [$limiter, $max]) {
            if (RateLimiter::tooManyAttempts($limiter, $max)) {
                throw ValidationException::withMessages(['email' => 'Muitas tentativas. Tente de novo em '.ceil(RateLimiter::availableIn($limiter) / 60).' minuto(s).']);
            }
        }

        $account = self::byEmail($data['email']);
        // Same cost with or without an account: a missing one checks against a throwaway hash.
        $valid = Hash::check($data['password'], $account->password ?? self::dummyHash()) && $account?->password !== null;

        if ($account === null || ! $valid) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($accountKey, 15 * 60);

            throw ValidationException::withMessages(['email' => 'E-mail ou senha incorretos.']);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($accountKey);

        if ($account->hasEnabledTwoFactorAuthentication()) {
            return PortalTwoFactorController::startChallenge($request, $account);
        }

        self::completeSignIn($request, $account);

        return to_route('portal.home', ['portal' => self::slug()]);
    }

    /**
     * The password (and, when on, the second factor) is right: the session belongs to the account from here.
     */
    public static function completeSignIn(Request $request, PatientAccount $account): void
    {
        self::signIn($request, $account);
        $account->forceFill(['last_login_at' => now()])->save();
        AuditEvent::record('patient_portal.login', $account->tenant_id, $account, byStaff: false);
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
        // Same answer time with or without an account: the lookup runs after the response.
        SendPortalAccessMail::afterAnswer(app(CurrentTenant::class)->require()->id, 'password', ['email' => $data['email']]);

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

        // Single use under concurrency: the link is spent under a row lock.
        $account = DB::transaction(function () use ($token, $data) {
            $account = PatientAccount::query()->whereKey(PatientAccount::forSetupToken($token)?->id)->lockForUpdate()->first();

            if ($account === null || $account->setup_token_hash !== hash('sha256', $token) || $account->setup_token_expires_at?->isPast() !== false) {
                return null;
            }

            // A new password also ends every other session (the session keeps a fingerprint of it) and the remember token.
            $account->forceFill(['password' => $data['password'], 'setup_token_hash' => null, 'setup_token_expires_at' => null, 'last_login_at' => now(), 'remember_token' => Str::random(60)])->save();

            return $account;
        });

        if ($account === null) {
            throw ValidationException::withMessages(['password' => 'Este link venceu ou já foi usado. Peça um novo.']);
        }

        AuditEvent::record('patient_portal.password_set', $account->tenant_id, $account, byStaff: false);

        // The e-mail link proves the e-mail, not the second factor.
        if ($account->hasEnabledTwoFactorAuthentication()) {
            return PortalTwoFactorController::startChallenge($request, $account);
        }

        self::signIn($request, $account);

        return to_route('portal.home', ['portal' => self::slug()]);
    }

    /**
     * The invitation (reception) and the forgotten password share one link, sent to the account's e-mail.
     */
    public static function sendLink(PatientAccount $account, bool $invitation): void
    {
        $tenant = app(CurrentTenant::class)->require();
        // Never the request's Host: a forged one would send the patient a real e-mail with someone else's link.
        $url = rtrim((string) config('app.url'), '/').route('portal.setup', ['portal' => $tenant->slug, 'token' => $account->issueSetupToken()], false);
        AuditEvent::record($invitation ? 'patient_portal.invited' : 'patient_portal.reset_requested', $account->tenant_id, $account, $invitation ? ['document_checked' => true] : [], byStaff: $invitation);
        // Sent now: a queued payload would keep the live link.
        Notification::route('mail', $account->email)->notifyNow(new PortalAccessLink($url, $tenant->name, $invitation));
    }

    private static function signIn(Request $request, PatientAccount $account): void
    {
        Auth::guard('patient')->login($account);
        $request->session()->regenerate();
        $request->session()->put('portal.last_seen', now()->getTimestamp());
        $request->session()->put('portal.password', self::passwordFingerprint($account));
    }

    /**
     * Ties the session to the password it signed in with: a new password signs out the other sessions.
     */
    public static function passwordFingerprint(PatientAccount $account): string
    {
        return hash_hmac('sha256', (string) $account->password, (string) config('app.key'));
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
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
