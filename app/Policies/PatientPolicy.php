<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Patient;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Tenancy\Role;

/**
 * Reception and care professionals of any unit register, find and correct patients of the network
 * (delivery 3 contract). Managers and admins do not see the registry for being managers.
 */
class PatientPolicy
{
    private const REGISTRARS = [Role::Reception, Role::Professional];

    public function viewAny(User $user): bool
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership !== null && self::registeringUnitIds($membership) !== [];
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Patient $patient): bool
    {
        $membership = UnitPolicy::membershipFor($user, $patient->tenant_id);

        return $membership !== null && self::registeringUnitIds($membership) !== [];
    }

    public function update(User $user, Patient $patient): bool
    {
        return $this->view($user, $patient);
    }

    /**
     * Units where the member registers patients.
     *
     * @return list<int>
     */
    public static function registeringUnitIds(Membership $membership): array
    {
        if ($membership->revoked_at !== null) {
            return [];
        }

        return array_values($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => in_array($assignment->role, self::REGISTRARS, true))
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)
            ->unique()
            ->all());
    }
}
