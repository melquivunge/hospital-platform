<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A price table of the network (step 6.1a, ADR-0010): the private one and the ones insurers' contracts point to.
 * Deactivated, never deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property bool $active
 */
#[Fillable(['name', 'active'])]
class PriceTable extends Model
{
    use BelongsToTenant;

    /**
     * The price in cents of an item in this table on a day, or null without one.
     */
    public function priceOn(int $billingItemId, string $date): ?int
    {
        $price = PriceEntry::query()->where('price_table_id', $this->id)->where('billing_item_id', $billingItemId)
            ->where('valid_from', '<=', $date)->where(fn ($open) => $open->whereNull('valid_until')->orWhere('valid_until', '>=', $date))
            ->value('price_cents');

        return $price === null ? null : (int) $price;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
