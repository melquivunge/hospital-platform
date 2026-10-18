<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Tenant of the current request or job. Resolved from the session's active membership, never from input.
 */
final class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        // Row-level security on clinical tables reads the client from the session (ADR-0003); an empty
        // value matches no row. Like SET, a rollback of the enclosing transaction restores the previous
        // value: callers that switch clients inside a transaction restore their own previous client too.
        DB::select("select set_config('app.tenant_id', ?, false)", [$tenant === null ? '' : (string) $tenant->id]);
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
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
