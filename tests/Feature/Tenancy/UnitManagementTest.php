<?php

namespace Tests\Feature\Tenancy;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Unit;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnitManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Membership
    {
        return Membership::factory()->withRole(Role::NetworkAdmin)->create();
    }

    public function test_network_admin_creates_unit_and_it_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin->user)
            ->post(route('units.store'), ['name' => 'Hospital Horizonte', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo'])
            ->assertRedirect();

        $unit = Unit::withoutTenantScope()->where('name', 'Hospital Horizonte')->firstOrFail();
        $this->assertSame($admin->tenant_id, $unit->tenant_id);
        $this->assertTrue(AuditEvent::where('action', 'unit.created')->where('subject_id', $unit->id)->exists());
    }

    public function test_tenant_id_in_the_form_is_ignored(): void
    {
        $admin = $this->admin();
        $other = Membership::factory()->create();

        $this->actingAs($admin->user)
            ->post(route('units.store'), ['name' => 'Intruso', 'tenant_id' => $other->tenant_id])
            ->assertRedirect();

        $this->assertSame($admin->tenant_id, Unit::withoutTenantScope()->where('name', 'Intruso')->value('tenant_id'));
    }

    public function test_invalid_uf_timezone_and_duplicate_name_are_rejected(): void
    {
        $admin = $this->admin();
        Unit::factory()->for($admin->tenant)->create(['name' => 'Hospital Horizonte']);

        $this->actingAs($admin->user)
            ->post(route('units.store'), ['name' => 'hospital horizonte', 'uf' => 'XX', 'timezone' => 'Europe/Lisbon'])
            ->assertSessionHasErrors(['name', 'uf', 'timezone']);
    }

    public function test_same_unit_name_is_allowed_in_another_tenant(): void
    {
        $admin = $this->admin();
        Unit::factory()->create(['name' => 'Hospital Central']);

        $this->actingAs($admin->user)
            ->post(route('units.store'), ['name' => 'Hospital Central'])
            ->assertSessionHasNoErrors();
    }

    public function test_network_admin_updates_unit(): void
    {
        $admin = $this->admin();
        $unit = Unit::factory()->for($admin->tenant)->create(['uf' => null, 'timezone' => null]);

        $this->actingAs($admin->user)
            ->put(route('units.update', $unit), ['name' => 'Hospital Aurora Sul', 'uf' => 'RS', 'timezone' => 'America/Sao_Paulo'])
            ->assertRedirect(route('units.show', $unit));

        $this->assertSame('RS', Unit::withoutTenantScope()->find($unit->id)?->uf);
        $this->assertTrue(AuditEvent::where('action', 'unit.updated')->exists());
    }

    public function test_unit_of_another_tenant_is_not_found(): void
    {
        $admin = $this->admin();
        $foreign = Unit::factory()->create();

        $this->actingAs($admin->user)->get(route('units.show', $foreign))->assertNotFound();
        $this->actingAs($admin->user)->put(route('units.update', $foreign), ['name' => 'X'])->assertNotFound();
    }

    public function test_unit_manager_sees_only_assigned_units_and_cannot_create_or_edit_them(): void
    {
        $manager = Membership::factory()->create();
        $assigned = Unit::factory()->for($manager->tenant)->create();
        $other = Unit::factory()->for($manager->tenant)->create();
        $manager->grant(Role::UnitManager, $assigned);

        $this->actingAs($manager->user)
            ->get(route('units.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('units/index')
                ->where('canCreate', false)
                ->has('units', 1)
                ->where('units.0.id', $assigned->id));

        $this->get(route('units.show', $other))->assertForbidden();
        $this->post(route('units.store'), ['name' => 'Nova'])->assertForbidden();
        $this->put(route('units.update', $assigned), ['name' => 'Renomeada'])->assertForbidden();
    }

    public function test_auditor_sees_all_units_read_only(): void
    {
        $auditor = Membership::factory()->withRole(Role::Auditor)->create();
        Unit::factory()->count(2)->for($auditor->tenant)->create();

        $this->actingAs($auditor->user)
            ->get(route('units.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canCreate', false)->has('units', 2));
    }

    public function test_incomplete_unit_is_flagged(): void
    {
        $admin = $this->admin();
        Unit::factory()->for($admin->tenant)->create(['uf' => null]);

        $this->actingAs($admin->user)
            ->get(route('units.index'))
            ->assertInertia(fn (Assert $page) => $page->where('units.0.complete', false));
    }
}
