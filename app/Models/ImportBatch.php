<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded CSV file (step 5). Status: previewed → applying → applied.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $type
 * @property string $source_system
 * @property string $filename
 * @property string $path
 * @property string $status
 * @property int $received
 * @property int $created_by_user_id
 * @property Carbon|null $confirmed_at
 * @property Carbon $created_at
 */
#[Fillable(['type', 'source_system', 'filename', 'path', 'status', 'received', 'created_by_user_id'])]
class ImportBatch extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'received' => 'integer'];
    }

    /**
     * @return HasMany<ImportItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ImportItem::class);
    }

    /**
     * Item count per status; the counts always add up to `received`.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(['ready', 'created', 'identical', 'conflict', 'rejected'], 0);

        foreach ($this->items()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->total;
        }

        return $counts;
    }
}
