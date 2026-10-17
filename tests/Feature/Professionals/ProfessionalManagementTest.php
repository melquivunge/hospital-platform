<?php

namespace Tests\Feature\Professionals;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Unit;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfessionalManagementTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Unit $north;

    private Unit $south;

    private Membership $northManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->north = Unit::factory()->for($this->admin->tenant)->create(['name' => 'Hospital Norte']);
        $this->south = Unit::factory()->for($this->admin->tenant)->create(['name' => 'Hospital Sul']);
        $this->northManager = Membership::factory()->for($this->admin->tenant)->create();
        $this->northManager->grant(Role::UnitManager, $this->north);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data = []): array
    {
        return [
            'name' => 'Ana Sintética',
            'base_unit_id' => $this->north->id,
            'council' => 'crm',
            'council_number' => '12345',
            'council_uf' => 'SP',
            'external_id' => 'MAT-001',
            'email' => 'Ana@Exemplo.test',
            'phone' => '(11) 90000-0000',
            ...$data,
        ];
    }

    public function test_unit_manager_registers_professional_in_own_unit_and_it_is_audited(): void
    {
        $this->actingAs($this->northManager->user)
            ->post(route('professionals.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $professional = Professional::withoutTenantScope()->where('name', 'Ana Sintética')->firstOrFail();
        $this->assertSame($this->admin->tenant_id, $professional->tenant_id);
        $this->assertSame('CRM', $professional->council);
        $this->assertSame('ana@exemplo.test', $professional->email);
        $this->assertTrue(AuditEvent::where('action', 'professional.created')->where('subject_id', $professional->id)->exists());
    }

    public function test_unit_manager_cannot_use_another_unit_as_base(): void
    {
        $foreignUnit = Unit::factory()->create();

        $this->actingAs($this->northManager->user)
            ->post(route('professionals.store'), $this->payload(['base_unit_id' => $this->south->id]))
            ->assertSessionHasErrors('base_unit_id');

        $this->actingAs($this->admin->user)
            ->post(route('professionals.store'), $this->payload(['base_unit_id' => $foreignUnit->id]))
            ->assertSessionHasErrors('base_unit_id');

        $this->assertSame(0, Professional::withoutTenantScope()->count());
    }

    public function test_tenant_id_in_the_form_is_ignored(): void
    {
        $other = Membership::factory()->create();

        $this->actingAs($this->admin->user)
            ->post(route('professionals.store'), $this->payload(['tenant_id' => $other->tenant_id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->admin->tenant_id, Professional::withoutTenantScope()->value('tenant_id'));
    }

    public function test_duplicate_council_registration_and_external_id_are_rejected_only_inside_the_tenant(): void
    {
        Professional::factory()->create([...$this->payload(['council' => 'CRM', 'email' => null]), 'base_unit_id' => $this->north->id]);
        $otherAdmin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $otherUnit = Unit::factory()->for($otherAdmin->tenant)->create();

        $this->actingAs($this->admin->user)
            ->post(route('professionals.store'), $this->payload(['name' => 'Outra']))
            ->assertSessionHasErrors(['council_uf', 'external_id']);

        // Same identifiers in another tenant are fine.
        $this->actingAs($otherAdmin->user)
            ->post(route('professionals.store'), $this->payload(['base_unit_id' => $otherUnit->id]))
            ->assertSessionHasNoErrors();
    }

    public function test_council_number_is_case_insensitive_for_duplicates(): void
    {
        Professional::factory()->create(['base_unit_id' => $this->north->id, 'council' => 'CRM', 'council_number' => '12A', 'council_uf' => 'SP']);

        $this->actingAs($this->admin->user)
            ->post(route('professionals.store'), $this->payload(['council_number' => '12a', 'external_id' => null]))
            ->assertSessionHasErrors('council_uf');
    }

    public function test_council_fields_go_together(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('professionals.store'), $this->payload(['council_number' => '', 'council_uf' => 'XX', 'phone' => 'abc']))
            ->assertSessionHasErrors(['council_number', 'council_uf', 'phone']);
    }

    public function test_base_unit_change_requires_reason_and_is_recorded_in_history(): void
    {
        $professional = Professional::factory()->create(['base_unit_id' => $this->north->id]);

        $this->actingAs($this->admin->user)
            ->put(route('professionals.update', $professional), ['name' => $professional->name, 'base_unit_id' => $this->south->id])
            ->assertSessionHasErrors('base_unit_reason');
        $this->assertSame($this->north->id, $professional->fresh()?->base_unit_id);

        $this->put(route('professionals.update', $professional), ['name' => $professional->name, 'base_unit_id' => $this->south->id, 'base_unit_reason' => 'Transferência sintética'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->south->id, $professional->fresh()?->base_unit_id);
        $event = AuditEvent::where('action', 'professional.base_unit_changed')->firstOrFail();
        $this->assertEquals(['from' => $this->north->id, 'to' => $this->south->id, 'reason' => 'Transferência sintética'], $event->context);

        $this->get(route('professionals.show', $professional))
            ->assertInertia(fn (Assert $page) => $page
                ->where('history.0.from', 'Hospital Norte')
                ->where('history.0.to', 'Hospital Sul')
                ->where('history.0.reason', 'Transferência sintética'));
    }

    public function test_unit_manager_cannot_move_professional_to_a_unit_they_do_not_manage(): void
    {
        $professional = Professional::factory()->create(['base_unit_id' => $this->north->id]);

        $this->actingAs($this->northManager->user)
            ->put(route('professionals.update', $professional), ['name' => 'x', 'base_unit_id' => $this->south->id, 'base_unit_reason' => 'x'])
            ->assertSessionHasErrors('base_unit_id');

        $this->assertSame($this->north->id, $professional->fresh()?->base_unit_id);
    }

    public function test_visibility_and_write_access_follow_roles(): void
    {
        $inNorth = Professional::factory()->create(['base_unit_id' => $this->north->id, 'name' => 'Norte Sintético']);
        $inSouth = Professional::factory()->create(['base_unit_id' => $this->south->id, 'name' => 'Sul Sintético']);
        $auditor = Membership::factory()->for($this->admin->tenant)->withRole(Role::Auditor)->create();
        $professionalRole = Membership::factory()->for($this->admin->tenant)->create();
        $professionalRole->grant(Role::Professional, $this->north);

        $this->actingAs($this->northManager->user)->get(route('professionals.index'))
            ->assertInertia(fn (Assert $page) => $page->has('professionals', 1)->where('professionals.0.name', 'Norte Sintético'));
        $this->get(route('professionals.show', $inSouth))->assertForbidden();
        $this->put(route('professionals.update', $inSouth), ['name' => 'x', 'base_unit_id' => $this->south->id])->assertForbidden();

        $this->actingAs($auditor->user)->get(route('professionals.index'))->assertInertia(fn (Assert $page) => $page->has('professionals', 2)->where('canCreate', false));
        $this->get(route('professionals.show', $inNorth))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));
        $this->post(route('professionals.store'), $this->payload())->assertForbidden();
        $this->put(route('professionals.update', $inNorth), ['name' => 'x', 'base_unit_id' => $this->north->id])->assertForbidden();

        $this->actingAs($professionalRole->user)->get(route('professionals.index'))->assertForbidden();
        $this->get(route('professionals.show', $inNorth))->assertForbidden();
    }

    public function test_professionals_of_another_tenant_are_not_found(): void
    {
        $foreign = Professional::factory()->create();

        $this->actingAs($this->admin->user)->get(route('professionals.show', $foreign))->assertNotFound();
        $this->put(route('professionals.update', $foreign), ['name' => 'x', 'base_unit_id' => $this->north->id])->assertNotFound();
    }
}
