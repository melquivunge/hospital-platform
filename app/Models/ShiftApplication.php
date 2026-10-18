<?php

namespace App\Models;

use App\Scheduling\ApplicationStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Application of a professional to an open vacancy (V1-07). Writes go through Shift (apply, withdraw,
 * selectCandidate) so they share its lock order: professionals, then the shift.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $shift_id
 * @property int $professional_id
 * @property ApplicationStatus $status
 * @property int $eligibility_version
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $decided_at
 * @property int|null $decided_by_user_id
 * @property string|null $decision_reason
 * @property Carbon $created_at
 */
#[Fillable(['shift_id', 'professional_id', 'status', 'eligibility_version'])]
class ShiftApplication extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'withdrawn_at' => 'datetime',
            'decided_at' => 'datetime',
            'shift_id' => 'integer',
            'professional_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
