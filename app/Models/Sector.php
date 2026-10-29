<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\SectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property string $name
 * @property string|null $sus_bed_specialty the SUS bed specialty (ESPEC_AIH, step 6b.6b2b1)
 */
#[Fillable(['tenant_id', 'unit_id', 'name', 'sus_bed_specialty'])]
class Sector extends Model
{
    /** @use HasFactory<SectorFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
