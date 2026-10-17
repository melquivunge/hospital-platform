<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_with_single_active_membership_gets_its_tenant(): void
    {
        $membership = Membership::factory()->withRole(Role::NetworkAdmin)->create();

        $this->actingAs($membership->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas('tenant_id', $membership->tenant_id);
    }

    public function test_user_without_membership_is_denied(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_revoked_membership_loses_access_on_next_request(): void
    {
        $membership = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->actingAs($membership->user)->get(route('dashboard'))->assertOk();

        $membership->revoke();

        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_session_cannot_point_to_a_tenant_without_membership(): void
    {
        $membership = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $other = Tenant::factory()->create();

        $this->actingAs($membership->user)
            ->withSession(['tenant_id' => $other->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas('tenant_id', $membership->tenant_id);
    }

    public function test_member_can_switch_only_to_tenants_where_membership_is_active(): void
    {
        $first = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $user = $first->user;
        $second = Membership::factory()->for($user)->withRole(Role::Auditor)->create();
        $foreign = Tenant::factory()->create();

        $this->actingAs($user)
            ->post(route('current-tenant.update'), ['tenant_id' => $second->tenant_id])
            ->assertRedirect();
        $this->assertSame($second->tenant_id, session('tenant_id'));

        $this->post(route('current-tenant.update'), ['tenant_id' => $foreign->id])
            ->assertSessionHasErrors('tenant_id');
        $this->assertSame($second->tenant_id, session('tenant_id'));
    }

    public function test_member_of_several_tenants_must_choose_one(): void
    {
        $first = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        Membership::factory()->for($first->user)->withRole(Role::Auditor)->create();

        // No data is rendered: the user is sent to pick an institution first (step 2b).
        $this->actingAs($first->user)
            ->get(route('dashboard'))
            ->assertRedirect(route('current-tenant.edit'));
    }
}
