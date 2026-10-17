<?php

namespace Tests\Feature\Tenancy;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_of_several_tenants_is_sent_to_the_selector(): void
    {
        $first = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $second = Membership::factory()->for($first->user)->withRole(Role::Auditor)->create();

        $this->actingAs($first->user)
            ->get(route('dashboard'))
            ->assertRedirect(route('current-tenant.edit'));

        $this->get(route('current-tenant.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('current-tenant/edit')
                ->has('tenants', 2)
                ->where('tenants.0.id', $first->tenant_id)
                ->where('tenants.1.id', $second->tenant_id));
    }

    public function test_selection_is_audited_and_shared_with_pages(): void
    {
        $first = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $second = Membership::factory()->for($first->user)->withRole(Role::Auditor)->create();

        $this->actingAs($first->user)
            ->post(route('current-tenant.update'), ['tenant_id' => $second->tenant_id])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($second->tenant_id, AuditEvent::where('action', 'tenant.selected')->value('tenant_id'));

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentTenant.id', $second->tenant_id)
                ->where('currentTenant.name', $second->tenant->name)
                ->where('canSwitchTenant', true));
    }

    public function test_selecting_a_foreign_or_revoked_tenant_is_denied_and_audited(): void
    {
        $member = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $revoked = Membership::factory()->for($member->user)->create();
        $revoked->revoke();
        $foreign = Membership::factory()->create();

        foreach ([$revoked->tenant_id, $foreign->tenant_id] as $tenantId) {
            $this->actingAs($member->user)
                ->post(route('current-tenant.update'), ['tenant_id' => $tenantId])
                ->assertSessionHasErrors('tenant_id');
        }

        $denied = AuditEvent::where('action', 'tenant.selection_denied')->orderBy('id')->get();
        $this->assertSame([$revoked->tenant_id, $foreign->tenant_id], $denied->pluck('context.requested_tenant_id')->all());
        $this->assertNull($denied->first()?->tenant_id);
    }

    public function test_after_choosing_the_user_returns_to_the_page_they_asked_for(): void
    {
        $first = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        Membership::factory()->for($first->user)->withRole(Role::Auditor)->create();

        $this->actingAs($first->user)->get(route('units.index'))->assertRedirect(route('current-tenant.edit'));

        $this->post(route('current-tenant.update'), ['tenant_id' => $first->tenant_id])
            ->assertRedirect(route('units.index'));
    }

    public function test_selector_lists_only_active_memberships(): void
    {
        $active = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $revoked = Membership::factory()->for($active->user)->create();
        $revoked->revoke();

        $this->actingAs($active->user)
            ->get(route('current-tenant.edit'))
            ->assertInertia(fn (Assert $page) => $page->has('tenants', 1)->where('tenants.0.id', $active->tenant_id));
    }
}
