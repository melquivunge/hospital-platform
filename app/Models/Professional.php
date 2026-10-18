<?php

namespace App\Models;

use App\Professionals\QualificationStatus;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\Role;
use Database\Factories\ProfessionalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Professional of a tenant (V1-03). Not a login account. The base unit is administrative: it only
 * changes through an authorized edit with a reason, never by the unit selected in the interface.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $base_unit_id
 * @property string $name
 * @property string|null $council
 * @property string|null $council_number
 * @property string|null $council_uf
 * @property string|null $external_id
 * @property string|null $email
 * @property string|null $phone
 * @property int $eligibility_version
 * @property int|null $membership_id
 */
#[Fillable(['base_unit_id', 'name', 'council', 'council_number', 'council_uf', 'external_id', 'email', 'phone'])]
class Professional extends Model
{
    /** @use HasFactory<ProfessionalFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['base_unit_id' => 'integer', 'eligibility_version' => 'integer', 'membership_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * Network admins and auditors see every professional; unit managers those based in their units
     * or with a non-revoked qualification there (the destination unit decides it).
     *
     * @param  Builder<Professional>  $query
     */
    public function scopeVisibleTo(Builder $query, Membership $membership): void
    {
        if ($membership->hasRole(Role::NetworkAdmin) || $membership->hasRole(Role::Auditor)) {
            return;
        }

        $unitIds = self::managedUnitIds($membership);
        $query->where(fn (Builder $query) => $query
            ->whereIn('base_unit_id', $unitIds)
            ->orWhereHas('qualifications', fn (Builder $query) => $query->whereIn('unit_id', $unitIds)->where('status', '<>', QualificationStatus::Revoked)));
    }

    /**
     * @return HasMany<Qualification, $this>
     */
    public function qualifications(): HasMany
    {
        return $this->hasMany(Qualification::class);
    }

    /**
     * Units where the member may register professionals or set them as base unit.
     *
     * @return Builder<Unit>
     */
    public static function manageableUnits(Membership $membership): Builder
    {
        return Unit::query()->when(
            ! $membership->hasRole(Role::NetworkAdmin),
            fn (Builder $query) => $query->whereIn('id', self::managedUnitIds($membership)),
        );
    }

    /**
     * @return list<int>
     */
    public static function managedUnitIds(Membership $membership): array
    {
        if ($membership->revoked_at !== null) {
            return [];
        }

        return array_values($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => $assignment->role === Role::UnitManager)
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)
            ->all());
    }
}
