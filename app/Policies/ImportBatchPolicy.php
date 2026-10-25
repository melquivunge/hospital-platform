<?php

namespace App\Policies;

use App\Http\Controllers\PharmacyController;
use App\Models\ImportBatch;
use App\Models\Membership;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Tenancy\Role;

/**
 * Network admins import for any unit; importers only into their units (PRODUCT.md, "Papéis").
 * An importer sees and confirms only their own batches. Importing never approves qualifications.
 * The initial stock balance (step 4c.1b, ADR-0007) is imported only by the unit's Pharmacy role.
 */
class ImportBatchPolicy
{
    public function create(User $user): bool
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership !== null && (self::importableUnitIds($membership) !== [] || PharmacyController::unitIds($membership) !== []);
    }

    public function view(User $user, ImportBatch $batch): bool
    {
        $membership = UnitPolicy::membershipFor($user, $batch->tenant_id);

        return $membership !== null && (
            $membership->hasRole(Role::NetworkAdmin)
            || ($batch->created_by_user_id === $user->id && self::unitIdsFor($membership, $batch->type) !== [])
        );
    }

    /**
     * The units a batch of this type may write to: the stock (medications or materials) goes only to the units of the
     * Pharmacy role.
     *
     * @return list<int>|null null means every unit of the tenant
     */
    public static function unitIdsFor(Membership $membership, string $type): ?array
    {
        return in_array($type, ['stock', 'material_stock'], true) ? PharmacyController::unitIds($membership) : self::importableUnitIds($membership);
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
