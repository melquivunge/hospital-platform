<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Insurer;
use App\Models\Membership;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InsurerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_network_admin_keeps_the_insurers_and_only_deactivates_them(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->actingAs($admin->user)->post(route('insurers.store'), ['name' => 'Saúde Boa', 'ans_code' => '123456'])->assertSessionHasNoErrors();
        $this->post(route('insurers.store'), ['name' => 'saúde boa'])->assertSessionHasErrors('name', null, 'newInsurer');
        $this->post(route('insurers.store'), ['name' => 'Outro', 'ans_code' => '12AB'])->assertSessionHasErrors('ans_code', null, 'newInsurer');
        $insurer = Insurer::withoutTenantScope()->sole();

        $this->put(route('insurers.update', $insurer), ['name' => 'Saúde Boa', 'ans_code' => '123456', 'active' => false])->assertSessionHasNoErrors();
        $this->assertFalse(Insurer::withoutTenantScope()->sole()->active);
        $this->assertSame(['active'], AuditEvent::where('action', 'insurer.updated')->sole()->context['fields']);
        $this->get(route('insurers.index'))->assertInertia(fn (Assert $page) => $page->component('insurers/index')->has('insurers', 1));

        // Reception and another client's admin cannot touch it.
        app(CurrentTenant::class)->set(null);
        $reception = Membership::factory()->for($admin->tenant)->create();
        $reception->grant(Role::Reception, Unit::factory()->for($admin->tenant)->create());
        $this->actingAs($reception->user)->get(route('insurers.index'))->assertForbidden();
        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->actingAs($stranger->user)->put(route('insurers.update', $insurer), ['name' => 'Tomado', 'active' => true])->assertNotFound();
    }
}
