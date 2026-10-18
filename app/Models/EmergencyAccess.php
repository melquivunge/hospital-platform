<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Emergency access to a patient's record (step 3.6b): justified, read-only, valid for 4 hours. Insert-only.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $professional_id
 * @property int $patient_id
 * @property int $unit_id
 * @property string $justification
 * @property Carbon $granted_at
 * @property Carbon $expires_at
 */
#[Fillable(['user_id', 'professional_id', 'patient_id', 'unit_id', 'justification', 'granted_at', 'expires_at'])]
class EmergencyAccess extends Model
{
    use BelongsToTenant;

    public const HOURS = 4;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
