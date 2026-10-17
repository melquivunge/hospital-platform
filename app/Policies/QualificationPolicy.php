<?php

namespace App\Policies;

use App\Models\Professional;
use App\Models\Qualification;
use App\Models\User;
use App\Tenancy\Role;

/**
 * Requests come from the network admin or the manager of the professional's base unit; decisions
 * belong to the destination unit (network admin or that unit's manager). Approval never crosses units.
 */
class QualificationPolicy
{
    public function request(User $user, Professional $professional): bool
    {
        $membership = UnitPolicy::membershipFor($user, $professional->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::UnitManager, $professional->base_unit_id)
        );
    }

    public function decide(User $user, Qualification $qualification): bool
    {
        $membership = UnitPolicy::membershipFor($user, $qualification->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::UnitManager, $qualification->unit_id)
        );
    }
}
