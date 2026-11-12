<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Step 7a.1a (ADR-0012): a patient's portal account in one network. Never a staff user: it has no
 * membership and authenticates through the "patient" guard only.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property string $email
 * @property string|null $password
 * @property string|null $setup_token_hash
 * @property Carbon|null $setup_token_expires_at
 * @property int|null $invited_by_user_id
 * @property string|null $signup_code_hash
 * @property Carbon|null $signup_code_expires_at
 * @property int $signup_code_attempts
 * @property Carbon|null $last_login_at
 */
class PatientAccount extends Authenticatable
{
    use BelongsToTenant;

    /** The invitation and the forgotten-password link last 72 hours (contract, decision 4). */
    public const SETUP_HOURS = 72;

    protected $hidden = ['password', 'remember_token', 'setup_token_hash', 'signup_code_hash'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'setup_token_expires_at' => 'datetime',
            'signup_code_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * A new single-use link; only its hash is stored, and it replaces any earlier one.
     */
    public function issueSetupToken(): string
    {
        $token = Str::random(64);
        $this->forceFill([
            'setup_token_hash' => hash('sha256', $token),
            'setup_token_expires_at' => now()->addHours(self::SETUP_HOURS),
        ])->save();

        return $token;
    }

    /**
     * The account a still-valid link belongs to, in the current network.
     */
    public static function forSetupToken(string $token): ?self
    {
        return self::query()
            ->where('setup_token_hash', hash('sha256', $token))
            ->where('setup_token_expires_at', '>', now())
            ->first();
    }

    /**
     * The record the portal shows: after a merge, the kept one (the account keeps its original link,
     * so undoing the merge needs nothing here).
     */
    public function effectivePatientId(): int
    {
        $patient = $this->patient()->firstOrFail();

        return $patient->merged_into_id ?? $patient->id;
    }
}
