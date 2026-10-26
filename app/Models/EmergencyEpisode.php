<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A patient's stay in the emergency department: arrival and classifications (step 4.2), then care and
 * its outcome (step 4.3): waiting → in_care → discharged | admission_requested, or evaded. One open
 * episode per patient in the network.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $patient_id
 * @property string $status
 * @property int|null $current_level
 * @property Carbon $arrived_at
 * @property Carbon|null $triaged_at
 * @property Carbon|null $ended_at
 * @property int|null $ended_by_user_id
 * @property string|null $modality private, insurance or sus (set on arrival since step 6.2a0)
 * @property int|null $insurer_id
 * @property string|null $insurance_card_number
 * @property Carbon|null $insurance_card_valid_until
 */
#[Fillable(['unit_id', 'patient_id', 'arrived_at', 'arrived_by_user_id', 'modality', 'insurer_id', 'insurance_card_number', 'insurance_card_valid_until'])]
class EmergencyEpisode extends Model
{
    use BelongsToTenant;

    /** Still in the department, including while waiting for a bed. */
    public const OPEN = ['waiting', 'in_care', 'admission_requested'];

    /** How an emergency visit ends. */
    public const OUTCOMES = ['discharged', 'admission_requested', 'evaded'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['arrived_at' => 'datetime', 'triaged_at' => 'datetime', 'ended_at' => 'datetime', 'current_level' => 'integer', 'insurance_card_valid_until' => 'date'];
    }

    /**
     * @return HasOne<Visit, $this>
     */
    public function visit(): HasOne
    {
        return $this->hasOne(Visit::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return HasMany<Triage, $this>
     */
    public function triages(): HasMany
    {
        return $this->hasMany(Triage::class, 'episode_id');
    }

    /**
     * @return HasOne<Triage, $this>
     */
    public function latestTriage(): HasOne
    {
        return $this->hasOne(Triage::class, 'episode_id')->latestOfMany();
    }
}
