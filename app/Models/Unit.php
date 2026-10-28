<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\Role;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hospital of a tenant. UF and IANA timezone are required before publishing schedules (V1-02).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $uf
 * @property string|null $timezone
 * @property string|null $cnes
 * @property string|null $cnpj the provider's, for the BPA header (step 6b.6a1)
 * @property string|null $sus_acronym
 * @property string|null $sus_manager_name the SUS manager the BPA goes to
 * @property string|null $sus_manager_level M (municipal) or E (state)
 */
#[Fillable(['tenant_id', 'name', 'uf', 'timezone', 'cnes', 'cnpj', 'sus_acronym', 'sus_manager_name', 'sus_manager_level'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use BelongsToTenant, HasFactory;

    /** Same list as the units_uf_check constraint. */
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    /**
     * Without UF and timezone the unit cannot publish schedules (V1-02).
     */
    public function isComplete(): bool
    {
        return $this->uf !== null && $this->timezone !== null;
    }

    /**
     * Network admins and auditors see every unit of the tenant; other roles only their units.
     *
     * @param  Builder<Unit>  $query
     */
    public function scopeVisibleTo(Builder $query, Membership $membership): void
    {
        if ($membership->hasRole(Role::NetworkAdmin) || $membership->hasRole(Role::Auditor)) {
            return;
        }

        $query->whereIn('id', $membership->roleAssignments->pluck('unit_id')->filter()->all());
    }

    /**
     * @return HasMany<Sector, $this>
     */
    public function sectors(): HasMany
    {
        return $this->hasMany(Sector::class);
    }

    /**
     * @return HasMany<AttendancePoint, $this>
     */
    public function attendancePoints(): HasMany
    {
        return $this->hasMany(AttendancePoint::class);
    }
}
