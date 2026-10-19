<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Transfer of an inpatient to another unit of the network (step 4.6): requested → accepted (bed reserved)
 * → departed → completed; refused or cancelled with a reason.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $admission_id
 * @property int $from_unit_id
 * @property int $from_bed_id
 * @property int $to_unit_id
 * @property int $requested_sector_id
 * @property int $responsible_professional_id
 * @property string $status
 * @property int|null $reserved_bed_id
 * @property string|null $compatibility_justification
 * @property string|null $reason
 * @property int $requested_by_user_id
 * @property Carbon $requested_at
 * @property Carbon|null $departed_at
 */
#[Fillable(['admission_id', 'from_unit_id', 'from_bed_id', 'to_unit_id', 'requested_sector_id', 'responsible_professional_id', 'requested_by_user_id', 'requested_at'])]
class AdmissionTransfer extends Model
{
    use BelongsToTenant;

    public const OPEN = ['requested', 'accepted', 'departed'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'decided_at' => 'datetime', 'departed_at' => 'datetime', 'received_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Admission, $this>
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
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
    public function reservedBed(): BelongsTo
    {
        return $this->belongsTo(Bed::class, 'reserved_bed_id');
    }
}
