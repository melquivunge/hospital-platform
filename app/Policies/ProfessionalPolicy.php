<?php

namespace App\Policies;

use App\Models\Professional;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Tenancy\Role;

/**
 * Network admins manage all professionals, unit managers those based in their units (PRODUCT.md,
 * "Papéis"). Auditors only read. Base unit changes are checked again in ProfessionalRequest.
 */
class ProfessionalPolicy
{
    public function viewAny(User $user): bool
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::Auditor)
            || self::isAnyUnitManager($membership->roleAssignments->all())
        );
    }

    public function create(User $user): bool
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || self::isAnyUnitManager($membership->roleAssignments->all())
        );
    }

    public function view(User $user, Professional $professional): bool
    {
        $membership = UnitPolicy::membershipFor($user, $professional->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::Auditor)
            || $membership->hasRole(Role::UnitManager, $professional->base_unit_id)
        );
    }

    public function update(User $user, Professional $professional): bool
    {
        $membership = UnitPolicy::membershipFor($user, $professional->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::UnitManager, $professional->base_unit_id)
        );
    }

    /**
     * @param  array<RoleAssignment>  $assignments
     */
    private static function isAnyUnitManager(array $assignments): bool
    {
        return array_any($assignments, fn (RoleAssignment $assignment) => $assignment->role === Role::UnitManager);
    }
}
