<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inpatient admission (step 4.4a): requested by the emergency doctor (sector, responsible doctor,
 * isolation), then admitted when bed management allocates a bed. One active admission per patient and one
 * patient per bed (enforced in the database).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $patient_id
 * @property int|null $emergency_episode_id
 * @property int $requested_sector_id
 * @property int $responsible_professional_id
 * @property bool $isolation
 * @property string $status requested, admitted, in_transit (step 4.6) or discharged (step 4.7)
 * @property int|null $bed_id
 * @property string|null $compatibility_justification
 * @property Carbon $requested_at
 * @property Carbon|null $admitted_at
 * @property string|null $discharge_type
 * @property Carbon|null $discharged_at
 * @property int|null $discharge_summary_id
 */
#[Fillable(['unit_id', 'patient_id', 'emergency_episode_id', 'requested_sector_id', 'responsible_professional_id', 'isolation', 'requested_by_user_id', 'requested_at'])]
class Admission extends Model
{
    use BelongsToTenant;

    public const ACTIVE = ['requested', 'admitted', 'in_transit'];

    /**
     * Step 4.7: discharge types; the first three need a signed summary.
     */
    public const DISCHARGE_TYPES = ['improved' => 'Melhora', 'at_request' => 'A pedido', 'transfer_out' => 'Transferência', 'death' => 'Óbito', 'evasion' => 'Evasão'];

    public const SUMMARY_REQUIRED = ['improved', 'at_request', 'transfer_out'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['isolation' => 'boolean', 'requested_at' => 'datetime', 'admitted_at' => 'datetime', 'discharged_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function requestedSector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'requested_sector_id');
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'responsible_professional_id');
    }

    /**
     * @return BelongsTo<Bed, $this>
     */
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }
}
