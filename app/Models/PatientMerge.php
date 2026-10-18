<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One merge decision (step 3.3), with its undoing when it happens.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $primary_patient_id
 * @property int $duplicate_patient_id
 * @property string $reason
 * @property int $merged_by_user_id
 * @property Carbon $merged_at
 * @property string|null $undo_reason
 * @property int|null $undone_by_user_id
 * @property Carbon|null $undone_at
 */
#[Fillable(['primary_patient_id', 'duplicate_patient_id', 'reason', 'merged_by_user_id', 'merged_at'])]
class PatientMerge extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['merged_at' => 'datetime', 'undone_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function primary(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'primary_patient_id');
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function duplicate(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'duplicate_patient_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by_user_id');
    }
}
