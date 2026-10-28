<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 4b.1c: allergies recorded on the patient by whoever writes on the record, shown at the top of it,
 * inactivated with a reason and never changed or deleted.
 */
class PatientAllergyTest extends ClinicalTestCase
{
    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $appointment = $this->arrived($this->doctor, $this->unit);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment));
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    public function test_recorded_during_the_visit_and_shown_at_the_top_of_the_record(): void
    {
        $record = fn (array $data) => $this->post(route('patient-allergies.store', $this->patient), $data);
        $record(['substance' => '  Dipirona ', 'severity' => 'severe', 'reaction' => 'Urticária'])->assertSessionHasNoErrors();
        // Same substance, other spelling: one active allergy per substance.
        $record(['substance' => 'DIPIRONA', 'severity' => 'mild'])->assertSessionHasErrors('substance');
        $record(['substance' => 'Penicilina', 'severity' => 'fatal'])->assertSessionHasErrors('severity');
        $record(['substance' => '   ', 'severity' => 'mild'])->assertSessionHasErrors('substance');

        $allergy = PatientAllergy::withoutTenantScope()->sole();
        $this->assertSame(['Dipirona', 'dipirona', 'severe', 'Urticária', $this->doctor->id], [$allergy->substance, $allergy->substance_key, $allergy->severity, $allergy->reaction, $allergy->professional_id]);
        // The audit keeps ids only, never the substance.
        $audit = AuditEvent::where('action', 'patient_allergy.recorded')->sole();
        $this->assertStringNotContainsStringIgnoringCase('dipirona', json_encode($audit->context) ?: '');

        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('allergies.0.substance', 'Dipirona')->where('allergies.0.severity', 'Grave')
            ->where('allergyForm.patientId', $this->patient->id));
    }

    public function test_inactivated_with_a_reason_once_then_recorded_again(): void
    {
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Dipirona', 'severity' => 'unknown'])->assertSessionHasNoErrors();
        $allergy = PatientAllergy::withoutTenantScope()->sole();

        $this->post(route('patient-allergies.inactivate', $allergy), [])->assertSessionHasErrors('reason');
        $this->post(route('patient-allergies.inactivate', $allergy), ['reason' => 'Tolerou dipirona sem reação.'])->assertSessionHasNoErrors();
        $this->post(route('patient-allergies.inactivate', $allergy), ['reason' => 'De novo.'])->assertSessionHasErrors('reason');
        $this->assertSame(1, AuditEvent::where('action', 'patient_allergy.inactivated')->count());

        // The inactivated one stays in the history; the substance can be recorded again.
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Dipirona', 'severity' => 'mild'])->assertSessionHasNoErrors();
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->has('allergies', 2)->where('allergies.0.inactivatedAt', null)->where('allergies.1.inactivationReason', 'Tolerou dipirona sem reação.'));

        // The database refuses anything but the inactivation, once, and never deletes.
        app(CurrentTenant::class)->set($this->unit->tenant);
        foreach ([
            fn () => DB::table('patient_allergies')->where('id', $allergy->id)->update(['inactivation_reason' => 'Outro motivo']),
            fn () => DB::table('patient_allergies')->where('inactivated_at', null)->update(['severity' => 'severe']),
            fn () => DB::table('patient_allergies')->where('id', $allergy->id)->delete(),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('An allergy was changed or deleted.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->getCode());
            }
        }
    }

    public function test_only_who_writes_on_the_record_records_and_an_emergency_grant_reads(): void
    {
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Dipirona', 'severity' => 'severe'])->assertSessionHasNoErrors();
        $allergy = PatientAllergy::withoutTenantScope()->sole();

        // Without a visit or admission: neither record nor inactivate.
        $this->actingAs($this->nurseAccount->user);
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Penicilina', 'severity' => 'mild'])->assertForbidden();
        $this->post(route('patient-allergies.inactivate', $allergy), ['reason' => 'Não sei.'])->assertForbidden();

        // An emergency grant reads them, with no form.
        $this->post(route('clinical-record.emergency.store', $this->patient), ['unit_id' => $this->other->id, 'justification' => 'Paciente desacordada no pronto-socorro.']);
        $this->get(route('clinical-record.show', $this->patient))->assertInertia(fn (Assert $page) => $page
            ->component('clinical/record')->where('allergies.0.substance', 'Dipirona')->missing('allergyForm'));
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Penicilina', 'severity' => 'mild'])->assertForbidden();

        // Once the visit closes, the doctor no longer writes either.
        $this->actingAs($this->doctorAccount->user)->post(route('visits.close', $this->visit), ['cid_principal' => 'J18.9']);
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Penicilina', 'severity' => 'mild'])->assertForbidden();
        $this->assertSame(1, PatientAllergy::withoutTenantScope()->count());
    }

    public function test_accents_and_a_merged_record_never_hide_a_duplicate(): void
    {
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Ácido acetilsalicílico', 'severity' => 'severe'])->assertSessionHasNoErrors();
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'acido acetilsalicilico', 'severity' => 'mild'])->assertSessionHasErrors('substance');
        $this->assertSame('acido acetilsalicilico', PatientAllergy::withoutTenantScope()->sole()->substance_key);

        // A duplicate registration with its own allergy, merged into this patient.
        $this->actingAs($this->reception->user)->post(route('patients.store'), ['registered_unit_id' => $this->unit->id, 'name' => 'Joana Prado Silva', 'birth_date' => '1990-01-02', 'sex' => 'female', 'confirm_new' => true])->assertSessionHasNoErrors();
        $duplicate = Patient::withoutTenantScope()->latest('id')->firstOrFail();
        app(CurrentTenant::class)->set($this->unit->tenant);
        PatientAllergy::query()->create(['patient_id' => $duplicate->id, 'substance' => 'Dipirona', 'substance_key' => 'dipirona', 'severity' => 'severe', 'recorded_by_user_id' => $this->doctorAccount->user_id, 'professional_id' => $this->doctor->id, 'created_at' => now()]);
        app(CurrentTenant::class)->set(null);
        $this->actingAs($this->manager->user)->post(route('patient-merges.store'), ['a_id' => $this->patient->id, 'b_id' => $duplicate->id, 'primary_id' => $this->patient->id, 'reason' => 'Mesma pessoa'])->assertSessionHasNoErrors();

        $this->actingAs($this->doctorAccount->user)->post(route('patient-allergies.store', $this->patient), ['substance' => 'DIPIRONA', 'severity' => 'mild'])->assertSessionHasErrors('substance');
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->has('allergies', 2));
    }
}
