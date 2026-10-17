<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_members_can_visit_the_dashboard()
    {
        // Without an active membership there is no operational access (TenantContextTest).
        $user = Membership::factory()->withRole(Role::NetworkAdmin)->create()->user;
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }
}
