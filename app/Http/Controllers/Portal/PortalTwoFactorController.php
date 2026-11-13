<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PatientAccount;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * Step 7a.1c: the optional second factor of the patient's account (TOTP, as on the staff accounts). Turning it
 * on or off asks for the password; once on, signing in (and a new password from an e-mail link) asks for a code.
 */
class PortalTwoFactorController extends Controller
{
    /** A pending sign-in waits this long for the code. */
    private const CHALLENGE_MINUTES = 10;

    /** Wrong codes per account in 15 minutes, from any session. */
    private const ATTEMPTS = 5;

    public function show(Request $request): Response
    {
        $account = self::account();
        $pending = $account->two_factor_secret !== null && $account->two_factor_confirmed_at === null;

        return Inertia::render('portal/security', [
            ...PortalHomeController::shell($account),
            'enabled' => $account->hasEnabledTwoFactorAuthentication(),
            'setup' => $pending ? [
                'qr' => $account->twoFactorQrCodeSvg(),
                'secret' => Fortify::currentEncrypter()->decrypt((string) $account->two_factor_secret),
            ] : null,
            // Shown once, right after they are made.
            'recoveryCodes' => $request->session()->get('portal.recovery_codes'),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $account = self::account();
        self::confirmPassword($request, $account);

        if (! $account->hasEnabledTwoFactorAuthentication()) {
            $account->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
                'two_factor_recovery_codes' => self::newRecoveryCodes(),
                'two_factor_confirmed_at' => null,
            ])->save();
        }

        return to_route('portal.security', ['portal' => self::slug()]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        $account = DB::transaction(function () use ($code) {
            $account = self::locked(self::account()->id);

            if ($account->two_factor_secret === null || $account->two_factor_confirmed_at !== null || ! self::validTotp($account, $code)) {
                return null;
            }

            $account->forceFill(['two_factor_confirmed_at' => now()])->save();

            return $account;
        });

        if ($account === null) {
            throw ValidationException::withMessages(['code' => 'Código incorreto. Confira o aplicativo e tente de novo.']);
        }

        AuditEvent::record('patient_portal.two_factor_enabled', $account->tenant_id, $account, byStaff: false);

        return to_route('portal.security', ['portal' => self::slug()])
            ->with('portal.recovery_codes', $account->recoveryCodes())
            ->with('status', 'Verificação em duas etapas ligada. Guarde os códigos de recuperação.');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $account = self::account();
        self::confirmPassword($request, $account);
        abort_unless($account->hasEnabledTwoFactorAuthentication(), 404);

        $account->forceFill(['two_factor_recovery_codes' => self::newRecoveryCodes()])->save();
        AuditEvent::record('patient_portal.recovery_codes_regenerated', $account->tenant_id, $account, byStaff: false);

        return to_route('portal.security', ['portal' => self::slug()])
            ->with('portal.recovery_codes', $account->recoveryCodes())
            ->with('status', 'Novos códigos de recuperação criados. Os anteriores deixaram de valer.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $account = self::account();
        self::confirmPassword($request, $account);
        $wasOn = $account->hasEnabledTwoFactorAuthentication();

        $account->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();

        if ($wasOn) {
            AuditEvent::record('patient_portal.two_factor_disabled', $account->tenant_id, $account, byStaff: false);
        }

        return to_route('portal.security', ['portal' => self::slug()])->with('status', 'Verificação em duas etapas desligada.');
    }

    /**
     * Called once the password is right: the account signs in only after the code.
     */
    public static function startChallenge(Request $request, PatientAccount $account): RedirectResponse
    {
        $request->session()->put('portal.challenge', [
            'id' => $account->id,
            'expires' => now()->addMinutes(self::CHALLENGE_MINUTES)->getTimestamp(),
            // A new password meanwhile ends the pending sign-in.
            'password' => PortalAuthController::passwordFingerprint($account),
        ]);

        return to_route('portal.challenge', ['portal' => self::slug()]);
    }

    public function showChallenge(Request $request): Response|RedirectResponse
    {
        if (self::pendingAccount($request) === null) {
            return to_route('portal.login', ['portal' => self::slug()]);
        }

        return Inertia::render('portal/challenge', ['network' => PortalHomeController::network()]);
    }

    public function challenge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:10', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);
        $account = self::pendingAccount($request);

        if ($account === null) {
            return to_route('portal.login', ['portal' => self::slug()])->with('status', 'O tempo para digitar o código acabou. Entre de novo.');
        }

        $key = 'portal-2fa|'.self::slug().'|'.$account->id;

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages(['code' => 'Muitas tentativas. Tente de novo em '.ceil(RateLimiter::availableIn($key) / 60).' minuto(s).']);
        }

        // Under the account's row lock: two requests with the same code (or recovery code) cannot both pass.
        $used = DB::transaction(function () use ($account, $data) {
            $account = self::locked($account->id);

            if (filled($data['recovery_code'] ?? null)) {
                $recovery = self::matchingRecoveryCode($account, (string) $data['recovery_code']);

                if ($recovery === null) {
                    return null;
                }

                $account->replaceRecoveryCode($recovery);

                return 'recovery';
            }

            return self::validTotp($account, (string) $data['code']) ? 'app' : null;
        });

        if ($used === null) {
            RateLimiter::hit($key, 15 * 60);

            throw ValidationException::withMessages(['code' => 'Código incorreto. Confira o aplicativo ou use um código de recuperação.']);
        }

        if ($used === 'recovery') {
            AuditEvent::record('patient_portal.recovery_code_used', $account->tenant_id, $account, byStaff: false);
        }

        RateLimiter::clear($key);
        $request->session()->forget('portal.challenge');
        PortalAuthController::completeSignIn($request, $account);

        return to_route('portal.home', ['portal' => self::slug()]);
    }

