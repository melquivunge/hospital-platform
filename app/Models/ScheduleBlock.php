<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Period without consultations for a professional at a unit (step 3.4a): vacation, training, absence.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $professional_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $reason
 * @property Carbon|null $cancelled_at
 */
#[Fillable(['unit_id', 'professional_id', 'starts_at', 'ends_at', 'reason', 'created_by_user_id', 'created_at'])]
class ScheduleBlock extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'created_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
