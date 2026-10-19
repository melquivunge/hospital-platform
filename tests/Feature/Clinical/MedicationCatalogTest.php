<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\MedicationInteraction;
use App\Models\Membership;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 4b.1a: the network's medication catalog, maximum doses and interactions between active ingredients.
 */
class MedicationCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const DIPYRONE = [
        'active_ingredient' => '  Dipirona   Sódica ', 'form' => 'Solução injetável', 'strength' => '500 mg/mL', 'dose_unit' => 'mg',
        'routes' => ['iv', 'im'], 'controlled' => false, 'high_alert' => false, 'max_single_dose' => 1000, 'max_daily_dose' => 4000,
    ];

    private Membership $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
    }

    private function medication(array $overrides = []): Medication
    {
        $this->actingAs($this->admin->user)->post(route('medications.store'), [...self::DIPYRONE, ...$overrides])->assertSessionHasNoErrors();

        return Medication::withoutTenantScope()->latest('id')->firstOrFail();
    }

    public function test_the_network_admin_keeps_presentations_with_their_safety_rules(): void
    {
        $medication = $this->medication();
        $this->assertSame(['Dipirona Sódica', 'dipirona sódica', ['iv', 'im']], [$medication->active_ingredient, $medication->ingredient_key, $medication->routes]);
        $this->assertEquals(['controlled' => false, 'high_alert' => false, 'max_single_dose' => '1000.000', 'max_daily_dose' => '4000.000', 'active' => true], AuditEvent::where('action', 'medication.created')->sole()->context);

        // The same presentation, whatever the case and spacing, is refused; a new strength is not.
        $this->post(route('medications.store'), [...self::DIPYRONE, 'active_ingredient' => 'dipirona sódica', 'form' => 'SOLUÇÃO INJETÁVEL'])->assertSessionHasErrors('active_ingredient', null, 'newMedication');
        $this->post(route('medications.store'), [...self::DIPYRONE, 'strength' => '1 g/2 mL'])->assertSessionHasNoErrors();

        // Rule checks: daily below single, unknown unit or route, no route at all.
        foreach ([['max_daily_dose' => 500], ['dose_unit' => 'colher'], ['routes' => ['boca']], ['routes' => []]] as $bad) {
            $this->post(route('medications.store'), [...self::DIPYRONE, 'strength' => 'x', ...$bad])->assertSessionHasErrors(null, null, 'newMedication');
        }
        $this->post(route('medications.store'), [...self::DIPYRONE, 'strength' => '50 mg', 'max_single_dose' => null, 'max_daily_dose' => 200])->assertSessionHasNoErrors();

        // Deactivated, never deleted; the audit keeps which rule changed and the new values.
        $this->put(route('medications.update', $medication), [...self::DIPYRONE, 'max_daily_dose' => 3000, 'high_alert' => true, 'active' => false])->assertSessionHasNoErrors();
        $event = AuditEvent::where('action', 'medication.updated')->sole()->context;
        $this->assertEqualsCanonicalizing(['high_alert', 'max_daily_dose', 'active'], $event['fields']);
        $this->assertSame(['3000.000', true, false], [$event['max_daily_dose'], $event['high_alert'], $event['active']]);
        $this->get(route('medications.index', ['q' => 'DIPIRONA']))->assertInertia(fn (Assert $page) => $page->component('medications/index')->has('medications', 3)->where('ingredients', ['dipirona sódica']));
        $this->get(route('medications.index', ['q' => 'morfina']))->assertInertia(fn (Assert $page) => $page->has('medications', 0)->where('total', 3));

        // The ingredient is fixed (interactions refer to it); doses beyond 3 decimals and odd searches are refused cleanly.
        $this->put(route('medications.update', $medication), [...self::DIPYRONE, 'active_ingredient' => 'Metamizol', 'active' => true])->assertSessionHasErrors('active_ingredient', null, "medication-{$medication->id}");
        $this->post(route('medications.store'), [...self::DIPYRONE, 'strength' => '1 mg', 'max_single_dose' => 0.0004])->assertSessionHasErrors('max_single_dose', null, 'newMedication');
        $this->get(route('medications.index').'?q[]=x')->assertOk();

        // The database refuses a daily maximum below the single one.
        $this->expectException(QueryException::class);
        DB::table('medications')->where('id', $medication->id)->update(['max_daily_dose' => 1]);
    }

    public function test_interactions_are_one_rule_per_pair_of_catalog_ingredients(): void
    {
        $this->medication();
        $this->medication(['active_ingredient' => 'Ácido Acetilsalicílico', 'form' => 'Comprimido', 'strength' => '100 mg', 'routes' => ['oral']]);
        $pair = ['ingredient_a' => 'Dipirona sódica', 'ingredient_b' => 'ácido acetilsalicílico', 'severity' => 'moderate', 'note' => 'Reduz o efeito antiagregante; espaçar as doses.'];

        $this->post(route('medication-interactions.store'), [...$pair, 'ingredient_b' => 'Varfarina'])->assertSessionHasErrors('ingredient_b', null, 'newInteraction');
        $this->post(route('medication-interactions.store'), [...$pair, 'ingredient_b' => 'DIPIRONA SÓDICA'])->assertSessionHasErrors('ingredient_b', null, 'newInteraction');
        $this->post(route('medication-interactions.store'), $pair)->assertSessionHasNoErrors();
        // The reversed pair is the same rule.
        $this->post(route('medication-interactions.store'), [...$pair, 'ingredient_a' => $pair['ingredient_b'], 'ingredient_b' => $pair['ingredient_a']])->assertSessionHasErrors('ingredient_b', null, 'newInteraction');
        $interaction = MedicationInteraction::withoutTenantScope()->sole();
        $this->assertSame(['dipirona sódica', 'ácido acetilsalicílico'], [$interaction->ingredient_a, $interaction->ingredient_b]);

        $this->put(route('medication-interactions.update', $interaction), ['severity' => 'severe', 'note' => $pair['note'], 'active' => false])->assertSessionHasNoErrors();
        $this->assertSame(['severe', false], [$interaction->fresh()->severity, $interaction->fresh()->active]);
        $this->assertEqualsCanonicalizing(['severity', 'active'], AuditEvent::where('action', 'medication_interaction.updated')->sole()->context['fields']);

        // Another client's admin cannot reach it.
        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->actingAs($stranger->user)->put(route('medication-interactions.update', $interaction), ['severity' => 'mild', 'note' => 'x', 'active' => true])->assertNotFound();

        // The database keeps one order per pair.
        $this->expectException(QueryException::class);
        DB::table('medication_interactions')->insert(['tenant_id' => $interaction->tenant_id, 'ingredient_a' => 'ácido acetilsalicílico', 'ingredient_b' => 'dipirona sódica', 'severity' => 'mild', 'note' => 'x', 'created_by_user_id' => $this->admin->user_id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_only_the_network_admin_of_the_client_keeps_the_catalog(): void
    {
        $medication = $this->medication();
        app(CurrentTenant::class)->set(null);
        $manager = Membership::factory()->for($this->admin->tenant)->create();
        $manager->grant(Role::UnitManager, Unit::factory()->for($this->admin->tenant)->create());
        $this->actingAs($manager->user)->get(route('medications.index'))->assertForbidden();
        $this->post(route('medications.store'), self::DIPYRONE)->assertForbidden();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('canManageMedications', false));

        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->actingAs($stranger->user)->put(route('medications.update', $medication), [...self::DIPYRONE, 'active' => false])->assertNotFound();
        $this->get(route('medications.index'))->assertInertia(fn (Assert $page) => $page->where('total', 0));
    }
}
