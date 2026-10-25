<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A lot of one medication in the stock of one unit (step 4c.1a, ADR-0007). Its balance is kept by the database
 * from the movements and never goes below zero; nothing else of a lot ever changes.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int|null $medication_id
 * @property int|null $material_id a material instead of a medication (step 5b.6b); exactly one of the two
 * @property string $lot_code
 * @property Carbon $expires_on
 * @property string|null $manufacturer
 * @property int $balance
 * @property int $created_by_user_id
 * @property Carbon $created_at
 */
#[Fillable(['unit_id', 'medication_id', 'material_id', 'lot_code', 'expires_on', 'manufacturer', 'created_by_user_id', 'created_at'])]
class StockLot extends Model
{
    use BelongsToTenant;

    /** A lot this close to its expiry is flagged. */
    public const EXPIRING_DAYS = 30;

    public $timestamps = false;

    /**
     * Uppercase, no spaces: "ab 123" and "AB123" are one lot.
     */
    public static function code(string $code): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $code));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_on' => 'date', 'balance' => 'integer', 'created_at' => 'datetime'];
    }
}
