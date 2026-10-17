<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Sector;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_admin_manages_units_and_sectors_of_own_tenant_only(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $ownUnit = Unit::factory()->for($admin->tenant)->create();
        $ownSector = Sector::factory()->for($ownUnit)->create();
        $foreignUnit = Unit::factory()->create();
        $foreignSector = Sector::factory()->for($foreignUnit)->create();

        app(CurrentTenant::class)->set($admin->tenant);
        $user = $admin->user;

        $this->assertTrue($user->can('update', $ownUnit));
        $this->assertTrue($user->can('update', $ownSector));
        $this->assertFalse($user->can('view', $foreignUnit));
        $this->assertFalse($user->can('update', $foreignUnit));
        $this->assertFalse($user->can('update', $foreignSector));
    }

    public function test_unit_manager_is_limited_to_assigned_units(): void
    {
        $manager = Membership::factory()->create();
        $assigned = Unit::factory()->for($manager->tenant)->create();
        $otherUnit = Unit::factory()->for($manager->tenant)->create();
        $manager->grant(Role::UnitManager, $assigned);

        app(CurrentTenant::class)->set($manager->tenant);
        $user = $manager->user;

        $this->assertTrue($user->can('view', $assigned));
        $this->assertFalse($user->can('update', $assigned));
        $this->assertTrue($user->can('update', Sector::factory()->for($assigned)->create()));
        $this->assertFalse($user->can('view', $otherUnit));
        $this->assertFalse($user->can('update', Sector::factory()->for($otherUnit)->create()));
    }

    public function test_revoked_membership_grants_nothing(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $unit = Unit::factory()->for($admin->tenant)->create();
        $admin->revoke();

        app(CurrentTenant::class)->set($admin->tenant);

        $this->assertFalse($admin->user->can('view', $unit));
    }

    public function test_network_admin_role_cannot_be_scoped_to_a_unit(): void
    {
        $membership = Membership::factory()->create();
        $unit = Unit::factory()->for($membership->tenant)->create();

        $this->expectException(QueryException::class);
        $membership->grant(Role::NetworkAdmin, $unit);
    }

    public function test_role_cannot_reference_unit_of_another_tenant(): void
    {
        $membership = Membership::factory()->create();

        $this->expectException(QueryException::class);
        $membership->grant(Role::UnitManager, Unit::factory()->create());
    }
}
