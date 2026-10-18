<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientMerge;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PatientMergeTest extends TestCase
{
    use RefreshDatabase;

    private Membership $reception;

    private Membership $manager;

    private Patient $a;

    private Patient $b;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $unit = Unit::factory()->for($admin->tenant)->create();
        $this->reception = Membership::factory()->for($admin->tenant)->create();
        $this->reception->grant(Role::Reception, $unit);
        $this->manager = Membership::factory()->for($admin->tenant)->create();
        $this->manager->grant(Role::UnitManager, $unit);

        $base = ['registered_unit_id' => $unit->id, 'birth_date' => '1980-05-10', 'sex' => 'female', 'confirm_new' => true];
        $this->actingAs($this->reception->user)->post(route('patients.store'), [...$base, 'name' => 'Maria das Dores Silva'])->assertSessionHasNoErrors();
        $this->post(route('patients.store'), [...$base, 'name' => 'Maria Silva', 'cpf' => '52998224725'])->assertSessionHasNoErrors();
        [$this->a, $this->b] = Patient::withoutTenantScope()->orderBy('id')->get()->all();
    }

    private function merge(int $primary, ?string $reason = 'Mesmo CPF confirmado'): TestResponse
    {
        return $this->actingAs($this->manager->user)->post(route('patient-merges.store'), ['a_id' => $this->a->id, 'b_id' => $this->b->id, 'primary_id' => $primary, 'reason' => $reason]);
    }

    public function test_a_manager_merges_a_suspected_pair_and_the_duplicate_leads_to_the_primary(): void
    {
        $this->actingAs($this->manager->user)->get(route('patient-merges.index'))
            ->assertInertia(fn (Assert $page) => $page->has('pairs', 1)->where('pairs.0.1.cpf', '•••.•••.•47-25')->missing('pairs.0.1.phone'));
        $this->actingAs($this->reception->user)->get(route('patient-merges.index'))->assertForbidden();

        $this->merge($this->a->id)->assertSessionHasNoErrors();
        $this->b->refresh();
        $this->assertSame('merged', $this->b->status);
        $this->assertSame($this->a->id, $this->b->merged_into_id);
        $this->assertSame(['merge_id' => PatientMerge::withoutTenantScope()->sole()->id, 'duplicate_patient_id' => $this->b->id], AuditEvent::where('action', 'patient.merged')->sole()->context);

        $this->actingAs($this->manager->user)->get(route('patient-merges.index'))->assertInertia(fn (Assert $page) => $page->has('pairs', 0)->has('merges', 1));
        $this->actingAs($this->reception->user);
        $this->post(route('patients.search'), ['q' => 'maria silva'])->assertInertia(fn (Assert $page) => $page->has('patients', 1)->where('patients.0.id', $this->a->id));
        // The duplicate's document still finds the person, through the primary registration.
        $this->post(route('patients.search'), ['q' => '529.982.247-25'])->assertInertia(fn (Assert $page) => $page->has('patients', 1)->where('patients.0.id', $this->a->id));
        $this->get(route('patients.show', $this->b))->assertRedirect(route('patients.show', $this->a));
        $this->get(route('patients.show', $this->a))->assertInertia(fn (Assert $page) => $page->has('unifiedFrom', 1)->where('unifiedFrom.0.id', $this->b->id));
        $this->post(route('patients.store'), ['registered_unit_id' => $this->a->registered_unit_id, 'name' => 'Outra', 'cpf' => '52998224725'])->assertSessionHasErrors('cpf');
    }

    public function test_undoing_restores_two_independent_registrations_that_do_not_come_back_to_the_queue(): void
    {
        $this->merge($this->a->id)->assertSessionHasNoErrors();
        $merge = PatientMerge::withoutTenantScope()->sole();

        $this->post(route('patient-merges.undo', $merge), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('patient-merges.undo', $merge), ['reason' => 'Pessoas diferentes, confirmado'])->assertSessionHasNoErrors();
        $this->assertNull($this->b->fresh()->merged_into_id);
        $this->assertSame('complete', $this->b->fresh()->status);
        $this->assertNotNull($merge->fresh()->undone_at);
        $this->assertTrue(AuditEvent::where('action', 'patient.merge_undone')->exists());
        $this->get(route('patient-merges.index'))->assertInertia(fn (Assert $page) => $page->has('pairs', 0)->has('merges', 0));
        $this->post(route('patient-merges.undo', $merge), ['reason' => 'De novo'])->assertSessionHasErrors('merge');
    }

    public function test_merges_are_refused_outside_the_pair_twice_or_across_clients(): void
    {
        $this->merge($this->a->id, '')->assertSessionHasErrors('reason');
        $this->actingAs($this->manager->user)->post(route('patient-merges.store'), ['a_id' => $this->a->id, 'b_id' => $this->b->id, 'primary_id' => 999, 'reason' => 'x'])
            ->assertSessionHasErrors('primary_id');
        $this->merge($this->a->id)->assertSessionHasNoErrors();
        $this->merge($this->b->id)->assertSessionHasErrors('merge');
        $merge = PatientMerge::withoutTenantScope()->sole();

        app(CurrentTenant::class)->set(null);
        $foreign = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->actingAs($foreign->user)->post(route('patient-merges.store'), ['a_id' => $this->a->id, 'b_id' => $this->b->id, 'primary_id' => $this->a->id, 'reason' => 'x'])
            ->assertNotFound();
        $this->post(route('patient-merges.undo', $merge), ['reason' => 'x'])->assertNotFound();
    }
}
