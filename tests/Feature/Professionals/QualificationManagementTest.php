<?php

namespace Tests\Feature\Professionals;

use App\Models\AuditEvent;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QualificationManagementTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Unit $base;

    private Unit $destination;

    private Sector $destinationIcu;

    private JobFunction $doctor;

    private Membership $baseManager;

    private Membership $destinationManager;

    private Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $this->admin->tenant;
        $this->base = Unit::factory()->for($tenant)->create(['name' => 'Hospital Base']);
        $this->destination = Unit::factory()->for($tenant)->create(['name' => 'Hospital Destino']);
        $this->destinationIcu = Sector::factory()->for($this->destination)->create();
        $this->doctor = JobFunction::factory()->for($tenant)->create();
        $this->baseManager = Membership::factory()->for($tenant)->create();
        $this->baseManager->grant(Role::UnitManager, $this->base);
        $this->destinationManager = Membership::factory()->for($tenant)->create();
        $this->destinationManager->grant(Role::UnitManager, $this->destination);
        $this->professional = Professional::factory()->create(['base_unit_id' => $this->base->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requestQualification(Membership $as, array $data = []): TestResponse
    {
        return $this->actingAs($as->user)->post(route('professionals.qualifications.store', $this->professional), [
            'unit_id' => $this->destination->id,
            'job_function_id' => $this->doctor->id,
            'sector_ids' => [$this->destinationIcu->id],
            'valid_from' => '2026-01-01',
            'valid_until' => '',
            ...$data,
        ]);
    }

    private function qualification(): Qualification
    {
        return Qualification::withoutTenantScope()->latest('id')->firstOrFail();
    }

    public function test_base_manager_requests_and_destination_manager_approves(): void
    {
        $this->requestQualification($this->baseManager)->assertSessionHasNoErrors()->assertRedirect();
        $qualification = $this->qualification();
        $this->assertSame(QualificationStatus::Pending, $qualification->status);

        // The base manager cannot approve at the destination.
        $this->actingAs($this->baseManager->user)
            ->post(route('qualifications.transition', $qualification), ['status' => 'approved'])
            ->assertForbidden();

        // The destination manager now sees the professional and decides.
        $this->actingAs($this->destinationManager->user)
            ->get(route('professionals.show', $this->professional))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('qualifications.0.canDecide', true)
                ->where('canUpdate', false)
                ->missing('professional.email')
                ->missing('professional.phone'));
        $this->post(route('qualifications.transition', $qualification), ['status' => 'approved'])->assertSessionHasNoErrors();

        $this->assertSame(QualificationStatus::Approved, $qualification->fresh()?->status);
        $this->assertSame($this->destinationManager->user_id, $qualification->fresh()?->decided_by_user_id);
        $this->assertSame(['qualification.requested', 'qualification.approved'], AuditEvent::where('action', 'like', 'qualification.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_destination_manager_only_sees_own_unit_and_loses_access_after_revocation(): void
    {
        $otherUnit = Unit::factory()->for($this->admin->tenant)->create();
        $otherSector = Sector::factory()->for($otherUnit)->create();
        $this->requestQualification($this->admin);
        $qualification = $this->qualification();
        $this->requestQualification($this->admin, ['unit_id' => $otherUnit->id, 'sector_ids' => [$otherSector->id]]);

        $this->actingAs($this->destinationManager->user)
            ->get(route('professionals.show', $this->professional))
            ->assertInertia(fn (Assert $page) => $page->has('qualifications', 1)->where('qualifications.0.unit', 'Hospital Destino'));

        $this->post(route('qualifications.transition', $qualification), ['status' => 'revoked', 'reason' => 'Motivo sintético']);
        $this->get(route('professionals.show', $this->professional))->assertForbidden();
        $this->get(route('professionals.index'))->assertInertia(fn (Assert $page) => $page->has('professionals', 0));
    }

    public function test_suspend_and_revoke_need_a_reason_and_revoked_is_final(): void
    {
        $this->requestQualification($this->admin);
        $qualification = $this->qualification();
        $this->post(route('qualifications.transition', $qualification), ['status' => 'approved']);

        $this->post(route('qualifications.transition', $qualification), ['status' => 'suspended'])
            ->assertSessionHasErrorsIn("qualification-{$qualification->id}", 'reason');

        $this->post(route('qualifications.transition', $qualification), ['status' => 'revoked', 'reason' => 'Motivo sintético'])->assertSessionHasNoErrors();
        $this->post(route('qualifications.transition', $qualification), ['status' => 'approved'])
            ->assertSessionHasErrors('status');

        $this->assertSame(QualificationStatus::Revoked, $qualification->fresh()?->status);
        $this->assertSame('Motivo sintético', AuditEvent::where('action', 'qualification.revoked')->value('context')['reason'] ?? null);
    }

    public function test_sectors_must_belong_to_the_chosen_unit_and_ids_of_other_tenants_are_rejected(): void
    {
        $baseSector = Sector::factory()->for($this->base)->create();
        $foreignFunction = JobFunction::factory()->create();

        $this->requestQualification($this->admin, ['sector_ids' => [$baseSector->id]])
            ->assertSessionHasErrorsIn('qualification', 'sector_ids');
        $this->requestQualification($this->admin, ['job_function_id' => $foreignFunction->id, 'sector_ids' => []])
            ->assertSessionHasErrorsIn('qualification', ['job_function_id', 'sector_ids']);
        $this->requestQualification($this->admin, ['sector_ids' => [$this->destinationIcu->id, $this->destinationIcu->id]])
            ->assertSessionHasErrorsIn('qualification', 'sector_ids.1');
        $this->requestQualification($this->admin, ['sector_ids' => ['1.9']])
            ->assertSessionHasErrorsIn('qualification', 'sector_ids.0');
        $this->requestQualification($this->admin, ['valid_from' => '2026-02-01', 'valid_until' => '2026-01-01'])
            ->assertSessionHasErrorsIn('qualification', 'valid_until');

        $this->assertSame(0, Qualification::withoutTenantScope()->count());
    }

    public function test_only_one_active_qualification_per_unit_and_function(): void
    {
        $this->requestQualification($this->admin)->assertSessionHasNoErrors();
        $this->requestQualification($this->admin)->assertSessionHasErrorsIn('qualification', 'job_function_id');
    }

    public function test_other_roles_cannot_request_or_decide(): void
    {
        $auditor = Membership::factory()->for($this->admin->tenant)->withRole(Role::Auditor)->create();
        $this->requestQualification($this->admin);
        $qualification = $this->qualification();

        foreach ([$auditor, $this->destinationManager] as $membership) {
            $this->requestQualification($membership)->assertForbidden();
        }

        $this->actingAs($auditor->user)
            ->post(route('qualifications.transition', $qualification), ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_qualification_of_another_tenant_is_not_found(): void
    {
        $otherAdmin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->requestQualification($this->admin);
        $qualification = $this->qualification();

        $this->actingAs($otherAdmin->user)
            ->post(route('qualifications.transition', $qualification), ['status' => 'approved'])
            ->assertNotFound();
    }

    public function test_network_admin_manages_job_functions_and_managers_cannot(): void
    {
        $this->actingAs($this->admin->user)
            ->post(route('job-functions.store'), ['name' => 'Enfermeiro'])
            ->assertSessionHasNoErrors();
        $this->post(route('job-functions.store'), ['name' => 'enfermeiro'])->assertSessionHasErrorsIn('newFunction', 'name');
        $this->assertTrue(AuditEvent::where('action', 'job_function.created')->exists());

        $this->actingAs($this->baseManager->user)->get(route('job-functions.index'))->assertForbidden();
        $this->post(route('job-functions.store'), ['name' => 'Outra'])->assertForbidden();
    }
}
