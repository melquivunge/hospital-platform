<?php

namespace App\Policies;

use App\Models\RoleAssignment;
use App\Models\User;
use App\Tenancy\Role;

/**
 * Unit managers and network admins merge registrations (user decision, 18/10/2026). They see identity
 * data only, never clinical content.
 */
class PatientMergePolicy
{
    public function merge(User $user): bool
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->roleAssignments->contains(fn (RoleAssignment $assignment) => $assignment->role === Role::UnitManager)
        );
    }
}
