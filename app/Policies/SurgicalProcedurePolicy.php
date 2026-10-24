<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\Role;

/**
 * The surgical procedures and the operating rooms are network tables: network admins keep them (ADR-0009).
 */
class SurgicalProcedurePolicy
{
    public function manage(User $user): bool
    {
        return UnitPolicy::currentMembership($user)?->hasRole(Role::NetworkAdmin) ?? false;
    }
}
