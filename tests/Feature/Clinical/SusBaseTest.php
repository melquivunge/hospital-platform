<?php

namespace Tests\Feature\Clinical;

use App\Clinical\Cid10;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 6b.1 (ADR-0011): the professional's Cartão Nacional de Saúde (validated, unique in the network, seen only in
 * the full record, like the CPF) and the official CID-10 table with its search.
 */
class SusBaseTest extends ClinicalTestCase
{
    public function test_the_professional_cns_is_validated_unique_and_kept_out_of_the_short_record(): void
    {
        $update = fn (Professional $professional, string $cns) => $this->actingAs($this->manager->user)->put(route('professionals.update', $professional), [
            'name' => $professional->name, 'base_unit_id' => $professional->base_unit_id, 'cns' => $cns,
        ]);
        $update($this->doctor, '123456789010001')->assertSessionHasErrors(['cns' => 'Cartão Nacional de Saúde inválido.']);
        $update($this->doctor, '1234 5678 9010 000')->assertSessionHasNoErrors();
        $this->assertSame('123456789010000', $this->doctor->refresh()->cns);
        // A provisional card (7, 8 or 9) too; the same card twice in the network is refused.
        $update($this->nurse, '700123456780900')->assertSessionHasNoErrors();
        $update($this->nurse, '123456789010000')->assertSessionHasErrors(['cns' => 'Já existe um profissional com este Cartão Nacional de Saúde.']);
        // Cleared with an empty field.
        $update($this->nurse, '')->assertSessionHasNoErrors();
        $this->assertNull($this->nurse->refresh()->cns);

        $this->get(route('professionals.show', $this->doctor))->assertInertia(fn (Assert $page) => $page->where('professional.cns', '123456789010000'));
    }

    public function test_the_cid10_table_finds_codes_and_names(): void
    {
        $this->assertSame('A000', Cid10::normalize(' a00.0 '));
        $this->assertNull(Cid10::normalize('A00'));
        $this->assertSame('A33', Cid10::normalize('A33'));
        $this->assertSame('Cólera devida a Vibrio cholerae 01, biótipo cholerae', Cid10::name('A000'));
        $this->assertSame('F', Cid10::sex('O800'));
        $this->assertNull(Cid10::sex('J189'));
        $this->assertSame(['A000', 'A001', 'A009'], array_column(Cid10::search('a00'), 'code'));
        // Words in any order, accents ignored.
        $this->assertContains('J189', array_column(Cid10::search('pneumonia nao especificada'), 'code'));
        $this->assertCount(5, Cid10::search('febre', 5));
        $this->assertSame([], Cid10::search('a'));
        $this->assertGreaterThan(12000, count(Cid10::all()));
    }

    /**
     * Step 6b.2: the CID-10 at the close of a SUS consultation, checked against the table and the patient's sex,
     * kept insert-only by version; optional in the other modalities; billing sees only the codes of the SUS account.
     */
    public function test_a_sus_visit_closes_with_its_cid10_and_billing_sees_only_the_codes(): void
    {
        $appointment = $this->arrived($this->doctor, $this->unit);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment))->assertRedirect();
        $visit = Visit::withoutTenantScope()->latest('id')->firstOrFail();
        $this->get(route('visits.show', $visit))->assertInertia(fn (Assert $page) => $page->where('visit.cidRequired', true));
        $close = fn (array $data) => $this->post(route('visits.close', $visit), $data);

        // Required in the SUS, from the table, matching the patient (a woman), the principal before the secondary.
        $close([])->assertSessionHasErrors(['cid_principal' => 'Informe o CID-10 principal (obrigatório no SUS).']);
        $close(['cid_principal' => 'X99.99'])->assertSessionHasErrors('cid_principal');
        $close(['cid_principal' => 'N40'])->assertSessionHasErrors(['cid_principal' => 'O CID N40 é exclusivo do sexo masculino.']);
        $close(['cid_principal' => 'J18.9', 'cid_secondary' => 'I10 Z99.99'])->assertSessionHasErrors('cid_secondary');
        $this->assertNull($visit->fresh()->closed_at);
        $close(['cid_principal' => 'j18.9', 'cid_secondary' => 'I10, e11.9; J18.9'])->assertSessionHasNoErrors();
        $this->assertSame([['J189', true, 1], ['I10', false, 1], ['E119', false, 1]], DB::table('diagnoses')->orderBy('id')->get()
            ->map(fn ($row) => [$row->cid_code, (bool) $row->principal, $row->version])->all());
        // Never in the audit.
        $this->assertStringNotContainsString('J189', AuditEvent::query()->pluck('context')->toJson());

        // Billing reads the SUS account's codes with their names, nothing of the record.
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $account = BillingAccount::withoutTenantScope()->where('appointment_id', $appointment->id)->sole();
        $this->actingAs($billing->user)->get(route('billing-accounts.show', $account))->assertInertia(fn (Assert $page) => $page
            ->where('diagnoses.0', ['code' => 'J189', 'name' => 'Pneumonia não especificada', 'principal' => true])->has('diagnoses', 3));

        // The database keeps them: no change, no deletion, one principal per version.
        app(CurrentTenant::class)->set($this->unit->tenant);
        foreach ([
            fn () => DB::table('diagnoses')->update(['cid_code' => 'A000']),
            fn () => DB::table('diagnoses')->delete(),
            fn () => DB::table('diagnoses')->insert(['tenant_id' => $visit->tenant_id, 'patient_id' => $visit->patient_id, 'visit_id' => $visit->id, 'version' => 1,
                'cid_code' => 'A000', 'principal' => true, 'recorded_by_user_id' => $this->doctorAccount->user_id, 'recorded_at' => now()]),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('The database accepted a change to a diagnosis.');
            } catch (QueryException) {
            }
        }
    }

    public function test_the_cid10_is_optional_outside_the_sus_and_searchable(): void
    {
        $appointment = $this->arrived($this->doctor, $this->unit, '09:20', ['modality' => 'private']);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment))->assertRedirect();
        $visit = Visit::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('visits.close', $visit))->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('diagnoses')->count());

        $this->getJson(route('cid10.search', ['q' => 'j18.9']))->assertOk()->assertExactJson([['code' => 'J189', 'name' => 'Pneumonia não especificada']]);
        $this->getJson(route('cid10.search', ['q' => 'x']))->assertExactJson([]);
        auth()->logout();
        $this->getJson(route('cid10.search', ['q' => 'j18']))->assertUnauthorized();
    }
}
