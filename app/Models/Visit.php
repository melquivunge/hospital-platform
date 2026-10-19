<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Visit (step 3.6a): opened by the professional from an outpatient arrival or, since step 4.3, from an
 * emergency episode (exactly one), then closed. No reopening: what comes later is an addendum.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int|null $appointment_id
 * @property int|null $emergency_episode_id
 * @property int $professional_id
 * @property int $patient_id
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 */
#[Fillable(['unit_id', 'appointment_id', 'emergency_episode_id', 'professional_id', 'patient_id', 'opened_by_user_id', 'opened_at'])]
class Visit extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
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
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return BelongsTo<EmergencyEpisode, $this>
     */
    public function emergencyEpisode(): BelongsTo
    {
        return $this->belongsTo(EmergencyEpisode::class);
    }
}
