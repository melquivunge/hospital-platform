<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 6b.6a1 (user's decisions, 28/10/2026): the patient's self-declared race/color and, when indigenous, the
 * ethnicity of the official table; the unit's data for the BPA header.
 */
class BpaRegistrationTest extends ClinicalTestCase
{
    public function test_the_reception_records_the_race_color_and_the_ethnicity_of_an_indigenous_patient(): void
    {
        $update = fn (array $data) => $this->actingAs($this->reception->user)->put(route('patients.update', $this->patient), [
            'registered_unit_id' => $this->unit->id, 'name' => 'Joana Prado', 'birth_date' => '1990-01-02', 'sex' => 'female', ...$data,
        ]);
        $this->get(route('patients.edit', $this->patient))->assertInertia(fn (Assert $page) => $page
            ->where('raceColors.05', 'Indígena')->where('ethnicities', fn ($list) => collect($list)->contains(['code' => '0005', 'name' => 'AMANAYE'])));

        $update(['race_color' => '99'])->assertSessionHasErrors('race_color');
        $update(['race_color' => '05'])->assertSessionHasErrors('ethnicity');
        $update(['race_color' => '05', 'ethnicity' => '9999'])->assertSessionHasErrors('ethnicity');
        $update(['race_color' => '05', 'ethnicity' => '0024'])->assertSessionHasNoErrors();
        $this->assertSame(['05', '0024'], [$this->patient->fresh()->race_color, $this->patient->fresh()->ethnicity]);
        $this->get(route('patients.show', $this->patient))->assertInertia(fn (Assert $page) => $page
            ->where('patient.raceColor', 'Indígena')->where('patient.ethnicity', 'ASHANINKA (KAMPA)'));

        // Another race/color drops the ethnicity (the database keeps it only for 05); the trail has field names only.
        $update(['race_color' => '03', 'ethnicity' => '0024'])->assertSessionHasNoErrors();
        $this->assertSame(['03', null], [$this->patient->fresh()->race_color, $this->patient->fresh()->ethnicity]);
        $this->assertStringNotContainsString('"03"', AuditEvent::query()->where('action', 'patient.updated')->pluck('context')->toJson());
        $this->assertTrue(AuditEvent::query()->where('action', 'patient.updated')->get()->contains(fn ($event) => in_array('race_color', $event->context['fields'], true)));
    }

    public function test_the_network_admin_records_the_unit_data_for_the_bpa_header(): void
    {
        $admin = Membership::factory()->for($this->unit->tenant)->withRole(Role::NetworkAdmin)->create();
        $save = fn (array $data) => $this->actingAs($admin->user)->put(route('units.update', $this->unit), [
            'name' => $this->unit->name, 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo', ...$data,
        ]);
        $save(['cnpj' => '12.345.678/0001'])->assertSessionHasErrors('cnpj');
        $save(['sus_manager_name' => 'Secretaria Municipal de Saúde'])->assertSessionHasErrors('sus_manager_level');
        $save(['sus_manager_name' => 'SMS', 'sus_manager_level' => 'X'])->assertSessionHasErrors('sus_manager_level');
        $save(['cnpj' => '12.345.678/0001-90', 'sus_acronym' => ' hca ', 'sus_manager_name' => 'Secretaria Municipal de Saúde', 'sus_manager_level' => 'M'])->assertSessionHasNoErrors();
        $unit = Unit::withoutGlobalScopes()->findOrFail($this->unit->id);
        $this->assertSame(['12345678000190', 'HCA', 'Secretaria Municipal de Saúde', 'M'], [$unit->cnpj, $unit->sus_acronym, $unit->sus_manager_name, $unit->sus_manager_level]);
        $this->get(route('units.edit', $this->unit))->assertInertia(fn (Assert $page) => $page->where('unit.cnpj', '12345678000190')->where('unit.sus_manager_level', 'M'));
        $this->assertSame(1, DB::table('units')->where('id', $this->unit->id)->whereNotNull('cnpj')->count());
    }

    public function test_a_new_registration_keeps_the_ethnicity_only_for_an_indigenous_patient_and_the_database_agrees(): void
    {
        $store = fn (string $name, array $data) => $this->actingAs($this->reception->user)->post(route('patients.store'), [
            'registered_unit_id' => $this->unit->id, 'name' => $name, 'birth_date' => '1975-03-04', 'sex' => 'male', ...$data,
        ])->assertSessionHasNoErrors();
        $store('Raoni Txucarramãe', ['race_color' => '05', 'ethnicity' => '0024']);
        $store('Pedro Alves', ['race_color' => '01', 'ethnicity' => '0024']);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertSame(['0024', null], [Patient::query()->where('name', 'Raoni Txucarramãe')->value('ethnicity'), Patient::query()->where('name', 'Pedro Alves')->value('ethnicity')]);
        try {
            DB::transaction(fn () => DB::table('patients')->where('name', 'Pedro Alves')->update(['ethnicity' => '0024']));
            $this->fail('The database accepted an ethnicity without the indigenous race/color.');
        } catch (QueryException) {
        }
    }

    /** Step 6b.6b1: the patient's address, the municipality from the IBGE table; the unit's AIH header data. */
    public function test_the_address_and_the_unit_aih_data_are_recorded(): void
    {
        $update = fn (array $data) => $this->actingAs($this->reception->user)->put(route('patients.update', $this->patient), [
            'registered_unit_id' => $this->unit->id, 'name' => 'Joana Prado', 'birth_date' => '1990-01-02', 'sex' => 'female', ...$data,
        ]);
        $this->getJson(route('municipalities.search', ['q' => 'sao paulo sp']))->assertOk()->assertJsonFragment(['code' => '3550308', 'name' => 'São Paulo/SP']);
        $this->getJson(route('municipalities.search', ['q' => 'campinas']))->assertJsonPath('0.name', 'Campinas/SP');
        $update(['address_municipality' => '9999999'])->assertSessionHasErrors('address_municipality');
        $update(['address_zip' => '0131'])->assertSessionHasErrors('address_zip');
        $update(['address_street' => 'Rua das Flores', 'address_number' => '120', 'address_district' => 'Centro', 'address_municipality' => '3550308', 'address_zip' => '01310-100'])->assertSessionHasNoErrors();
        $this->assertSame(['3550308', '01310100'], [$this->patient->fresh()->address_municipality, $this->patient->fresh()->address_zip]);
        $this->get(route('patients.show', $this->patient))->assertInertia(fn (Assert $page) => $page->where('patient.address', 'Rua das Flores 120, Centro, São Paulo/SP, 01310-100'));
        $this->get(route('patients.edit', $this->patient))->assertInertia(fn (Assert $page) => $page->where('patient.municipalityLabel', 'São Paulo/SP'));

        $admin = Membership::factory()->for($this->unit->tenant)->withRole(Role::NetworkAdmin)->create();
        $save = fn (array $data) => $this->actingAs($admin->user)->put(route('units.update', $this->unit), ['name' => $this->unit->name, 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo', ...$data]);
        // The clinical director needs the Cartão Nacional de Saúde.
        $save(['clinical_director_id' => $this->doctor->id])->assertSessionHasErrors('clinical_director_id');
        app(CurrentTenant::class)->set($this->unit->tenant);
        DB::table('professionals')->where('id', $this->doctor->id)->update(['cns' => '700123456780900']);
        app(CurrentTenant::class)->set(null);
        $save(['municipality' => '3550308', 'aih_issuer' => 'M355030001', 'clinical_director_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $unit = Unit::withoutGlobalScopes()->findOrFail($this->unit->id);
        $this->assertSame(['3550308', 'M355030001', $this->doctor->id], [$unit->municipality, $unit->aih_issuer, $unit->clinical_director_id]);
        $this->get(route('units.edit', $this->unit))->assertInertia(fn (Assert $page) => $page->where('municipalityLabel', 'São Paulo/SP')
            ->where('directors', fn ($list) => collect($list)->contains('id', $this->doctor->id)));
    }
}
