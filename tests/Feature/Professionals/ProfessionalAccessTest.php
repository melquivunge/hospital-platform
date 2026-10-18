<?php

namespace Tests\Feature\Professionals;

use App\Models\AuditEvent;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Sector;
use App\Models\Shift;
use App\Models\Unit;
use App\Models\User;
use App\Scheduling\ShiftStatus;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfessionalAccessTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Unit $unit;

    private Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->unit = Unit::factory()->for($this->admin->tenant)->create();
        $this->professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => 'Ana Sintética', 'email' => 'ana@exemplo.test']);
    }

    public function test_invite_creates_access_with_professional_role_and_links_it(): void
    {
        $this->actingAs($this->admin->user)->post(route('professionals.invite', $this->professional))->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'ana@exemplo.test')->firstOrFail();
        $membership = Membership::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($membership->id, $this->professional->fresh()?->membership_id);
        $this->assertTrue($membership->hasRole(Role::Professional, $this->unit->id));
        $this->assertTrue(AuditEvent::where('action', 'professional.invited')->exists());

        $this->post(route('professionals.invite', $this->professional))->assertSessionHasErrors('access');
    }

    public function test_existing_access_is_reused_and_keeps_its_roles(): void
    {
        $coordinator = Membership::factory()->for($this->admin->tenant)->create();
        $coordinator->grant(Role::ShiftCoordinator, $this->unit);
        $other = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'email' => $coordinator->user->email]);
        $this->professional->update(['email' => $coordinator->user->email]);

        $this->actingAs($this->admin->user)->post(route('professionals.invite', $this->professional))->assertSessionHasNoErrors();

        $coordinator->unsetRelation('roleAssignments');
        $this->assertTrue($coordinator->hasRole(Role::ShiftCoordinator, $this->unit->id));
        $this->assertTrue($coordinator->hasRole(Role::Professional, $this->unit->id));
        $this->assertSame($coordinator->id, $this->professional->fresh()?->membership_id);

        // The same account cannot be linked to a second professional.
        $this->post(route('professionals.invite', $other))->assertSessionHasErrors('access');
    }

    public function test_unit_manager_cannot_attach_an_outside_account_or_reactivate_a_revoked_access(): void
    {
        $manager = Membership::factory()->for($this->admin->tenant)->create();
        $manager->grant(Role::UnitManager, $this->unit);
        $outsider = Membership::factory()->create();
        $revoked = Membership::factory()->for($this->admin->tenant)->create();
        $revoked->revoke();

        foreach ([$outsider->user->email, $revoked->user->email] as $email) {
            $this->professional->update(['email' => $email]);
            $this->actingAs($manager->user)->post(route('professionals.invite', $this->professional))->assertSessionHasErrors('access');
        }

        $this->assertNull($this->professional->fresh()?->membership_id);
        $this->assertNotNull($revoked->fresh()?->revoked_at);
    }

    public function test_invite_needs_email_and_permission(): void
    {
        $auditor = Membership::factory()->for($this->admin->tenant)->withRole(Role::Auditor)->create();
        $this->professional->update(['email' => null]);
        $this->actingAs($this->admin->user)->post(route('professionals.invite', $this->professional))->assertSessionHasErrors('access');

        $this->actingAs($auditor->user)->post(route('professionals.invite', $this->professional))->assertForbidden();
    }

    public function test_my_shifts_lists_only_own_published_shifts(): void
    {
        $sector = Sector::factory()->for($this->unit)->create(['name' => 'UTI']);
        $function = JobFunction::factory()->for($this->admin->tenant)->create();
        $other = Professional::factory()->create(['base_unit_id' => $this->unit->id]);
        $this->actingAs($this->admin->user)->post(route('professionals.invite', $this->professional));
        $base = ['tenant_id' => $this->admin->tenant_id, 'unit_id' => $this->unit->id, 'sector_id' => $sector->id, 'job_function_id' => $function->id, 'created_by_user_id' => $this->admin->user_id];

        Shift::withoutTenantScope()->insert([
            [...$base, 'professional_id' => $this->professional->id, 'status' => ShiftStatus::Published->value, 'starts_at' => '2026-11-10 10:00:00+00', 'ends_at' => '2026-11-10 22:00:00+00', 'published_at' => now(), 'published_by_user_id' => $this->admin->user_id],
            [...$base, 'professional_id' => $this->professional->id, 'status' => ShiftStatus::Draft->value, 'starts_at' => '2026-11-11 10:00:00+00', 'ends_at' => '2026-11-11 22:00:00+00', 'published_at' => null, 'published_by_user_id' => null],
            [...$base, 'professional_id' => $other->id, 'status' => ShiftStatus::Published->value, 'starts_at' => '2026-11-12 10:00:00+00', 'ends_at' => '2026-11-12 22:00:00+00', 'published_at' => now(), 'published_by_user_id' => $this->admin->user_id],
        ]);

        $user = User::query()->where('email', 'ana@exemplo.test')->firstOrFail();
        // Defining the password through the invitation link confirms the e-mail.
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user)->get(route('shifts.mine'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('shifts', 1)->where('shifts.0.date', '2026-11-10')->where('professional', 'Ana Sintética'));

        $this->actingAs($this->admin->user)->get(route('shifts.mine'))->assertForbidden();
    }
}
