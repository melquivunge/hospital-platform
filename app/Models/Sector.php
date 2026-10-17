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
 */
#[Fillable(['tenant_id', 'unit_id', 'name'])]
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
