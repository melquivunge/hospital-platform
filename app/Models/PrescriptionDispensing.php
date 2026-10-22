<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The pharmacy's dispensing of one approved item for the 24 hours of its prescription (step 4b.3b, ADR-0005),
 * with the return of what came back. One per item; only the return is ever added, once.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $prescription_item_id
 * @property int $quantity
 * @property int $dispensed_by_user_id
 * @property Carbon $created_at
 * @property int|null $returned_quantity
 * @property string|null $return_reason
 * @property int|null $returned_by_user_id
 * @property Carbon|null $returned_at
 */
#[Fillable(['prescription_item_id', 'quantity', 'dispensed_by_user_id', 'created_at'])]
class PrescriptionDispensing extends Model
{
    use BelongsToTenant;

    /** A return is recorded up to this long after the dispensing, also after a renewal or a discharge. */
    public const RETURN_HOURS = 48;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'returned_quantity' => 'integer', 'created_at' => 'datetime', 'returned_at' => 'datetime'];
    }
}
