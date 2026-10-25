<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One movement of a stock lot (step 4c.1a, ADR-0007): insert-only; inserting it moves the lot's balance.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $stock_lot_id
 * @property string $type
 * @property int $quantity signed: what it adds to the lot
 * @property string|null $reason
 * @property string|null $note the text of a discard for another reason
 * @property int $user_id
 * @property int|null $prescription_dispensing_id the dispensing a 'dispensing' or 'return' movement belongs to
 * @property int|null $stock_inventory_id the inventory an 'adjustment' movement belongs to
 * @property int|null $surgery_id the surgery a 'surgery' or 'surgery_return' movement belongs to (step 5b.6c)
 * @property Carbon $created_at
 */
#[Fillable(['stock_lot_id', 'type', 'quantity', 'reason', 'note', 'user_id', 'prescription_dispensing_id', 'stock_inventory_id', 'surgery_id', 'created_at'])]
class StockMovement extends Model
{
    use BelongsToTenant;

    /** The reason codes of a discard; "other" comes with a text (note). */
    public const DISCARD_REASONS = ['expired', 'damage', 'loss', 'other'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'created_at' => 'datetime'];
    }
}
