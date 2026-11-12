<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Notifications\PortalSignupCode;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7a.1b (contract, decision 4): self sign-up with the CPF and the birth date, proven by a code sent
 * only to the e-mail already on file. The answer is the same whether or not the data match a patient,
 * so the screen never confirms that someone is a patient here (the e-mail is not shown, not even masked).
 */
class PortalSignupController extends Controller
{
    public const CODE_MINUTES = 10;

    public const CODE_ATTEMPTS = 5;

    /** Sign-up requests per CPF in an hour, from any address. */
    private const PER_CPF = 3;

    public function show(Request $request): Response
    {
        return Inertia::render('portal/signup', ['network' => self::network(), 'status' => $request->session()->get('status')]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cpf' => ['required', 'string', 'max:20'],
            'birth_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $cpf = preg_replace('/\D/', '', $data['cpf']) ?? '';

        if (strlen($cpf) !== 11) {
            throw ValidationException::withMessages(['cpf' => 'Informe os 11 dígitos do CPF.']);
        }

        $key = 'portal-signup|'.self::network()['slug'].'|'.$cpf;
        $accountId = 0;

        if (! RateLimiter::tooManyAttempts($key, self::PER_CPF)) {
            RateLimiter::hit($key, 3600);
            $accountId = self::sendCode($cpf, $data['birth_date']);
        }

        // Unknown data keep a pending sign-up too, so the next screen is the same.
        $request->session()->put('portal.signup', ['account' => $accountId, 'attempts' => 0]);

        return to_route('portal.signup.code', ['portal' => self::network()['slug']]);
    }

    public function showCode(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('portal.signup')) {
            return to_route('portal.signup', ['portal' => self::network()['slug']]);
        }

        return Inertia::render('portal/signup-code', ['network' => self::network()]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $pending = $request->session()->get('portal.signup');
        $wrong = ValidationException::withMessages(['code' => 'Código incorreto ou vencido. Confira o e-mail ou peça um novo código.']);

        if (! is_array($pending)) {
            return to_route('portal.signup', ['portal' => self::network()['slug']]);
        }

        // The session counts too: unknown data run out of attempts the same way.
        $pending['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
        $request->session()->put('portal.signup', $pending);

        if ($pending['attempts'] > self::CODE_ATTEMPTS) {
            $request->session()->forget('portal.signup');

            return to_route('portal.signup', ['portal' => self::network()['slug']])->with('status', 'Tentativas esgotadas. Peça um novo código.');
        }

        $code = preg_replace('/\D/', '', $data['code']) ?? '';
        $token = DB::transaction(function () use ($pending, $code) {
            $account = PatientAccount::query()->whereKey((int) ($pending['account'] ?? 0))->lockForUpdate()->first();

            if ($account === null || $account->signup_code_hash === null || $account->signup_code_expires_at?->isPast() !== false
                || $account->signup_code_attempts >= self::CODE_ATTEMPTS || ! self::stillEligible($account)) {
                return null;
            }

            if (! hash_equals($account->signup_code_hash, self::codeHash($account, $code))) {
                $account->forceFill(['signup_code_attempts' => $account->signup_code_attempts + 1])->save();

                return null;
            }

            $account->forceFill(['signup_code_hash' => null, 'signup_code_expires_at' => null, 'signup_code_attempts' => 0])->save();
            AuditEvent::record('patient_portal.signup_verified', $account->tenant_id, $account, byStaff: false);

            // Same last step as an invitation: choose the password through a single-use link.
            return $account->issueSetupToken();
        });

        if ($token === null) {
            throw $wrong;
        }

        $request->session()->forget('portal.signup');

        return to_route('portal.setup', ['portal' => self::network()['slug'], 'token' => $token]);
    }

    /**
     * The account the code went to, or 0 when the data match no adult, complete patient with an e-mail
     * (or the patient already chose a password: "Esqueci minha senha" covers that).
     */
    private static function sendCode(string $cpf, string $birthDate): int
    {
        $patient = Patient::query()->where('cpf', $cpf)->whereDate('birth_date', $birthDate)
            ->whereNull('merged_into_id')->where('status', 'complete')->whereNotNull('email')->first();

        if ($patient === null || $patient->birth_date === null || $patient->birth_date->age < PatientPortalInviteController::ADULT_AGE) {
            return 0;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $account = DB::transaction(function () use ($patient, $code) {
                $account = PatientAccount::query()->where('patient_id', $patient->id)->lockForUpdate()->first();

                if ($account?->password !== null) {
                    return null;
                }

                // A code still valid is kept: asking again (or someone who knows the CPF and the birth date)
                // never replaces the one the patient may be typing. The screen already says to check the e-mail.
                if ($account?->signup_code_expires_at?->isFuture() === true) {
                    return ['account' => $account, 'sent' => false];
                }

                $account ??= new PatientAccount;
                $account->forceFill(['tenant_id' => $patient->tenant_id, 'patient_id' => $patient->id, 'email' => $patient->email])->save();
                $account->forceFill([
                    'signup_code_hash' => self::codeHash($account, $code),
                    'signup_code_expires_at' => now()->addMinutes(self::CODE_MINUTES),
                    'signup_code_attempts' => 0,
                ])->save();

                return ['account' => $account, 'sent' => true];
            });
        } catch (UniqueConstraintViolationException) {
            // The e-mail is another patient's account here: the reception sorts it out.
            return 0;
        }

        if ($account === null) {
            return 0;
        }

        if ($account['sent']) {
            AuditEvent::record('patient_portal.signup_code_sent', $account['account']->tenant_id, $account['account'], byStaff: false);
            // Sent now, never through the queue: the queued payload would keep the live code in plain text.
            Notification::route('mail', $account['account']->email)->notifyNow(new PortalSignupCode($code, self::network()['name']));
        }

        return $account['account']->id;
    }

    /**
     * The patient may have been merged, edited or lost the e-mail since the code went out.
     */
    private static function stillEligible(PatientAccount $account): bool
    {
        $patient = Patient::query()->find($account->patient_id);

        return $patient !== null && $patient->merged_into_id === null && $patient->status === 'complete'
            && $patient->email !== null && mb_strtolower($patient->email) === mb_strtolower($account->email)
            && $patient->birth_date !== null && $patient->birth_date->age >= PatientPortalInviteController::ADULT_AGE;
    }

    private static function codeHash(PatientAccount $account, string $code): string
    {
        return hash_hmac('sha256', $account->id.'|'.$code, (string) config('app.key'));
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
