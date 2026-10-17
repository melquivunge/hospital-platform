<?php

namespace Tests\Feature\Governance;

use App\Governance\EffectivePolicy;
use App\Governance\PolicyKey;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\PolicyVersion;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PolicyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'attendance_max_accuracy';

    private Membership $admin;

    private Unit $unit;

    private Membership $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->unit = Unit::factory()->for($this->admin->tenant)->create(['name' => 'Hospital Horizonte']);
        $this->manager = Membership::factory()->for($this->admin->tenant)->create();
        $this->manager->grant(Role::UnitManager, $this->unit);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function publishNetwork(array $data = []): TestResponse
    {
        return $this->actingAs($this->admin->user)->post(route('policies.publish', self::KEY), [
            'expected_version' => 0,
            'value' => 50,
            'delegable' => true,
            'min_value' => 20,
            'max_value' => 80,
            'reason' => 'Valor sintético de teste',
            ...$data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function publishLocal(array $data = [], ?Membership $as = null): TestResponse
    {
        return $this->actingAs(($as ?? $this->manager)->user)->post(route('units.policies.publish', [$this->unit, self::KEY]), [
            'expected_version' => 0,
            'value' => 30,
            'reason' => 'Ajuste local sintético',
            ...$data,
        ]);
    }

    private function effective(): ?EffectivePolicy
    {
        app(CurrentTenant::class)->set($this->admin->tenant);

        return PolicyVersion::effective(PolicyKey::AttendanceMaxAccuracy, $this->unit);
    }

    public function test_rule_without_network_version_has_no_effective_value_and_no_local_override(): void
    {
        $this->assertNull($this->effective());

        $this->publishLocal()->assertSessionHasErrors('value');
        $this->assertSame(0, PolicyVersion::withoutTenantScope()->count());
    }

    public function test_network_admin_publishes_version_and_unit_inherits_it(): void
    {
        $this->publishNetwork()->assertRedirect(route('policies.index'))->assertSessionHasNoErrors();

        $effective = $this->effective();
        $this->assertSame(50, $effective?->value);
        $this->assertSame('network', $effective->origin());
        $this->assertSame(1, $effective->network->version);
        $this->assertSame($this->admin->user_id, $effective->network->author_user_id);
        $this->assertTrue(AuditEvent::where('action', 'policy.published')->where('subject_id', $effective->network->id)->exists());
    }

    public function test_unit_manager_sets_local_value_inside_limits_and_can_return_to_inherited(): void
    {
        $this->publishNetwork();

        $this->publishLocal(['value' => 30])->assertSessionHasNoErrors();
        $this->assertSame(30, $this->effective()?->value);
        $this->assertSame('unit', $this->effective()?->origin());

        $this->publishLocal(['expected_version' => 1, 'value' => ''])->assertSessionHasNoErrors();
        $this->assertSame(50, $this->effective()?->value);
        $this->assertSame('network', $this->effective()?->origin());
    }

    public function test_local_value_outside_limits_or_not_delegated_is_rejected(): void
    {
        $this->publishNetwork();
        $this->publishLocal(['value' => 81])->assertSessionHasErrors('value');
        $this->publishLocal(['value' => 19])->assertSessionHasErrors('value');

        $this->publishNetwork(['expected_version' => 1, 'delegable' => false]);
        $this->publishLocal(['value' => 30])->assertSessionHasErrors('value');

        $this->assertSame(0, PolicyVersion::withoutTenantScope()->whereNotNull('unit_id')->count());
    }

    public function test_local_override_never_weakens_a_later_network_limit(): void
    {
        $this->publishNetwork();
        $this->publishLocal(['value' => 75]);

        // Narrowing the limits without confirmation lists the affected unit and publishes nothing.
        $this->publishNetwork(['expected_version' => 1, 'max_value' => 60])
            ->assertSessionHasErrors(['confirm_incompatible' => 'Estas unidades passarão a usar o valor da rede: Hospital Horizonte. Confirme para publicar.']);
        $this->assertSame(75, $this->effective()?->value);

        $this->publishNetwork(['expected_version' => 1, 'max_value' => 60, 'confirm_incompatible' => true])->assertSessionHasNoErrors();

        $effective = $this->effective();
        $this->assertSame(50, $effective?->value);
        $this->assertSame('network', $effective->origin());
        $this->assertSame([$this->unit->id], AuditEvent::where('action', 'policy.published')->latest('id')->first()?->context['incompatible_unit_ids']);

        // Widening the limits again does not bring the removed local value back.
        $this->publishNetwork(['expected_version' => 2, 'max_value' => 80])->assertSessionHasNoErrors();
        $this->assertSame(50, $this->effective()?->value);
        $this->assertSame('network', $this->effective()?->origin());
    }

    public function test_unit_can_return_to_inherited_value_even_when_rule_is_no_longer_delegated(): void
    {
        $this->publishNetwork();
        $this->publishLocal(['value' => 30]);
        $this->publishNetwork(['expected_version' => 1, 'delegable' => false, 'confirm_incompatible' => true])->assertSessionHasNoErrors();

        // The confirmed transition already cleared it (version 2); clearing again is still accepted.
        $this->publishLocal(['expected_version' => 2, 'value' => ''])->assertSessionHasNoErrors();
        $this->assertSame('network', $this->effective()?->origin());
    }

    public function test_publishing_over_a_stale_version_is_a_conflict(): void
    {
        $this->publishNetwork()->assertSessionHasNoErrors();

        $this->publishNetwork(['value' => 40])->assertSessionHasErrors('expected_version');
        $this->assertSame(50, $this->effective()?->value);
    }

    public function test_network_values_are_validated(): void
    {
        $this->publishNetwork(['value' => 0, 'reason' => ''])->assertSessionHasErrors(['value', 'reason']);
        $this->publishNetwork(['min_value' => 60])->assertSessionHasErrors('min_value');
        $this->publishNetwork(['max_value' => 40])->assertSessionHasErrors('max_value');
        $this->post(route('policies.publish', 'unknown_rule'), [])->assertNotFound();
    }

    public function test_only_authorized_roles_publish(): void
    {
        $this->publishNetwork();

        $auditor = Membership::factory()->for($this->admin->tenant)->withRole(Role::Auditor)->create();
        $otherManager = Membership::factory()->for($this->admin->tenant)->create();
        $otherManager->grant(Role::UnitManager, Unit::factory()->for($this->admin->tenant)->create());
        $professional = Membership::factory()->for($this->admin->tenant)->create();
        $professional->grant(Role::Professional, $this->unit);

        $this->actingAs($this->manager->user)
            ->post(route('policies.publish', self::KEY), ['expected_version' => 1, 'value' => 10, 'reason' => 'x'])
            ->assertForbidden();

        foreach ([$auditor, $otherManager, $professional] as $membership) {
            $this->publishLocal([], $membership)->assertForbidden();
        }

        $this->publishLocal([], $this->admin)->assertSessionHasNoErrors();
    }

    public function test_units_of_another_tenant_are_not_found(): void
    {
        $foreignUnit = Unit::factory()->create();

        $this->actingAs($this->admin->user)
            ->post(route('units.policies.publish', [$foreignUnit, self::KEY]), ['expected_version' => 0, 'value' => 30, 'reason' => 'x'])
            ->assertNotFound();
    }

    public function test_versions_are_append_only_in_the_database(): void
    {
        $this->publishNetwork();

        $this->expectException(QueryException::class);
        PolicyVersion::withoutTenantScope()->update(['value' => 1]);
    }

    public function test_catalog_page_shows_effective_values_to_allowed_roles(): void
    {
        $this->publishNetwork();
        $this->publishLocal(['value' => 30]);

        $this->actingAs($this->manager->user)->get(route('policies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('policies/index')
                ->where('canPublishNetwork', false)
                ->where('policies.0.network.value', 50)
                ->has('policies.0.units', 1)
                ->where('policies.0.units.0.value', 30)
                ->where('policies.0.units.0.origin', 'unit')
                ->where('policies.0.units.0.canPublish', true));

        $professional = Membership::factory()->for($this->admin->tenant)->create();
        $professional->grant(Role::Professional, $this->unit);
        $this->actingAs($professional->user)->get(route('policies.index'))->assertForbidden();
    }
}
