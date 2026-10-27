<?php

namespace App\Models;

use App\Tenancy\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deactivated_at
 * @property string|null $avatar_path
 * @property-read string|null $avatar
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'avatar_path'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** @var list<string> */
    protected $appends = ['avatar'];

    /**
     * URL of the profile photo; the file name in the query busts the browser cache on change.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatar(): Attribute
    {
        return Attribute::get(fn () => $this->avatar_path === null ? null
            : route('profile.photo.show', ['v' => substr(sha1($this->avatar_path), 0, 10)]));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMembershipIn(int $tenantId): ?Membership
    {
        // ponytail: one query per policy check; eager-load per request when list screens need it (step 2b).
        $membership = $this->memberships()->active()->where('tenant_id', $tenantId)->with('roleAssignments')->first();
        if ($membership?->hasRole(Role::NetworkAdmin) ?? false) {
            // User's decision (27/10/2026): the network admin works every unit with the roles that never read the record.
            // Granted in memory only, never stored: pharmacy, laboratory, imaging, surgical center and attending stay by
            // role (they read the record).
            $granted = collect(Unit::query()->where('tenant_id', $tenantId)->pluck('id')->all())
                ->crossJoin([Role::UnitManager, Role::Reception, Role::BedManagement, Role::Billing])
                ->map(fn (array $pair) => (new RoleAssignment)->forceFill(['membership_id' => $membership->id, 'tenant_id' => $tenantId, 'unit_id' => (int) $pair[0], 'role' => $pair[1]]));
            $membership->setRelation('roleAssignments', $membership->roleAssignments->concat($granted));
        }

        return $membership;
    }

    /**
     * Deactivated accounts cannot log in and lose existing sessions on the next request (V1-01).
     */
    public function deactivate(): void
    {
        DB::transaction(function () {
            $this->forceFill(['deactivated_at' => now()])->save();

            // One event per client the person belongs to, so each client's trail shows it.
            $tenantIds = $this->memberships()->pluck('tenant_id');
            foreach ($tenantIds->isEmpty() ? [null] : $tenantIds as $tenantId) {
                AuditEvent::record('user.deactivated', $tenantId, $this);
            }
            foreach ($this->memberships()->get() as $membership) {
                Shift::vacateFor($membership);
            }
            // ADR-0006: the signer credentials go with the access.
            SigningAccount::withoutTenantScope()->where('user_id', $this->id)->delete();
        });
    }
}
