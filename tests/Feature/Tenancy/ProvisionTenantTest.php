<?php

namespace Tests\Feature\Tenancy;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisionTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_provisions_tenant_and_initial_network_admin(): void
    {
        $this->artisan('tenant:provision', [
            'name' => 'Rede Aurora',
            '--admin-email' => 'admin@aurora.example',
            '--admin-name' => 'Ana Admin',
        ])->assertSuccessful();

        $tenant = Tenant::where('slug', 'rede-aurora')->firstOrFail();
        $admin = User::where('email', 'admin@aurora.example')->firstOrFail();
        $membership = Membership::where('tenant_id', $tenant->id)->where('user_id', $admin->id)->firstOrFail();

        // Role assignments are tenant-scoped: read them inside the tenant context.
        app(CurrentTenant::class)->set($tenant);
        $this->assertTrue($membership->hasRole(Role::NetworkAdmin));
        $this->assertFalse(Hash::check('password', $admin->password));
        $this->assertSame('tenant.provisioned', AuditEvent::where('tenant_id', $tenant->id)->value('action'));
    }

    public function test_existing_identity_is_reused_without_changing_its_password(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);
        $hash = $user->password;

        $this->artisan('tenant:provision', ['name' => 'Hospital Horizonte', '--admin-email' => 'ana@example.com'])
            ->assertSuccessful();

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(1, Membership::where('user_id', $user->id)->count());
    }

    public function test_name_without_letters_or_digits_is_rejected(): void
    {
        $this->artisan('tenant:provision', ['name' => '***', '--admin-email' => 'a@example.com'])->assertFailed();

        $this->assertSame(0, Tenant::count());
    }

    public function test_duplicate_tenant_is_rejected_without_side_effects(): void
    {
        Tenant::factory()->create(['name' => 'Rede Aurora', 'slug' => 'rede-aurora']);

        $this->artisan('tenant:provision', ['name' => 'Rede Aurora', '--admin-email' => 'novo@aurora.example'])
            ->assertFailed();

        $this->assertNull(User::where('email', 'novo@aurora.example')->first());
    }
}
