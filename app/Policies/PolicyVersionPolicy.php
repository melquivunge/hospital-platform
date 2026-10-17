<?php

namespace App\Policies;

use App\Models\RoleAssignment;
use App\Models\Unit;
use App\Models\User;
use App\Tenancy\Role;

/**
 * Network rules: network admins publish. Local values: network admins or the unit's manager.
 */
class PolicyVersionPolicy
{
    public function viewAny(User $user): bool
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::Auditor)
            || $membership->roleAssignments->contains(fn (RoleAssignment $assignment) => $assignment->role === Role::UnitManager)
        );
    }

    public function publishNetwork(User $user): bool
    {
        return UnitPolicy::currentMembership($user)?->hasRole(Role::NetworkAdmin) ?? false;
    }

    public function publishLocal(User $user, Unit $unit): bool
    {
        $membership = UnitPolicy::membershipFor($user, $unit->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::UnitManager, $unit->id)
        );
    }
}
