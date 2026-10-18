<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Authorized presence point of a unit (step 8a). Never edited, only deactivated (database trigger), so
 * the id recorded with an attendance decision identifies the exact configuration used.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property string $name
 * @property string $latitude
 * @property string $longitude
 * @property int $radius_m
 * @property int $created_by_user_id
 * @property Carbon|null $deactivated_at
 * @property Carbon $created_at
 */
#[Fillable(['unit_id', 'name', 'latitude', 'longitude', 'radius_m', 'created_by_user_id'])]
class AttendancePoint extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
            'unit_id' => 'integer',
            'radius_m' => 'integer',
        ];
    }

    /**
     * Great-circle distance in metres (haversine, mean Earth radius).
     */
    public function distanceTo(float $latitude, float $longitude): float
    {
        $radius = 6371008.8;
        $dLat = deg2rad($latitude - (float) $this->latitude);
        $dLng = deg2rad($longitude - (float) $this->longitude);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad((float) $this->latitude)) * cos(deg2rad($latitude)) * sin($dLng / 2) ** 2;

        return 2 * $radius * asin(min(1.0, sqrt($a)));
    }
}
