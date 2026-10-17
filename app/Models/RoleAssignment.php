<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Role of a membership, tenant-wide (unit_id null) or for one unit.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $membership_id
 * @property Role $role
 * @property int|null $unit_id
 */
#[Fillable(['tenant_id', 'membership_id', 'role', 'unit_id'])]
class RoleAssignment extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }
}
