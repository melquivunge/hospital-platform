<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Weekly consultation window of a professional at a unit (step 3.4a), in the unit's wall clock.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $professional_id
 * @property int $weekday ISO: 1 = Monday
 * @property int $start_minute
 * @property int $end_minute
 * @property int $slot_minutes
 * @property Carbon|null $deactivated_at
 */
#[Fillable(['unit_id', 'professional_id', 'weekday', 'start_minute', 'end_minute', 'slot_minutes', 'created_by_user_id', 'created_at'])]
class ScheduleRule extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'deactivated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
