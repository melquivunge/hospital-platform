<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
