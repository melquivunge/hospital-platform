<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One lot of an inventory (step 4c.3b, ADR-0007): a recorded lot, or a lot found without a record (code and
 * expiry). Counting keeps the lot's balance read with the lot locked at that moment (expected).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $stock_inventory_id
 * @property int|null $medication_id
 * @property int|null $material_id a material instead of a medication (step 5b.6b); exactly one of the two
 * @property int|null $stock_lot_id
 * @property string|null $lot_code
 * @property Carbon|null $expires_on
 * @property int|null $counted
 * @property int|null $expected
 * @property int|null $counted_by_user_id
 * @property Carbon|null $counted_at
 */
#[Fillable(['stock_inventory_id', 'medication_id', 'material_id', 'stock_lot_id', 'lot_code', 'expires_on', 'counted', 'expected', 'counted_by_user_id', 'counted_at'])]
class StockInventoryLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_on' => 'date', 'counted' => 'integer', 'expected' => 'integer', 'counted_at' => 'datetime'];
    }
}
