<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PatientTest extends TestCase
{
    use RefreshDatabase;

    private const CPF = '52998224725';

    private const CNS = '123456789010000';

    private Membership $reception;

    private Membership $professional;

    private Membership $manager;

    private Unit $unit;

    private Unit $other;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->unit = Unit::factory()->for($admin->tenant)->create(['name' => 'Hospital A']);
        $this->other = Unit::factory()->for($admin->tenant)->create(['name' => 'Hospital B']);
        $this->reception = Membership::factory()->for($admin->tenant)->create();
        $this->reception->grant(Role::Reception, $this->unit);
        $this->professional = Membership::factory()->for($admin->tenant)->create();
        $this->professional->grant(Role::Professional, $this->other);
        $this->manager = Membership::factory()->for($admin->tenant)->create();
        $this->manager->grant(Role::UnitManager, $this->unit);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data = []): array
    {
        return ['registered_unit_id' => $this->unit->id, 'name' => 'Maria das Dores Silva', 'birth_date' => '1980-05-10', 'sex' => 'female', ...$data];
    }

    public function test_someone_without_documents_gets_a_provisional_registration_completed_later(): void
    {
        $this->actingAs($this->reception->user)->post(route('patients.store'), $this->payload(['birth_date' => '', 'sex' => '']))->assertSessionHasNoErrors();
        $patient = Patient::withoutTenantScope()->sole();
        $this->assertSame('provisional', $patient->status);
        $this->assertSame('maria das dores silva', $patient->name_key);

        $this->put(route('patients.update', $patient), $this->payload(['cpf' => '529.982.247-25']))->assertSessionHasNoErrors();
        $patient->refresh();
        $this->assertSame('complete', $patient->status);
        $this->assertSame(self::CPF, $patient->cpf);
        // The trail names the changed fields, never the personal data.
        $updated = AuditEvent::where('action', 'patient.updated')->sole();
        $this->assertSame(['birth_date', 'sex', 'cpf'], $updated->context['fields']);
        $this->assertStringNotContainsString(self::CPF, (string) json_encode($updated->context));
        $this->assertTrue(AuditEvent::where('action', 'patient.created')->exists());
    }

    public function test_documents_are_validated_and_unique_in_the_network(): void
    {
        $this->actingAs($this->reception->user);
        $this->post(route('patients.store'), $this->payload(['cpf' => '529.982.247-24']))->assertSessionHasErrors(['cpf' => 'CPF inválido.']);
        $this->post(route('patients.store'), $this->payload(['cns' => '123456789010001']))->assertSessionHasErrors(['cns' => 'Cartão Nacional de Saúde inválido.']);
        $this->post(route('patients.store'), $this->payload(['cpf' => self::CPF, 'cns' => self::CNS]))->assertSessionHasNoErrors();

        // Another unit of the same network cannot register the same documents again.
        $this->actingAs($this->professional->user)->post(route('patients.store'), $this->payload(['registered_unit_id' => $this->other->id, 'name' => 'Outra Pessoa', 'cpf' => self::CPF]))
            ->assertSessionHasErrors('cpf');
        $this->post(route('patients.store'), $this->payload(['registered_unit_id' => $this->other->id, 'name' => 'Outra Pessoa', 'cns' => self::CNS]))
            ->assertSessionHasErrors('cns');
        $this->post(route('patients.store'), $this->payload(['name' => 'Outra Pessoa']))->assertSessionHasErrors('registered_unit_id');
        $this->assertSame(1, Patient::withoutTenantScope()->count());
    }

    public function test_a_possible_duplicate_is_shown_and_only_registered_after_confirmation(): void
    {
        $this->actingAs($this->reception->user)->post(route('patients.store'), $this->payload())->assertSessionHasNoErrors();
        $first = Patient::withoutTenantScope()->sole();

        $this->post(route('patients.store'), $this->payload(['name' => 'Maria Silva']))->assertSessionHasErrors('confirm_new');
        $this->get(route('patients.create'))->assertInertia(fn (Assert $page) => $page->has('duplicates', 1)->where('duplicates.0.id', $first->id));
        $this->assertSame(1, Patient::withoutTenantScope()->count());

        $this->post(route('patients.store'), $this->payload(['name' => 'Maria Silva', 'confirm_new' => true]))->assertSessionHasNoErrors();
        $this->assertSame(2, Patient::withoutTenantScope()->count());
        $this->assertSame([$first->id], AuditEvent::where('action', 'patient.created')->latest('id')->firstOrFail()->context['confirmed_not_duplicate_of']);
    }

    public function test_search_finds_by_name_document_or_birth_date_and_shows_only_masked_documents(): void
    {
        $this->actingAs($this->reception->user)->post(route('patients.store'), $this->payload(['name' => 'José da Silva Ávila', 'cpf' => self::CPF, 'cns' => self::CNS]));
        $this->post(route('patients.store'), $this->payload(['name' => 'Ana Souza', 'birth_date' => '1990-01-01']));

        foreach (['jose avila', 'Ávila', '529.982.247-25', '123 4567 8901 0000', '10/05/1980'] as $term) {
            $this->post(route('patients.search'), ['q' => $term])->assertInertia(fn (Assert $page) => $page
                ->has('patients', 1)->where('patients.0.name', 'José da Silva Ávila')
                ->where('patients.0.cpf', '•••.•••.•47-25')->where('patients.0.cns', '••• •••• •••• 0000'));
        }
        // Never the whole registry: symbols, short words or impossible dates find nobody.
        foreach (['an', '%%%', '---', 'a b', '31/02/1980', '123'] as $term) {
            $this->post(route('patients.search'), ['q' => $term])->assertInertia(fn (Assert $page) => $page->has('patients', 0));
        }
        $this->post(route('patients.search'), ['q' => ['x']])->assertInertia(fn (Assert $page) => $page->has('patients', 0));
        $this->get(route('patients.index'))->assertInertia(fn (Assert $page) => $page->has('patients', 0)->where('searched', false));
        $this->post(route('patients.store'), $this->payload(['cpf' => ['x']]))->assertSessionHasErrors('cpf');
    }

    public function test_revoked_access_loses_the_registry(): void
    {
        $this->actingAs($this->reception->user)->post(route('patients.store'), $this->payload());
        $patient = Patient::withoutTenantScope()->sole();
        $this->reception->revoke();

        $this->post(route('patients.search'), ['q' => 'maria'])->assertForbidden();
        $this->get(route('patients.show', $patient))->assertForbidden();
    }

    public function test_professionals_anywhere_in_the_network_find_patients_and_managers_or_other_clients_do_not(): void
    {
        $this->actingAs($this->reception->user)->post(route('patients.store'), $this->payload());
        $patient = Patient::withoutTenantScope()->sole();

        $this->actingAs($this->professional->user)->post(route('patients.search'), ['q' => 'maria'])->assertInertia(fn (Assert $page) => $page->has('patients', 1));
        $this->get(route('patients.show', $patient))->assertOk();

        $this->actingAs($this->manager->user)->get(route('patients.index'))->assertForbidden();
        $this->get(route('patients.show', $patient))->assertForbidden();

        app(CurrentTenant::class)->set(null);
        $foreign = Membership::factory()->create();
        $foreign->grant(Role::Reception, Unit::factory()->for($foreign->tenant)->create());
        $this->actingAs($foreign->user)->post(route('patients.search'), ['q' => 'maria'])->assertInertia(fn (Assert $page) => $page->has('patients', 0));
        $this->get(route('patients.show', $patient))->assertNotFound();

        $this->put(route('patients.update', $patient), $this->payload())->assertNotFound();

        // And the database agrees: under the other client, the row does not exist (ADR-0003).
        $this->assertSame(0, DB::table('patients')->count());
    }
}
