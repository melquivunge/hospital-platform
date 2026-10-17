<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Unit;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
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
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404)->missing('message'))
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

    public function test_server_error_hides_internal_message_unless_debugging(): void
    {
        Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('segredo interno'));

        config(['app.debug' => false]);
        $this->get('/_test/boom')
            ->assertStatus(500)
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 500)->missing('message'))
            ->assertDontSee('segredo interno');

        config(['app.debug' => true]);
        $this->get('/_test/boom')->assertStatus(500)->assertSee('segredo interno');
    }

    public function test_branded_page_keeps_original_headers(): void
    {
        Route::middleware('web')->get('/_test/maintenance', fn () => abort(503, '', ['Retry-After' => '120']));

        $this->get('/_test/maintenance')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '120')
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 503));
    }
}
