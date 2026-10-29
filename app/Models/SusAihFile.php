<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Step 6b.6b2b2 (ADR-0011): the SISAIH01 file of a unit and presentation competence, generated once (insert-only).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property Carbon $competence
 * @property string $path
 * @property string $sha256
 * @property int $account_count
 * @property int $generated_by_user_id
 * @property Carbon $generated_at
 */
class SusAihFile extends Model
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