    private static function pendingAccount(Request $request): ?PatientAccount
    {
        $pending = $request->session()->get('portal.challenge');

        if (! is_array($pending) || ($pending['expires'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget('portal.challenge');

            return null;
        }

        $account = PatientAccount::query()->whereKey((int) ($pending['id'] ?? 0))->first();

        $current = $account !== null && $account->hasEnabledTwoFactorAuthentication()
            && hash_equals((string) ($pending['password'] ?? ''), PortalAuthController::passwordFingerprint($account));

        return $current ? $account : null;
    }

    private static function locked(int $id): PatientAccount
    {
        return PatientAccount::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Per account, a code is good only from a time step after the last one used: no replay, not even of a code
     * one step ahead (Fortify's check is per code and lets that one through a second time). Call under the row lock.
     */
    private static function validTotp(PatientAccount $account, string $code): bool
    {
        // Authenticator apps show "123 456".
        $code = (string) preg_replace('/\s+/', '', $code);
        $step = app(Google2FA::class)->verifyKeyNewer(Fortify::currentEncrypter()->decrypt((string) $account->two_factor_secret), $code, $account->two_factor_last_step ?? 0);

        if (! is_int($step)) {
            return false;
        }

        $account->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    private static function matchingRecoveryCode(PatientAccount $account, string $given): ?string
    {
        $match = null;

        // Every code compared, so the time does not tell which one matched.
        foreach ($account->recoveryCodes() as $code) {
            if (hash_equals($code, trim($given))) {
                $match = $code;
            }
        }

        return $match;
    }

    private static function newRecoveryCodes(): string
    {
        return Fortify::currentEncrypter()->encrypt(json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all(), JSON_THROW_ON_ERROR));
    }

    private static function confirmPassword(Request $request, PatientAccount $account): void
    {
        $password = $request->validate(['password' => ['required', 'string', 'max:255']])['password'];
        // Per account, from any address: a borrowed session cannot guess the password to turn the factor off.
        $key = 'portal-2fa-password|'.$account->id;

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages(['password' => 'Muitas tentativas. Tente de novo em '.ceil(RateLimiter::availableIn($key) / 60).' minuto(s).']);
        }

        if (! Hash::check($password, (string) $account->password)) {
            RateLimiter::hit($key, 15 * 60);

            throw ValidationException::withMessages(['password' => 'Senha incorreta.']);
        }

        RateLimiter::clear($key);
    }

    private static function account(): PatientAccount
    {
        /** @var PatientAccount|null $account */
        $account = Auth::guard('patient')->user();
        abort_if($account === null, 403);

        // Read again: the row may have changed under the lock since the guard loaded it.
        return PatientAccount::query()->whereKey($account->id)->firstOrFail();
    }

    private static function slug(): string
    {
        return app(CurrentTenant::class)->require()->slug;
    }
}
