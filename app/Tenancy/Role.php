<?php

namespace App\Tenancy;

/**
 * V1 roles (PRODUCT.md, "Papéis e autorização mínima"). Scope rules are also enforced by a CHECK constraint.
 */
enum Role: string
{
    case NetworkAdmin = 'network_admin';
    case UnitManager = 'unit_manager';
    case ShiftCoordinator = 'shift_coordinator';
    case Professional = 'professional';
    case Importer = 'importer';
    case Auditor = 'auditor';

    public function requiresUnit(): bool
    {
        return in_array($this, [self::UnitManager, self::ShiftCoordinator, self::Professional, self::Importer], true);
    }
}
