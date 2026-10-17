<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Tenant of the current request or job. Resolved from the session's active membership, never from input.
 */
final class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new TenantContextViolation('Operação sem cliente definido.');
    }
}
