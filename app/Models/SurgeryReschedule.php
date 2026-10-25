<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The booking a reschedule replaced, with the reason (step 5b.3a). Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $surgery_id
 * @property int $previous_room_id
 * @property Carbon $previous_start
 * @property Carbon $previous_end
 * @property string $reason
 * @property int $changed_by_user_id
 * @property Carbon $changed_at
 */
#[Fillable(['tenant_id', 'surgery_id', 'previous_room_id', 'previous_start', 'previous_end', 'reason', 'changed_by_user_id', 'changed_at'])]
class SurgeryReschedule extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['previous_start' => 'datetime', 'previous_end' => 'datetime', 'changed_at' => 'datetime'];
    }
}
