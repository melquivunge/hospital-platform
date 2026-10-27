<?php

namespace Tests\Feature\Beds;

use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\Membership;
use App\Models\Sector;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BedTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    private Unit $other;

    private Sector $ward;

    private Membership $manager;

    private Membership $bedManager;

    private Membership $housekeeping;

    private Membership $reception;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create();
        $this->other = Unit::factory()->for($tenant)->create();
        $this->ward = Sector::factory()->for($this->unit)->create(['name' => 'Clínica Médica']);
        foreach (['manager' => Role::UnitManager, 'bedManager' => Role::BedManagement, 'housekeeping' => Role::Housekeeping, 'reception' => Role::Reception] as $property => $role) {
            $this->{$property} = Membership::factory()->for($tenant)->create();
            $this->{$property}->grant($role, $this->unit);
        }
    }

    private function bed(string $code = '201-A'): Bed
    {
        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $this->ward->id, 'room' => '201', 'code' => $code, 'type' => 'ward', 'sex_restriction' => 'female', 'isolation' => false, 'extra' => false])->assertSessionHasNoErrors();

        return Bed::withoutTenantScope()->latest('id')->firstOrFail();
    }

    public function test_the_manager_sets_up_beds_and_every_role_sees_the_map(): void
    {
        $bed = $this->bed();
        $this->assertSame(['free', 'female'], [$bed->status, $bed->sex_restriction]);
        $this->post(route('beds.store', $this->unit), ['sector_id' => $this->ward->id, 'room' => '201', 'code' => '201-a', 'type' => 'ward'])->assertSessionHasErrors('code');
        $this->post(route('beds.store', $this->unit), ['sector_id' => Sector::factory()->for($this->other)->create()->id, 'room' => '1', 'code' => 'X', 'type' => 'ward'])->assertSessionHasErrors('sector_id');
        $this->assertTrue(AuditEvent::where('action', 'bed.created')->exists());

        foreach ([$this->manager, $this->bedManager, $this->housekeeping] as $member) {
            $this->actingAs($member->user)->get(route('beds.index'))->assertInertia(fn (Assert $page) => $page->component('beds/index')
                ->where('counts.free', 1)->where('sectors.0.beds.0.code', '201-A'));
        }
        $this->actingAs($this->bedManager->user)->post(route('beds.store', $this->unit), ['sector_id' => $this->ward->id, 'room' => '1', 'code' => 'Y', 'type' => 'ward'])->assertForbidden();
        $this->actingAs($this->reception->user)->get(route('beds.index'))->assertForbidden();
    }

    public function test_state_changes_follow_the_roles_and_keep_the_history(): void
    {
        $bed = $this->bed();

        $this->actingAs($this->housekeeping->user)->post(route('beds.block', $bed), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->bedManager->user)->post(route('beds.block', $bed), [])->assertSessionHasErrors('reason');
        $this->post(route('beds.block', $bed), ['reason' => 'Manutenção do oxigênio'])->assertSessionHasNoErrors();
        $this->assertSame(['blocked', 'Manutenção do oxigênio'], [$bed->fresh()->status, $bed->fresh()->block_reason]);
        // Not from blocked: the conditional update refuses with a message.
        $this->post(route('beds.send-to-cleaning', $bed))->assertSessionHasErrors('bed');
        $this->post(route('beds.unblock', $bed))->assertSessionHasNoErrors();
        $this->post(route('beds.send-to-cleaning', $bed))->assertSessionHasNoErrors();
        $this->actingAs($this->housekeeping->user)->post(route('beds.clean', $bed))->assertSessionHasNoErrors();
        $this->post(route('beds.clean', $bed))->assertSessionHasErrors('bed');

        $this->assertSame(
            [[null, 'free'], ['free', 'blocked'], ['blocked', 'free'], ['free', 'cleaning'], ['cleaning', 'free']],
            DB::table('bed_events')->orderBy('id')->get()->map(fn ($event) => [$event->from_status, $event->to_status])->all(),
        );
        app(CurrentTenant::class)->set($this->unit->tenant);
        try {
            DB::transaction(fn () => DB::table('bed_events')->update(['to_status' => 'occupied']));
            $this->fail('The bed history was changed.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }
    }

    public function test_other_units_and_clients_cannot_touch_the_beds(): void
    {
        $bed = $this->bed();
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::BedManagement, $this->other);
        $this->actingAs($elsewhere->user)->post(route('beds.block', $bed), ['reason' => 'x'])->assertForbidden();

        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->create();
        $stranger->grant(Role::BedManagement, Unit::factory()->for($stranger->tenant)->create());
        $this->actingAs($stranger->user)->post(route('beds.block', $bed), ['reason' => 'x'])->assertNotFound();

        // A manager of another unit does not set up beds here; the network admin configures but does not operate.
        app(CurrentTenant::class)->set(null);
        $otherManager = Membership::factory()->for($this->unit->tenant)->create();
        $otherManager->grant(Role::UnitManager, $this->other);
        $this->actingAs($otherManager->user)->post(route('beds.store', $this->unit), ['sector_id' => $this->ward->id, 'room' => '1', 'code' => 'Z', 'type' => 'ward'])->assertForbidden();
        $admin = Membership::query()->where('tenant_id', $this->unit->tenant_id)->whereHas('roleAssignments', fn ($query) => $query->where('role', Role::NetworkAdmin))->firstOrFail();
        // The network admin also manages the beds of every unit (user's decision, 27/10/2026).
        $this->actingAs($admin->user)->get(route('beds.index'))->assertInertia(fn (Assert $page) => $page->where('can.configure', true)->where('can.manage', true));

        // Deactivated only while free or blocked; it leaves the map.
        $this->actingAs($this->bedManager->user)->post(route('beds.send-to-cleaning', $bed));
        $this->actingAs($this->manager->user)->post(route('beds.deactivate', $bed))->assertSessionHasErrors('bed');
        $this->actingAs($this->housekeeping->user)->post(route('beds.clean', $bed));
        $this->actingAs($this->manager->user)->post(route('beds.deactivate', $bed))->assertSessionHasNoErrors();
        $this->get(route('beds.index'))->assertInertia(fn (Assert $page) => $page->where('counts.free', 0));
        $this->assertSame('Leito desativado', DB::table('bed_events')->latest('id')->value('reason'));

        // A revoked membership loses the map at once.
        $this->bedManager->revoke();
        $this->actingAs($this->bedManager->user)->get(route('beds.index'))->assertForbidden();
    }
}
