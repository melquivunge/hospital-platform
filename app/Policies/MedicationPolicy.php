<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\Role;

/**
 * The medication catalog and its safety rules are a network table: network admins keep them (delivery 4b
 * contract; the pharmacy role joins in step 4b.3).
 */
class MedicationPolicy
{
    public function manage(User $user): bool
    {
        return UnitPolicy::currentMembership($user)?->hasRole(Role::NetworkAdmin) ?? false;
    }
}
