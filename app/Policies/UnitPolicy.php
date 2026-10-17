<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Unit;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;

class UnitPolicy
{
    public function view(User $user, Unit $unit): bool
    {
        $membership = self::membershipFor($user, $unit->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::Auditor)
            || $membership->hasAnyRoleIn($unit->id)
        );
    }

    public function update(User $user, Unit $unit): bool
    {
        return self::membershipFor($user, $unit->tenant_id)?->hasRole(Role::NetworkAdmin) ?? false;
    }

    /**
     * Active membership in the record's tenant, only when that tenant is the request's context.
     */
    public static function membershipFor(User $user, int $tenantId): ?Membership
    {
        // Deactivated accounts get nothing, also outside HTTP (jobs, commands).
        if ($user->deactivated_at !== null || app(CurrentTenant::class)->id() !== $tenantId) {
            return null;
        }

        return $user->activeMembershipIn($tenantId);
    }
}
