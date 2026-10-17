<?php

namespace App\Models;

use App\Tenancy\Role;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Access of a global identity (user) to one tenant. The email alone never grants access.
 * Not tenant-scoped on purpose: it is the source of the tenant context.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property Carbon|null $revoked_at
 */
#[Fillable(['tenant_id', 'user_id'])]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * @param  Builder<Membership>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * True when the role is held tenant-wide, or for the given unit.
     */
    public function hasRole(Role $role, ?int $unitId = null): bool
    {
        return $this->revoked_at === null && $this->roleAssignments->contains(
            fn (RoleAssignment $assignment) => $assignment->role === $role
                && ($assignment->unit_id === null || (int) $assignment->unit_id === $unitId),
        );
    }

    public function hasAnyRoleIn(int $unitId): bool
    {
        return $this->revoked_at === null
            && $this->roleAssignments->contains(fn (RoleAssignment $assignment) => (int) $assignment->unit_id === $unitId);
    }

    /**
     * Access change and its audit event commit together or not at all.
     */
    public function grant(Role $role, ?Unit $unit = null): RoleAssignment
    {
        if ($this->revoked_at !== null) {
            throw new LogicException('Vínculo revogado não recebe papéis.');
        }

        $assignment = DB::transaction(function () use ($role, $unit) {
            $assignment = $this->roleAssignments()->create([
                'tenant_id' => $this->tenant_id,
                'role' => $role,
                'unit_id' => $unit?->id,
            ]);

            AuditEvent::record('role.granted', $this->tenant_id, $this, ['role' => $role->value, 'unit_id' => $unit?->id]);

            return $assignment;
        });
        $this->unsetRelation('roleAssignments');

        return $assignment;
    }

    public function revoke(): void
    {
        if ($this->revoked_at !== null) {
            return;
        }

        DB::transaction(function () {
            $this->forceFill(['revoked_at' => now()])->save();

            AuditEvent::record('membership.revoked', $this->tenant_id, $this, ['user_id' => $this->user_id]);
        });
    }
}
