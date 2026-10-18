<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Health insurer accepted by the network (step 3.5). Deactivated, never deleted: arrivals refer to it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $ans_code ANS registry number, six digits
 * @property bool $active
 */
#[Fillable(['name', 'ans_code', 'active'])]
class Insurer extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
