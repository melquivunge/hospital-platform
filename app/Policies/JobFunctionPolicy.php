<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\Role;

/**
 * Functions are a network catalog: only network admins change it.
 */
class JobFunctionPolicy
{
    public function manage(User $user): bool
    {
        return UnitPolicy::currentMembership($user)?->hasRole(Role::NetworkAdmin) ?? false;
    }
}
