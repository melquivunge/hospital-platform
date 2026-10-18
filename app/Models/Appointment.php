<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Consultation booked by the reception (step 3.4b): scheduled → cancelled (with reason) or no-show
 * (after the time). Arrival and the visit itself come in steps 3.5 and 3.6.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $professional_id
 * @property int $patient_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $walk_in
 * @property string $status
 * @property int|null $rescheduled_from_id
 * @property string|null $cancel_reason
 * @property Carbon|null $closed_at
 */
#[Fillable(['unit_id', 'professional_id', 'patient_id', 'starts_at', 'ends_at', 'walk_in', 'rescheduled_from_id', 'created_by_user_id', 'created_at'])]
class Appointment extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'created_at' => 'datetime', 'closed_at' => 'datetime', 'walk_in' => 'boolean'];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
