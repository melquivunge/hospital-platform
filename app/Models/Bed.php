<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inpatient bed (step 4.1): free → reserved → occupied → cleaning → free; blocked with a reason. Every
 * change is a row in bed_events. A bed is deactivated, never deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $sector_id
 * @property string $room
 * @property string $code
 * @property string $type ward, private or icu
 * @property string|null $sex_restriction female or male
 * @property bool $isolation
 * @property bool $extra a temporary bed (overcrowding), identified as such
 * @property string $status
 * @property string|null $block_reason
 * @property Carbon $status_changed_at
 * @property Carbon|null $deactivated_at
 */
#[Fillable(['unit_id', 'sector_id', 'room', 'code', 'type', 'sex_restriction', 'isolation', 'extra', 'status_changed_at'])]
class Bed extends Model
{
    use BelongsToTenant;

    public const TYPES = ['ward', 'private', 'icu'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['isolation' => 'boolean', 'extra' => 'boolean', 'status_changed_at' => 'datetime', 'deactivated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
