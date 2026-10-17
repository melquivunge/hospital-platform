<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fail-closed tenant scoping: queries without a tenant context throw instead of returning everything.
 * Bypass only through withoutTenantScope(), for provisioning and test setup.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $query->where($query->qualifyColumn('tenant_id'), app(CurrentTenant::class)->require()->id);
        });

        static::creating(function (Model $model) {
            $current = app(CurrentTenant::class)->id();

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $current ?? throw new TenantContextViolation('Registro sem cliente definido.'));
            } elseif ($current !== null && (int) $model->getAttribute('tenant_id') !== $current) {
                throw new TenantContextViolation('Registro de outro cliente.');
            }
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('tenant_id')) {
                throw new TenantContextViolation('Registro não pode mudar de cliente.');
            }
        });
    }

    /**
     * @return Builder<self>
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
