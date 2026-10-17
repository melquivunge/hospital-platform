<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Tenancy\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
        ];
    }

    /**
     * Role without audit event (test setup). Unit-scoped roles get a unit of the same tenant.
     */
    public function withRole(Role $role): static
    {
        return $this->afterCreating(function (Membership $membership) use ($role) {
            $membership->roleAssignments()->create([
                'tenant_id' => $membership->tenant_id,
                'role' => $role,
                'unit_id' => $role->requiresUnit() ? Unit::factory()->create(['tenant_id' => $membership->tenant_id])->id : null,
            ]);
        });
    }
}
