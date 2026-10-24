<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operating room of a unit (step 5b.1a). Deactivated, never deleted: surgeries are booked in it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property string $name
 * @property string $name_key normalized name, unique per unit
 * @property bool $active
 */
#[Fillable(['unit_id', 'name', 'active'])]
class OperatingRoom extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(fn (OperatingRoom $room) => $room->name_key = Medication::key($room->name));
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
