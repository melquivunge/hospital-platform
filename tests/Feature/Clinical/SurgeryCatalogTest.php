<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Material;
use App\Models\Membership;
use App\Models\OperatingRoom;
use App\Models\SurgicalProcedure;
use App\Models\Unit;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 5b.1a (delivery 5b, ADR-0009): the network surgical procedures and the operating rooms of each unit, kept
 * by the network admin, and the Surgical center role, one unit each.
 */
class SurgeryCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->unit = Unit::factory()->for($this->admin->tenant)->create();
    }

    public function test_the_admin_keeps_the_procedures(): void
    {
        $this->actingAs($this->admin->user)->post(route('surgical-procedures.store'), ['name' => ' Colecistectomia  videolaparoscópica ', 'code' => '31005497', 'duration_minutes' => 90, 'size' => 'medium'])
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Procedimento cadastrado.');
        $procedure = SurgicalProcedure::withoutTenantScope()->sole();
        $this->assertSame(['Colecistectomia videolaparoscópica', 'colecistectomia videolaparoscópica', 90, true], [$procedure->name, $procedure->name_key, $procedure->duration_minutes, $procedure->active]);
        $this->assertEquals(['size' => 'medium'], AuditEvent::where('action', 'surgical_procedure.created')->sole()->context);

        $this->post(route('surgical-procedures.store'), ['name' => 'COLECISTECTOMIA VIDEOLAPAROSCÓPICA', 'duration_minutes' => 60, 'size' => 'small'])
            ->assertSessionHasErrorsIn('newProcedure', ['name' => 'Já existe um procedimento com este nome.']);
        $this->post(route('surgical-procedures.store'), ['name' => 'Apendicectomia', 'duration_minutes' => 2, 'size' => 'huge', 'code' => 'a b'])
            ->assertSessionHasErrorsIn('newProcedure', ['duration_minutes', 'size', 'code']);

        // Deactivated, never deleted; only the changed fields are audited.
        $this->put(route('surgical-procedures.update', $procedure), ['name' => 'Colecistectomia videolaparoscópica', 'code' => '31005497', 'duration_minutes' => 120, 'size' => 'medium', 'active' => false])->assertSessionHasNoErrors();
        $this->assertEquals(['fields' => ['duration_minutes', 'active'], 'active' => false], AuditEvent::where('action', 'surgical_procedure.updated')->sole()->context);
        $this->put(route('surgical-procedures.update', $procedure), ['name' => 'Colecistectomia videolaparoscópica', 'code' => '31005497', 'duration_minutes' => 120, 'size' => 'medium', 'active' => false])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditEvent::where('action', 'surgical_procedure.updated')->count());

        // Renaming onto another procedure's name is refused.
        $this->post(route('surgical-procedures.store'), ['name' => 'Apendicectomia', 'duration_minutes' => 60, 'size' => 'small'])->assertSessionHasNoErrors();
        $this->put(route('surgical-procedures.update', $procedure), ['name' => 'apendicectomia', 'duration_minutes' => 120, 'size' => 'medium', 'active' => false])
            ->assertSessionHasErrorsIn("procedure-{$procedure->id}", ['name' => 'Já existe um procedimento com este nome.']);

        $this->get(route('surgery-catalog.index', ['q' => 'COLE']))->assertInertia(fn (Assert $page) => $page
            ->component('surgery-catalog/index')->where('total', 2)->has('procedures', 1)->where('procedures.0.active', false));
        $this->get(route('surgery-catalog.index', ['q' => 'hernia']))->assertInertia(fn (Assert $page) => $page->has('procedures', 0));
    }

    public function test_the_admin_keeps_the_materials_and_only_the_admin_of_its_network(): void
    {
        $stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $center = Membership::factory()->for($this->admin->tenant)->create();
        $center->grant(Role::SurgicalCenter, $this->unit);
        $this->actingAs($this->admin->user)->post(route('materials.store'), ['name' => ' Compressa  cirúrgica ', 'code' => 'CMP/45', 'stock_unit' => 'pacote'])
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Material cadastrado.');
        $material = Material::withoutTenantScope()->sole();
        $this->assertSame(['Compressa cirúrgica', 'compressa cirúrgica', 'pacote', true], [$material->name, $material->name_key, $material->stock_unit, $material->active]);
        $this->assertEquals(['stock_unit' => 'pacote'], AuditEvent::where('action', 'material.created')->sole()->context);
        $this->post(route('materials.store'), ['name' => 'COMPRESSA CIRÚRGICA', 'stock_unit' => 'unidade'])
            ->assertSessionHasErrorsIn('newMaterial', ['name' => 'Já existe um material com este nome.']);
        $this->post(route('materials.store'), ['name' => 'Luva', 'stock_unit' => 'tonel', 'code' => 'a b'])->assertSessionHasErrorsIn('newMaterial', ['stock_unit', 'code']);
        // Nothing a spreadsheet would run as a formula, as in the CSV import (the procedures too).
        $this->post(route('materials.store'), ['name' => '=HYPERLINK("x")', 'stock_unit' => 'unidade', 'code' => '-5'])->assertSessionHasErrorsIn('newMaterial', ['name', 'code']);
        $this->post(route('surgical-procedures.store'), ['name' => '@SUM(A1)', 'duration_minutes' => 60, 'size' => 'small', 'code' => '-1'])->assertSessionHasErrorsIn('newProcedure', ['name', 'code']);

        $this->put(route('materials.update', $material), ['name' => 'Compressa cirúrgica', 'code' => 'CMP/45', 'stock_unit' => 'unidade', 'active' => false])->assertSessionHasNoErrors();
        $this->assertEquals(['fields' => ['stock_unit', 'active'], 'active' => false], AuditEvent::where('action', 'material.updated')->sole()->context);
        $this->post(route('materials.store'), ['name' => 'Luva estéril', 'stock_unit' => 'par'])->assertSessionHasNoErrors();
        $this->get(route('surgery-catalog.index', ['m' => 'luva']))->assertInertia(fn (Assert $page) => $page
            ->where('materialTotal', 2)->has('materials', 1)->where('materials.0.stock_unit', 'par')->has('procedures', 0)->where('stockUnits', Material::STOCK_UNITS));

        $this->actingAs($center->user)->post(route('materials.store'), ['name' => 'Gaze', 'stock_unit' => 'pacote'])->assertForbidden();
        $this->put(route('materials.update', $material), ['name' => 'X', 'stock_unit' => 'unidade', 'active' => true])->assertForbidden();
        // Another network neither sees nor changes them.
        $this->actingAs($stranger->user)->get(route('surgery-catalog.index'))->assertInertia(fn (Assert $page) => $page->where('materialTotal', 0)->has('materials', 0));
        $this->put(route('materials.update', $material), ['name' => 'X', 'stock_unit' => 'unidade', 'active' => true])->assertNotFound();
        $this->assertSame('Compressa cirúrgica', $material->fresh()->name);

        // The database checks the unit of stock.
        $this->expectException(QueryException::class);
        DB::table('materials')->insert(['tenant_id' => $this->admin->tenant_id, 'name' => 'Gaze', 'name_key' => 'gaze', 'stock_unit' => 'tonel', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_admin_keeps_rooms_per_unit_and_a_room_never_changes_unit(): void
    {
        $other = Unit::factory()->for($this->admin->tenant)->create();
        $this->actingAs($this->admin->user)->post(route('operating-rooms.store'), ['unit_id' => $this->unit->id, 'name' => ' Sala  1 '])->assertSessionHasNoErrors();
        $this->post(route('operating-rooms.store'), ['unit_id' => $this->unit->id, 'name' => 'sala 1'])->assertSessionHasErrorsIn('newRoom', ['name' => 'Esta unidade já tem uma sala com este nome.']);
        // The same name in another unit is another room.
        $this->post(route('operating-rooms.store'), ['unit_id' => $other->id, 'name' => 'Sala 1'])->assertSessionHasNoErrors();
        $room = OperatingRoom::withoutTenantScope()->where('unit_id', $this->unit->id)->sole();
        $this->assertSame('Sala 1', $room->name);
        $this->assertEquals(['unit_id' => $this->unit->id], AuditEvent::where('action', 'operating_room.created')->oldest('id')->first()->context);
        $this->post(route('operating-rooms.store'), ['unit_id' => $this->unit->id, 'name' => 'Sala 2'])->assertSessionHasNoErrors();
        $this->put(route('operating-rooms.update', $room), ['name' => 'SALA 2', 'active' => true])->assertSessionHasErrorsIn("room-{$room->id}", ['name' => 'Esta unidade já tem uma sala com este nome.']);

        $this->put(route('operating-rooms.update', $room), ['name' => 'Sala 1', 'active' => false, 'unit_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame([$this->unit->id, false], [$room->fresh()->unit_id, $room->fresh()->active]);
        $this->assertEquals(['fields' => ['active'], 'active' => false], AuditEvent::where('action', 'operating_room.updated')->sole()->context);

        $this->get(route('surgery-catalog.index'))->assertInertia(fn (Assert $page) => $page->has('units', 2)->has('units.0.rooms')
            ->where('units', fn ($units) => collect(collect($units)->firstWhere('id', $this->unit->id)['rooms'])->pluck('active', 'id')->all() === [OperatingRoom::withoutTenantScope()->where('name', 'Sala 2')->value('id') => true, $room->id => false]));
    }

    public function test_only_the_network_admin_and_only_its_own_network(): void
    {
        $procedure = SurgicalProcedure::withoutTenantScope()->forceCreate(['tenant_id' => $this->admin->tenant_id, 'name' => 'Herniorrafia', 'duration_minutes' => 60, 'size' => 'small']);
        $room = OperatingRoom::withoutTenantScope()->forceCreate(['tenant_id' => $this->admin->tenant_id, 'unit_id' => $this->unit->id, 'name' => 'Sala 1']);

        $center = Membership::factory()->for($this->admin->tenant)->create();
        $center->grant(Role::SurgicalCenter, $this->unit);
        $stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $manager = Membership::factory()->for($this->admin->tenant)->create();
        $manager->grant(Role::UnitManager, $this->unit);
        $auditor = Membership::factory()->for($this->admin->tenant)->withRole(Role::Auditor)->create();
        foreach ([$manager, $auditor] as $other) {
            $this->actingAs($other->user)->get(route('surgery-catalog.index'))->assertForbidden();
        }
        $this->actingAs($center->user)->get(route('surgery-catalog.index'))->assertForbidden();
        $this->post(route('operating-rooms.store'), ['unit_id' => $this->unit->id, 'name' => 'Sala 2'])->assertForbidden();
        $this->put(route('surgical-procedures.update', $procedure), ['name' => 'X', 'duration_minutes' => 60, 'size' => 'small', 'active' => true])->assertForbidden();

        $this->actingAs($stranger->user)->put(route('surgical-procedures.update', $procedure), ['name' => 'X', 'duration_minutes' => 60, 'size' => 'small', 'active' => true])->assertNotFound();
        $this->put(route('operating-rooms.update', $room), ['name' => 'X', 'active' => true])->assertNotFound();
        // A unit of another network is not offered.
        $this->post(route('operating-rooms.store'), ['unit_id' => $this->unit->id, 'name' => 'Sala 9'])->assertSessionHasErrorsIn('newRoom', ['unit_id']);
        $this->assertSame(1, OperatingRoom::withoutTenantScope()->count());
    }

    public function test_the_surgical_center_role_needs_a_unit_and_the_database_checks_the_values(): void
    {
        $person = Membership::factory()->for($this->admin->tenant)->create();
        $this->actingAs($this->admin->user)->post(route('people.roles.store', $person), ['role' => 'surgical_center', 'unit_id' => $this->unit->id])->assertSessionHasNoErrors();
        $this->assertTrue($person->fresh()->hasRole(Role::SurgicalCenter, $this->unit->id));
        $this->post(route('people.roles.store', $person), ['role' => 'surgical_center'])->assertSessionHasErrors();

        // The scope CHECK: a surgical center without a unit, and a blank room name, never reach the table.
        foreach ([
            fn () => DB::table('role_assignments')->insert(['tenant_id' => $this->admin->tenant_id, 'membership_id' => $person->id, 'role' => 'surgical_center', 'unit_id' => null, 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('operating_rooms')->insert(['tenant_id' => $this->admin->tenant_id, 'unit_id' => $this->unit->id, 'name' => ' ', 'name_key' => ' ', 'created_at' => now(), 'updated_at' => now()]),
        ] as $insert) {
            try {
                DB::transaction($insert);
                $this->fail('The database accepted an invalid row.');
            } catch (QueryException) {
            }
        }

        $this->expectException(QueryException::class);
        DB::table('surgical_procedures')->insert(['tenant_id' => $this->admin->tenant_id, 'name' => 'X', 'name_key' => 'x', 'duration_minutes' => 3000, 'size' => 'small', 'created_at' => now(), 'updated_at' => now()]);
    }
}
