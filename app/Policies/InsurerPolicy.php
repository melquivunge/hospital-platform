<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\Role;

/**
 * Insurers are a network table: only network admins change it (delivery 3 contract).
 */
class InsurerPolicy
{
    public function manage(User $user): bool
    {
        return UnitPolicy::currentMembership($user)?->hasRole(Role::NetworkAdmin) ?? false;
    }
}
