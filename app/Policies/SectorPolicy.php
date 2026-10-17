<?php

namespace App\Policies;

use App\Models\Sector;
use App\Models\Unit;
use App\Models\User;
use App\Tenancy\Role;

class SectorPolicy
{
    public function create(User $user, Unit $unit): bool
    {
        $membership = UnitPolicy::membershipFor($user, $unit->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::UnitManager, $unit->id)
        );
    }

    public function update(User $user, Sector $sector): bool
    {
        $membership = UnitPolicy::membershipFor($user, $sector->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::UnitManager, $sector->unit_id)
        );
    }
}
