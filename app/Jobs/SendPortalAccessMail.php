<?php

namespace App\Jobs;

use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalSignupController;
use App\Models\PatientAccount;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Review of 7a.1a/7a.1b: "Esqueci minha senha" and "Primeiro acesso" run this after the response is sent,
 * whatever was typed, so the answer takes the same time with or without a patient. Nothing is stored in a queue:
 * the link or the code never sits in a job payload.
 */
class SendPortalAccessMail
{
    use Dispatchable;

    /**
     * @param  array{email?: string, cpf?: string, birth_date?: string}  $data
     */
    public function __construct(public readonly int $tenantId, public readonly string $kind, public readonly array $data) {}

    /**
     * After the response in production. In tests one application serves every request and its terminating
     * callbacks pile up and run again, so there it runs in line.
     *
     * @param  array{email?: string, cpf?: string, birth_date?: string}  $data
     */
    public static function afterAnswer(int $tenantId, string $kind, array $data): void
    {
        // ponytail: test-harness branch; drop it if the test client stops reusing terminating callbacks.
        app()->runningUnitTests() ? self::dispatchSync($tenantId, $kind, $data) : self::dispatchAfterResponse($tenantId, $kind, $data);
    }

    public function handle(CurrentTenant $current): void
    {
        // Restores whatever was set before (the sync driver runs this inside the request).
        $previous = $current->get();
        $current->set(Tenant::query()->findOrFail($this->tenantId));

        try {
            if ($this->kind === 'password') {
                $account = PatientAccount::query()->whereRaw('lower(email) = ?', [mb_strtolower($this->data['email'] ?? '')])->first();

                // Only an account that already chose a password: a pending invitation is renewed by the reception.
                if ($account?->password !== null) {
                    PortalAuthController::sendLink($account, invitation: false);
                }
            } elseif ($this->kind === 'signup') {
                PortalSignupController::sendCode($this->data['cpf'] ?? '', $this->data['birth_date'] ?? '');
            }
        } finally {
            $current->set($previous);
        }
    }
}
