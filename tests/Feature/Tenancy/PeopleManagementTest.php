<?php

namespace Tests\Feature\Tenancy;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\RoleAssignment;
use App\Models\Unit;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PeopleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Membership
    {
        return Membership::factory()->withRole(Role::NetworkAdmin)->create();
    }

    public function test_only_network_admins_manage_people(): void
    {
        $manager = Membership::factory()->create();
        $manager->grant(Role::UnitManager, Unit::factory()->for($manager->tenant)->create());

        $this->actingAs($manager->user)->get(route('people.index'))->assertForbidden();
        $this->post(route('people.store'), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'auditor'])->assertForbidden();
    }

    public function test_admin_lists_people_of_own_tenant_only(): void
    {
        $admin = $this->admin();
        Membership::factory()->for($admin->tenant)->withRole(Role::Auditor)->create();
        Membership::factory()->withRole(Role::Auditor)->create();

        $this->actingAs($admin->user)
            ->get(route('people.index'))
            ->assertInertia(fn (Assert $page) => $page->component('people/index')->has('people', 2));
    }

    public function test_inviting_a_new_person_creates_identity_role_and_sends_set_password_link(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $unit = Unit::factory()->for($admin->tenant)->create();

        $this->actingAs($admin->user)
            ->post(route('people.store'), ['name' => 'Bia Coordenação', 'email' => 'Bia@Example.TEST', 'role' => 'shift_coordinator', 'unit_id' => $unit->id])
            ->assertRedirect();

        $user = User::where('email', 'bia@example.test')->firstOrFail();
        $membership = Membership::where('user_id', $user->id)->where('tenant_id', $admin->tenant_id)->firstOrFail();
        app(CurrentTenant::class)->set($admin->tenant);
        $this->assertTrue($membership->hasRole(Role::ShiftCoordinator, $unit->id));
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertTrue(AuditEvent::where('action', 'member.invited')->where('tenant_id', $admin->tenant_id)->exists());
    }

    public function test_existing_identity_is_linked_without_new_account_or_email(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $existing = User::factory()->create(['email' => 'ana@example.test']);

        $this->actingAs($admin->user)
            ->post(route('people.store'), ['name' => 'Outro nome', 'email' => 'ANA@example.test', 'role' => 'auditor'])
            ->assertRedirect();

        $this->assertSame(1, User::where('email', 'ana@example.test')->count());
        $this->assertSame($existing->name, $existing->fresh()?->name);
        Notification::assertNothingSentTo($existing);
    }

    public function test_invite_validates_role_scope_and_unit_of_same_tenant(): void
    {
        $admin = $this->admin();
        $foreignUnit = Unit::factory()->create();

        $this->actingAs($admin->user)
            ->post(route('people.store'), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'professional'])
            ->assertSessionHasErrors('unit_id');
        $this->post(route('people.store'), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'professional', 'unit_id' => $foreignUnit->id])
            ->assertSessionHasErrors('unit_id');
        $this->post(route('people.store'), ['name' => 'X', 'email' => 'x@example.test', 'role' => 'superuser'])
            ->assertSessionHasErrors('role');
    }

    public function test_inviting_an_active_member_is_rejected_and_a_revoked_one_is_reactivated(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $member = Membership::factory()->for($admin->tenant)->withRole(Role::Auditor)->create();

        $this->actingAs($admin->user)
            ->post(route('people.store'), ['name' => 'X', 'email' => $member->user->email, 'role' => 'auditor'])
            ->assertSessionHasErrors('email');

        $member->revoke();
        $this->post(route('people.store'), ['name' => 'X', 'email' => $member->user->email, 'role' => 'auditor'])
            ->assertRedirect();
        $this->assertNull($member->fresh()?->revoked_at);
    }

    public function test_admin_grants_and_removes_roles_with_audit(): void
    {
        $admin = $this->admin();
        $member = Membership::factory()->for($admin->tenant)->create();
        $unit = Unit::factory()->for($admin->tenant)->create();

        $this->actingAs($admin->user)
            ->post(route('people.roles.store', $member), ['role' => 'unit_manager', 'unit_id' => $unit->id])
            ->assertRedirect(route('people.show', $member));

        $assignment = RoleAssignment::withoutTenantScope()->where('membership_id', $member->id)->firstOrFail();
        $this->delete(route('people.roles.destroy', [$member, $assignment]))->assertRedirect(route('people.show', $member));

        $this->assertSame(0, RoleAssignment::withoutTenantScope()->where('membership_id', $member->id)->count());
        $this->assertTrue(AuditEvent::where('action', 'role.revoked')->exists());
    }

    public function test_last_network_admin_is_protected_and_nobody_revokes_themselves(): void
    {
        $admin = $this->admin();
        $adminRole = RoleAssignment::withoutTenantScope()->where('membership_id', $admin->id)->firstOrFail();

        $this->actingAs($admin->user)
            ->delete(route('people.roles.destroy', [$admin, $adminRole]))
            ->assertSessionHasErrors('role');
        $this->post(route('people.revoke', $admin))->assertSessionHasErrors('membership');

        $this->assertNull($admin->fresh()?->revoked_at);
        $this->assertSame(1, RoleAssignment::withoutTenantScope()->where('membership_id', $admin->id)->count());
    }

    public function test_admin_revokes_and_reactivates_a_member(): void
    {
        $admin = $this->admin();
        $member = Membership::factory()->for($admin->tenant)->withRole(Role::Auditor)->create();

        $this->actingAs($admin->user)->post(route('people.revoke', $member))->assertRedirect();
        $this->assertNotNull($member->fresh()?->revoked_at);

        $this->post(route('people.reactivate', $member))->assertRedirect();
        $this->assertNull($member->fresh()?->revoked_at);
        $this->assertTrue(AuditEvent::where('action', 'membership.reactivated')->exists());
    }

    public function test_membership_of_another_tenant_is_not_found(): void
    {
        $admin = $this->admin();
        $foreign = Membership::factory()->withRole(Role::Auditor)->create();

        $this->actingAs($admin->user)->get(route('people.show', $foreign))->assertNotFound();
        $this->post(route('people.revoke', $foreign))->assertNotFound();
    }

    public function test_password_reset_confirms_the_email_address(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'nova-senha-segura',
                'password_confirmation' => 'nova-senha-segura',
            ]);

            return true;
        });

        $this->assertNotNull($user->fresh()?->email_verified_at);
    }

    public function test_reinvitation_does_not_restore_previous_roles(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $member = Membership::factory()->for($admin->tenant)->withRole(Role::NetworkAdmin)->create();
        $member->revoke();

        $this->actingAs($admin->user)
            ->post(route('people.store'), ['name' => 'X', 'email' => $member->user->email, 'role' => 'auditor'])
            ->assertRedirect();

        $roles = RoleAssignment::withoutTenantScope()->where('membership_id', $member->id)->pluck('role')->map->value->all();
        $this->assertSame(['auditor'], $roles);
    }

    public function test_legacy_mixed_case_email_is_found_not_duplicated(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $legacy = User::factory()->create();
        DB::table('users')->where('id', $legacy->id)->update(['email' => 'Legado@Example.test']);

        $this->actingAs($admin->user)
            ->post(route('people.store'), ['name' => 'X', 'email' => 'legado@example.test', 'role' => 'auditor'])
            ->assertRedirect();

        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['legado@example.test'])->count());
    }

    public function test_non_admin_cannot_change_roles_or_access(): void
    {
        $admin = $this->admin();
        $member = Membership::factory()->for($admin->tenant)->withRole(Role::Auditor)->create();
        $auditor = Membership::factory()->for($admin->tenant)->withRole(Role::Auditor)->create();
        $assignment = RoleAssignment::withoutTenantScope()->where('membership_id', $member->id)->firstOrFail();

        $this->actingAs($auditor->user)
            ->post(route('people.roles.store', $member), ['role' => 'auditor'])->assertForbidden();
        $this->delete(route('people.roles.destroy', [$member, $assignment]))->assertForbidden();
        $this->post(route('people.revoke', $member))->assertForbidden();
        $this->post(route('people.reactivate', $member))->assertForbidden();
    }
}
