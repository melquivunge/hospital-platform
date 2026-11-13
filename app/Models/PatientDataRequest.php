<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step 7a.3b: a patient's request to correct the registration data, open until the reception resolves it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $account_id
 * @property string $message
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 */
class PatientDataRequest extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** Open requests one account may have at a time. */
    public const OPEN_LIMIT = 3;

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
