<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * An insurer's contract (step 6.1b, ADR-0010): the price table it pays by, an adjustment in basis points (1% = 100)
 * and the billable items that need its authorization. One per insurer.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $insurer_id
 * @property int $price_table_id
 * @property int $adjustment_bp
 */
#[Fillable(['insurer_id', 'price_table_id', 'adjustment_bp', 'provider_code'])]
class InsurerContract extends Model
{
    use BelongsToTenant;

    /**
     * The price in cents of an item on a day: by the insurer's contract (with its adjustment, rounded half up to the
     * cent), or by the private table without an insurer; null when there is no contract, table or price.
     */
    public static function priceFor(?int $insurerId, int $billingItemId, string $date): ?int
    {
        $contract = $insurerId === null ? null : self::query()->where('insurer_id', $insurerId)->first();
        $table = $insurerId === null ? PriceTable::query()->where('private', true)->first()
            : ($contract === null ? null : PriceTable::query()->find($contract->price_table_id));
        $price = $table?->priceOn($billingItemId, $date);
        if ($price === null || $contract === null || $contract->adjustment_bp === 0) {
            return $price;
        }

        $adjusted = intdiv($price * (10000 + $contract->adjustment_bp) + 5000, 10000);

        // Above the database's ceiling the item would fail its insert: no price, a pending item billing sees (6.7).
        return $adjusted > PriceEntry::MAX_CENTS ? null : $adjusted;
    }

    /**
     * Whether the insurer's contract needs an authorization for the item.
     */
    public static function needsAuthorization(int $insurerId, int $billingItemId): bool
    {
        return DB::table('insurer_contract_authorizations')->join('insurer_contracts', 'insurer_contracts.id', '=', 'insurer_contract_authorizations.insurer_contract_id')
            ->where('insurer_contracts.insurer_id', $insurerId)->where('insurer_contract_authorizations.billing_item_id', $billingItemId)->exists();
    }

    /**
     * "10", "-5,5" or "12,75" (percent, as people type it) in basis points; null when it is not a percentage.
     */
    public static function basisPoints(string $text): ?int
    {
        if (preg_match('/^(-?)(\d{1,4})(?:,(\d{1,2}))?$/', trim($text), $parts) !== 1) {
            return null;
        }
        $points = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '0', 2, '0');

        return $parts[1] === '-' ? -$points : $points;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['adjustment_bp' => 'integer'];
    }
}
