<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\Role;

/**
 * The exam catalog is a network table: network admins keep it (delivery 5 contract).
 */
class ExamPolicy
{
    public function manage(User $user): bool
    {
        return UnitPolicy::currentMembership($user)?->hasRole(Role::NetworkAdmin) ?? false;
    }
}
