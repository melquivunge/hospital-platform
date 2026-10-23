<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The inventory of a unit's stock (step 4c.3b, ADR-0007): counting → submitted → approved or refused.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property string $status
 * @property int $opened_by_user_id
 * @property Carbon $opened_at
 * @property int|null $submitted_by_user_id
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $refusal_reason
 * @property list<int> $participant_user_ids who counted or submitted it
 */
#[Fillable(['unit_id', 'status', 'opened_by_user_id', 'opened_at', 'submitted_by_user_id', 'submitted_at', 'decided_by_user_id', 'decided_at', 'refusal_reason', 'participant_user_ids'])]
class StockInventory extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return HasMany<StockInventoryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockInventoryLine::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'participant_user_ids' => 'array'];
    }
}
