<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An item of a patient's account (step 6.2a, ADR-0010), generated from the care record it points to (origin type and
 * id, unique): the code, name and price in cents of the billable item on the day of that record, frozen. A null price
 * is pending (no billable item or no price that day). Never changed nor deleted (trigger).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $billing_account_id
 * @property string $origin_type visit | exam_order_item | surgery
 * @property int $origin_id
 * @property int|null $billing_item_id
 * @property string|null $code
 * @property string $name
 * @property int $quantity
 * @property int|null $unit_price_cents
 * @property bool $needs_authorization
 * @property Carbon $performed_on
 * @property Carbon $created_at
 */
class BillingAccountItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_cents' => 'integer', 'needs_authorization' => 'boolean', 'performed_on' => 'date', 'created_at' => 'datetime'];
    }
}
