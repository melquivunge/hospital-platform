<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Sector;
use App\Models\Unit;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_overview_counts_only_what_the_member_can_see(): void
    {
        $manager = Membership::factory()->create();
        $assigned = Unit::factory()->for($manager->tenant)->create(['timezone' => null]);
        Sector::factory()->count(2)->for($assigned)->create();
        Unit::factory()->for($manager->tenant)->create();
        Unit::factory()->create();
        $manager->grant(Role::UnitManager, $assigned);

        $this->actingAs($manager->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('stats', ['units' => 1, 'sectors' => 2, 'incomplete' => 1])
                ->where('canCreateUnit', false));
    }

    public function test_network_screens_send_the_admin_of_a_network_without_units_to_the_units(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $member = Membership::factory()->for($admin->tenant)->create();
        foreach (['exam-overdue.index', 'surgery-overdue.index', 'stock.indicators.index'] as $route) {
            $this->actingAs($admin->user)->get(route($route))->assertRedirect(route('units.index'))
                ->assertInertiaFlash('toast.message', 'Cadastre uma unidade da rede para usar esta tela.');
            // Without the role, still no access.
            $this->actingAs($member->user)->get(route($route))->assertForbidden();
        }
    }

    public function test_the_network_admin_works_every_unit_without_the_roles_that_read_the_record(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        Unit::factory()->for($admin->tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->actingAs($admin->user);
        // Unit manager, reception, bed management and billing in every unit (user's decision, 27/10/2026).
        foreach (['appointments.queue', 'emergency.index', 'beds.index', 'admissions.index', 'billing-accounts.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        // Pharmacy, laboratory/imaging and surgical center read the record: still by role.
        foreach (['pharmacy.index', 'exam-queue.index', 'surgery-schedule.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        // Granted in memory only: nothing stored.
        $this->assertSame(1, $admin->roleAssignments()->count());
    }
}
