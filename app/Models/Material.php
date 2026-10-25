<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A surgical material of the network catalog (step 5b.6a, ADR-0009), separate from the medications. Deactivated,
 * never deleted: stock lots refer to it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $name_key normalized name, unique per network
 * @property string|null $code the supplier's or the hospital's reference
 * @property string $stock_unit what the stock counts
 * @property bool $active
 */
#[Fillable(['name', 'code', 'stock_unit', 'active'])]
class Material extends Model
{
    use BelongsToTenant;

    public const STOCK_UNITS = ['unidade', 'par', 'pacote', 'caixa', 'kit', 'rolo', 'frasco', 'envelope', 'metro'];

    protected static function booted(): void
    {
        static::saving(fn (Material $material) => $material->name_key = Medication::key($material->name));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
