<?php

namespace Tests\Feature\Tenancy;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Unit;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->create();
        $user->deactivate();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_deactivation_ends_existing_session_on_next_request(): void
    {
        $membership = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $user = $membership->user;
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->deactivate();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_access_changes_are_audited_with_actor_scope_and_instant(): void
    {
        $actor = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $target = Membership::factory()->for($actor->tenant)->withRole(Role::Professional)->create();
        $this->actingAs($actor->user);

        $target->revoke();
        $target->user->deactivate();

        $events = AuditEvent::orderBy('id')->get();
        $this->assertSame(['membership.revoked', 'user.deactivated'], $events->pluck('action')->all());
        $this->assertSame($actor->user_id, $events[0]->actor_user_id);
        $this->assertSame($actor->tenant_id, $events[0]->tenant_id);
        $this->assertNotNull($events[0]->occurred_at);
    }

    public function test_audit_events_are_append_only(): void
    {
        $membership = Membership::factory()->create();
        $membership->revoke();
        $event = AuditEvent::firstOrFail();

        $this->expectException(\LogicException::class);
        $event->update(['action' => 'forjado']);
    }

    public function test_audit_trail_cannot_be_changed_even_bypassing_the_model(): void
    {
        Membership::factory()->create()->revoke();

        $this->expectException(QueryException::class);
        DB::table('audit_events')->update(['action' => 'forjado']);
    }

    public function test_revoking_twice_records_one_event_and_revoked_membership_gets_no_roles(): void
    {
        $membership = Membership::factory()->create();
        $membership->revoke();
        $membership->revoke();

        $this->assertSame(1, AuditEvent::count());

        $this->expectException(\LogicException::class);
        $membership->grant(Role::Auditor);
    }

    public function test_deactivation_is_visible_in_each_client_trail(): void
    {
        $first = Membership::factory()->create();
        $second = Membership::factory()->for($first->user)->create();

        $first->user->deactivate();

        $this->assertEqualsCanonicalizing(
            [$first->tenant_id, $second->tenant_id],
            AuditEvent::where('action', 'user.deactivated')->pluck('tenant_id')->all(),
        );
    }

    public function test_policies_deny_deactivated_accounts_outside_http(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $unit = Unit::factory()->for($admin->tenant)->create();
        app(CurrentTenant::class)->set($admin->tenant);

        $admin->user->deactivate();

        $this->assertFalse($admin->user->can('view', $unit));
    }

    public function test_member_cannot_delete_own_account(): void
    {
        $membership = Membership::factory()->withRole(Role::Professional)->create();

        $this->actingAs($membership->user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($membership->user->fresh());
    }
}
