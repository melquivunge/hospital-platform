<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A patient's stay in the emergency department (step 4.2): arrival, then classifications. Care,
 * discharge and admission come in step 4.3. One open episode per patient in the network.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $patient_id
 * @property string $status
 * @property int|null $current_level
 * @property Carbon $arrived_at
 * @property Carbon|null $triaged_at
 */
#[Fillable(['unit_id', 'patient_id', 'arrived_at', 'arrived_by_user_id'])]
class EmergencyEpisode extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['arrived_at' => 'datetime', 'triaged_at' => 'datetime', 'current_level' => 'integer'];
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
}
