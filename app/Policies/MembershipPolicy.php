<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Tenancy\Role;

/**
 * People management is for network admins of the current tenant only.
 */
class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return UnitPolicy::currentMembership($user)?->hasRole(Role::NetworkAdmin) ?? false;
    }

    public function update(User $user, Membership $membership): bool
    {
        return UnitPolicy::membershipFor($user, $membership->tenant_id)?->hasRole(Role::NetworkAdmin) ?? false;
    }
}
