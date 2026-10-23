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
 * @property int $user_id
 * @property int|null $prescription_dispensing_id the dispensing a 'dispensing' or 'return' movement belongs to
 * @property Carbon $created_at
 */
#[Fillable(['stock_lot_id', 'type', 'quantity', 'reason', 'user_id', 'prescription_dispensing_id', 'created_at'])]
class StockMovement extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'created_at' => 'datetime'];
    }
}
