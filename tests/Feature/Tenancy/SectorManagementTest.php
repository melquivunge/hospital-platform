<?php

namespace Tests\Feature\Tenancy;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Sector;
use App\Models\Unit;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectorManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_manager_creates_and_renames_sectors_of_assigned_unit(): void
    {
        $manager = Membership::factory()->create();
        $unit = Unit::factory()->for($manager->tenant)->create();
        $manager->grant(Role::UnitManager, $unit);

        $this->actingAs($manager->user)
            ->post(route('units.sectors.store', $unit), ['name' => 'UTI Adulto'])
            ->assertRedirect(route('units.show', $unit));

        $sector = Sector::withoutTenantScope()->where('name', 'UTI Adulto')->firstOrFail();
        $this->assertSame($unit->id, $sector->unit_id);

        $this->put(route('units.sectors.update', [$unit, $sector]), ['name' => 'UTI Adulto 2'])
            ->assertRedirect(route('units.show', $unit));

        $this->assertSame('UTI Adulto 2', $sector->fresh()?->name);
        $this->assertSame(['sector.created', 'sector.updated'], AuditEvent::where('action', 'like', 'sector.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_saving_unchanged_sector_records_no_audit_event(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $sector = Sector::factory()->for(Unit::factory()->for($admin->tenant))->create(['name' => 'UTI']);

        $this->actingAs($admin->user)
            ->put(route('units.sectors.update', [$sector->unit_id, $sector]), ['name' => 'UTI'])
            ->assertRedirect();

        $this->assertFalse(AuditEvent::where('action', 'sector.updated')->exists());
    }

    public function test_unit_manager_cannot_touch_sectors_of_other_units(): void
    {
        $manager = Membership::factory()->create();
        $assigned = Unit::factory()->for($manager->tenant)->create();
        $other = Unit::factory()->for($manager->tenant)->create();
        $manager->grant(Role::UnitManager, $assigned);

        $this->actingAs($manager->user)
            ->post(route('units.sectors.store', $other), ['name' => 'Pediatria'])
            ->assertForbidden();
    }

    public function test_manager_of_another_unit_and_auditor_cannot_change_sectors(): void
    {
        $tenantAdmin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $unit = Unit::factory()->for($tenantAdmin->tenant)->create();
        $sector = Sector::factory()->for($unit)->create();

        $otherManager = Membership::factory()->for($tenantAdmin->tenant)->create();
        $otherManager->grant(Role::UnitManager, Unit::factory()->for($tenantAdmin->tenant)->create());
        $auditor = Membership::factory()->for($tenantAdmin->tenant)->withRole(Role::Auditor)->create();

        foreach ([$otherManager->user, $auditor->user] as $user) {
            $this->actingAs($user)
                ->put(route('units.sectors.update', [$unit, $sector]), ['name' => 'Trocado'])
                ->assertForbidden();
            $this->post(route('units.sectors.store', $unit), ['name' => 'Novo'])->assertForbidden();
        }
    }

    public function test_array_name_is_a_validation_error_not_a_crash(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $unit = Unit::factory()->for($admin->tenant)->create();

        $this->actingAs($admin->user)
            ->post(route('units.sectors.store', $unit), ['name' => ['x']])
            ->assertSessionHasErrors('name');
        $this->post(route('units.store'), ['name' => ['x']])->assertSessionHasErrors('name');
    }

    public function test_sector_must_belong_to_the_unit_in_the_url(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $unit = Unit::factory()->for($admin->tenant)->create();
        $otherUnitSector = Sector::factory()->for(Unit::factory()->for($admin->tenant))->create();

        $this->actingAs($admin->user)
            ->put(route('units.sectors.update', [$unit, $otherUnitSector]), ['name' => 'Trocado'])
            ->assertNotFound();
    }

    public function test_duplicate_sector_name_in_same_unit_is_rejected_case_insensitively(): void
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $unit = Unit::factory()->for($admin->tenant)->create();
        Sector::factory()->for($unit)->create(['name' => 'Pronto-socorro']);

        $this->actingAs($admin->user)
            ->post(route('units.sectors.store', $unit), ['name' => 'PRONTO-SOCORRO'])
            ->assertSessionHasErrors('name');
    }
}
