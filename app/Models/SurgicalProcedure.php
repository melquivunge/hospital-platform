<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A surgical procedure of the network catalog (step 5b.1a). Deactivated, never deleted: surgeries refer to it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $name_key normalized name, unique per network
 * @property string|null $code TUSS or SIGTAP
 * @property int $duration_minutes expected, the default length of the booking
 * @property string $size small | medium | large
 * @property bool $active
 */
#[Fillable(['name', 'code', 'duration_minutes', 'size', 'active'])]
class SurgicalProcedure extends Model
{
    use BelongsToTenant;

    public const SIZES = ['small' => 'Pequeno', 'medium' => 'Médio', 'large' => 'Grande'];

    protected static function booted(): void
    {
        static::saving(fn (SurgicalProcedure $procedure) => $procedure->name_key = Medication::key($procedure->name));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'duration_minutes' => 'integer'];
    }
}
