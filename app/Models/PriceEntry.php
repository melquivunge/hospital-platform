<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The price of a billable item in a table for a period (step 6.1a, ADR-0010), in cents. Never changed nor deleted;
 * only its end date is set once, when the next price starts (trigger). Periods never overlap (exclusion constraint).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $price_table_id
 * @property int $billing_item_id
 * @property int $price_cents
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until the last day, included
 * @property int $created_by_user_id
 * @property Carbon $created_at
 */
#[Fillable(['price_table_id', 'billing_item_id', 'price_cents', 'valid_from', 'valid_until', 'created_by_user_id', 'created_at'])]
class PriceEntry extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    public const MAX_CENTS = 100000000000;

    /**
     * "1.234,56", "1234,5" or "1234" (reais, as people type them) in cents; null when it is not a price.
     */
    public static function cents(string $text): ?int
    {
        $text = trim($text);
        // At most twelve integer digits (999.999.999.999): beyond it is a typo, and it would overflow.
        if (preg_match('/^(\d{1,3}(?:\.\d{3}){1,3}|\d{1,12})(?:,(\d{1,2}))?$/', $text, $parts) !== 1) {
            return null;
        }

        $cents = (int) str_replace('.', '', $parts[1]) * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');

        // The same ceiling as the database (one billion reais): the screen and the CSV refuse above it.
        return $cents > self::MAX_CENTS ? null : $cents;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'valid_from' => 'date', 'valid_until' => 'date', 'created_at' => 'datetime'];
    }
}
