<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Unit;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_found_uses_the_branded_page_without_internal_details(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $foreign = Unit::factory()->create();

        $this->actingAs($admin->user)
            ->get(route('units.show', $foreign))
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404))
            ->assertDontSee('App\\Models', false);
    }

    public function test_forbidden_uses_the_branded_page(): void
    {
        $manager = Membership::factory()->create();
        $manager->grant(Role::UnitManager, Unit::factory()->for($manager->tenant)->create());

        $this->actingAs($manager->user)
            ->get(route('units.create'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 403));
    }

    public function test_json_requests_keep_json_errors(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();

        $this->actingAs($admin->user)
            ->getJson(route('units.show', Unit::factory()->create()))
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
