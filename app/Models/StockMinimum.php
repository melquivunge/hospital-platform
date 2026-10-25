<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The minimum stock of a medication in a unit (step 4c.1a): below it, the medication is flagged.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int|null $medication_id
 * @property int|null $material_id a material instead of a medication (step 5b.6b); exactly one of the two
 * @property int $minimum
 * @property int $updated_by_user_id
 */
#[Fillable(['unit_id', 'medication_id', 'material_id', 'minimum', 'updated_by_user_id'])]
class StockMinimum extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['minimum' => 'integer'];
    }
}
