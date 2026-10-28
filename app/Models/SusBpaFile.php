<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Step 6b.6a2 (ADR-0011): the BPA file of a unit and processing competence, generated once (insert-only).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property Carbon $competence
 * @property string $path
 * @property string $sha256
 * @property int $line_count
 * @property int $sheet_count
 * @property int $account_count
 * @property int $generated_by_user_id
 * @property Carbon $generated_at
 */
class SusBpaFile extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['competence' => 'date', 'generated_at' => 'datetime'];
    }
}
