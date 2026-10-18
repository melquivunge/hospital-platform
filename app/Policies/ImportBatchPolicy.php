<?php

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\Membership;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Tenancy\Role;

/**
 * Network admins import for any unit; importers only into their units (PRODUCT.md, "Papéis").
 * An importer sees and confirms only their own batches. Importing never approves qualifications.
 */
class ImportBatchPolicy
{
    public function create(User $user): bool
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership !== null && self::importableUnitIds($membership) !== [];
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        $membership = UnitPolicy::membershipFor($user, $batch->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || ($batch->created_by_user_id === $user->id && self::importableUnitIds($membership) !== [])
        );
    }

    /**
     * @return list<int>|null null means every unit of the tenant
     */
    public static function importableUnitIds(Membership $membership): ?array
    {
        if ($membership->revoked_at !== null) {
            return [];
        }

        if ($membership->hasRole(Role::NetworkAdmin)) {
            return null;
        }

        return array_values($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => $assignment->role === Role::Importer)
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)
            ->all());
    }
}
