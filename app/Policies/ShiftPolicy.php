<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\RoleAssignment;
use App\Models\Shift;
use App\Models\Unit;
use App\Models\User;
use App\Tenancy\Role;

/**
 * Coordinators of the unit and network admins build, publish and cancel shifts; unit managers and
 * auditors read (user decision, 17/10/2026). Professionals see their own shifts in a later step.
 */
class ShiftPolicy
{
    private const READERS = [Role::UnitManager, Role::ShiftCoordinator];

    public function viewAny(User $user): bool
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || $membership->hasRole(Role::Auditor)
            || $membership->roleAssignments->contains(fn (RoleAssignment $assignment) => in_array($assignment->role, self::READERS, true))
        );
    }

    public function viewUnit(User $user, Unit $unit): bool
    {
        $membership = UnitPolicy::membershipFor($user, $unit->tenant_id);

        return $membership !== null && self::readsUnit($membership, $unit->id);
    }

    public function view(User $user, Shift $shift): bool
    {
        $membership = UnitPolicy::membershipFor($user, $shift->tenant_id);

        return $membership !== null && self::readsUnit($membership, $shift->unit_id);
    }

    public function manage(User $user, Unit $unit): bool
    {
        $membership = UnitPolicy::membershipFor($user, $unit->tenant_id);

        return $membership !== null && self::managesUnit($membership, $unit->id);
    }

    public function update(User $user, Shift $shift): bool
    {
        $membership = UnitPolicy::membershipFor($user, $shift->tenant_id);

        return $membership !== null && self::managesUnit($membership, $shift->unit_id);
    }

    /**
     * Unit ids whose schedule the member may read.
     *
     * @return list<int>|null null means every unit of the tenant
     */
    public static function readableUnitIds(Membership $membership): ?array
    {
        if ($membership->revoked_at !== null) {
            return [];
        }

        if ($membership->hasRole(Role::NetworkAdmin) || $membership->hasRole(Role::Auditor)) {
            return null;
        }

        return array_values($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => in_array($assignment->role, self::READERS, true))
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)
            ->unique()
            ->all());
    }

    private static function readsUnit(Membership $membership, int $unitId): bool
    {
        $ids = self::readableUnitIds($membership);

        return $ids === null || in_array($unitId, $ids, true);
    }

    private static function managesUnit(Membership $membership, int $unitId): bool
    {
        return $membership->hasRole(Role::NetworkAdmin) || $membership->hasRole(Role::ShiftCoordinator, $unitId);
    }
}
