<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Consultation booked by the reception (step 3.4b): scheduled → cancelled (with reason) or no-show
 * (after the time), or arrived (step 3.5, with the visit's modality) → in progress → completed (the visit, step 3.6a).
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
 * @property Carbon|null $arrived_at
 * @property string|null $modality private, insurance or sus (set on arrival)
 * @property int|null $insurer_id
 * @property string|null $insurance_card_number
 * @property Carbon|null $insurance_card_valid_until
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
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'created_at' => 'datetime', 'closed_at' => 'datetime', 'walk_in' => 'boolean', 'arrived_at' => 'datetime', 'insurance_card_valid_until' => 'date'];
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

    /**
     * @return HasOne<Visit, $this>
     */
    public function visit(): HasOne
    {
        return $this->hasOne(Visit::class);
    }

    /**
     * @return BelongsTo<Insurer, $this>
     */
    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
