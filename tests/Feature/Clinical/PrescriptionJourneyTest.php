<?php

namespace Tests\Feature\Clinical;

use App\Clinical\TestPrescriptionSigner;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\MedicationAdministration;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionDispensing;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionSignature;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Step 4b.6: the whole prescription journey on a brand-new client, only through the HTTP routes people use:
 * admission → prescription with a justified allergy, signed with the (test) certificate → pharmacy review →
 * dispensing → administration with double check → renewal the next day → discharge closing the prescription.
 * Then the read trail and the audit reconciled against what happened.
 */
class PrescriptionJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    /** The emailed password link sets the password and confirms the address; here both are applied directly. */
    private function confirmed(string $email): User
    {
        $user = User::where('email', $email)->sole();
        $user->forceFill(['email_verified_at' => now(), 'password' => 'password'])->save();

        return $user;
    }

    /** The first scheduled dose of the item named, among the items of the prescription. */
    private function firstDose(Prescription $prescription, string $ingredient): int
    {
        $item = PrescriptionItem::withoutTenantScope()->where('prescription_id', $prescription->id)->where('medication_label', 'like', "{$ingredient}%")->sole();

        return (int) DB::table('prescription_doses')->where('prescription_item_id', $item->id)->orderBy('scheduled_at')->value('id');
    }

    public function test_the_prescription_journey_on_a_new_client_reconciles(): void
    {
        Queue::fake();
        Notification::fake();
        Storage::fake('local');
        config(['services.icp.signer' => 'test', 'services.icp.trust_anchors' => Storage::disk('local')->path(TestPrescriptionSigner::AUTHORITY)]);
        $this->at('2026-11-20 12:00');

        // 1. Provisioning and structure: a unit with the emergency room and a ward, one bed, a care function.
        $this->artisan('tenant:provision', ['name' => 'Rede Prescrição', '--admin-email' => 'admin@rx.example', '--admin-name' => 'Admin Rx'])->assertSuccessful();
        $admin = $this->confirmed('admin@rx.example');
        $this->actingAs($admin)->post(route('units.store'), ['name' => 'Hospital Centro', 'uf' => 'SP', 'timezone' => 'America/Sao_Paulo'])->assertSessionHasNoErrors();
        $unit = Unit::withoutTenantScope()->sole();
        foreach (['Pronto-socorro', 'Clínica médica'] as $sector) {
            $this->post(route('units.sectors.store', $unit), ['name' => $sector])->assertSessionHasNoErrors();
        }
        $ward = Sector::withoutTenantScope()->where('name', 'Clínica médica')->sole();
        $this->post(route('beds.store', $unit), ['sector_id' => $ward->id, 'room' => '1', 'code' => 'CM-1', 'type' => 'ward'])->assertSessionHasNoErrors();
        $bed = Bed::withoutTenantScope()->sole();
        $this->post(route('job-functions.store'), ['name' => 'Assistência', 'attends_patients' => true])->assertSessionHasNoErrors();
        $function = JobFunction::withoutTenantScope()->sole();

        // 2. Catalog with the network's safety rules: dipyrone, and morphine (controlled: double check).
        foreach ([
            ['Dipirona', 'Comprimido', '500 mg', ['oral'], false, 1000, 4000],
            ['Morfina', 'Solução injetável', '10 mg/mL', ['iv'], true, 10, 60],
        ] as [$ingredient, $form, $strength, $routes, $controlled, $single, $daily]) {
            $this->post(route('medications.store'), [
                'active_ingredient' => $ingredient, 'form' => $form, 'strength' => $strength, 'dose_unit' => 'mg', 'routes' => $routes,
                'controlled' => $controlled, 'high_alert' => false, 'max_single_dose' => $single, 'max_daily_dose' => $daily,
            ])->assertSessionHasNoErrors();
        }

        // 3. People by invitation: reception, bed management, pharmacy (unit role), auditor.
        $users = [];
        foreach (['recepcao' => ['reception', $unit], 'leitos' => ['bed_management', $unit], 'farmacia' => ['pharmacy', $unit], 'auditoria' => ['auditor', null]] as $key => [$role, $roleUnit]) {
            $this->actingAs($admin)->post(route('people.store'), array_filter(['name' => ucfirst($key), 'email' => "{$key}@rx.example", 'role' => $role, 'unit_id' => $roleUnit?->id]))->assertSessionHasNoErrors();
            $users[$key] = $this->confirmed("{$key}@rx.example");
        }

        // 4. Professionals: the doctor (CRM and CPF, for the certificate) and two ward nurses.
        $staff = [];
        foreach ([
            'medico' => ['Dr. Heitor', 'crm', '41111', 'Pronto-socorro', '52998224725'],
            'enf1' => ['Enf. Joana', 'coren', '42222', 'Clínica médica', ''], 'enf2' => ['Enf. Davi', 'coren', '43333', 'Clínica médica', ''],
        ] as $key => [$name, $council, $number, $sector, $cpf]) {
            $this->actingAs($admin)->post(route('professionals.store'), [
                'name' => $name, 'base_unit_id' => $unit->id, 'council' => $council, 'council_number' => $number, 'council_uf' => 'SP',
                'external_id' => strtoupper($key), 'email' => "{$key}@rx.example", 'phone' => '', 'cpf' => $cpf,
            ])->assertSessionHasNoErrors();
            $professional = Professional::withoutTenantScope()->where('name', $name)->sole();
            $this->post(route('professionals.qualifications.store', $professional), [
                'unit_id' => $unit->id, 'job_function_id' => $function->id, 'valid_from' => '2026-01-01', 'valid_until' => '',
                'sector_ids' => [Sector::withoutTenantScope()->where('name', $sector)->value('id')],
            ])->assertSessionHasNoErrors();
            $this->post(route('qualifications.transition', Qualification::withoutTenantScope()->where('professional_id', $professional->id)->sole()), ['status' => 'approved'])->assertSessionHasNoErrors();
            $this->post(route('professionals.invite', $professional))->assertSessionHasNoErrors();
            $staff[$key] = [$professional, $this->confirmed("{$key}@rx.example")];
        }
        [[$doctor, $doctorUser], [, $nurse], [, $witness]] = [$staff['medico'], $staff['enf1'], $staff['enf2']];

        // 5. Emergency care, admission request and the ward bed.
        $this->actingAs($users['recepcao'])->post(route('patients.store'), ['registered_unit_id' => $unit->id, 'name' => 'Benedita Faria Nunes', 'birth_date' => '1948-03-02', 'sex' => 'female', 'cpf' => '11144477735'])->assertSessionHasNoErrors();
        $patient = Patient::withoutTenantScope()->sole();
        $this->post(route('emergency.arrive', $unit), ['patient_id' => $patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($doctorUser)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Fratura de fêmur após queda.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->post(route('visits.close', Visit::withoutTenantScope()->sole()), ['outcome' => 'admission_requested', 'requested_sector_id' => $ward->id, 'responsible_professional_id' => $doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->sole();
        $this->actingAs($users['leitos'])->post(route('admissions.allocate', $admission), ['bed_id' => $bed->id])->assertSessionHasNoErrors();

        // 6. The doctor records the allergy, links the certificate and prescribes; the allergy alert is justified.
        $this->actingAs($doctorUser)->post(route('patient-allergies.store', $patient), ['substance' => 'Dipirona', 'severity' => 'mild'])->assertSessionHasNoErrors();
        $this->post(route('certificate.link'))->assertSessionHasNoErrors();
        $this->post(route('ward.prescriptions.start', $admission))->assertRedirect();
        $first = Prescription::withoutTenantScope()->where('status', 'draft')->sole();
        $medications = DB::table('medications')->pluck('id', 'active_ingredient');
        $dipyrone = ['medication_id' => $medications['Dipirona'], 'dose' => '500', 'route' => 'oral', 'schedule' => 'interval', 'frequency_hours' => 6, 'prn' => '0'];
        $this->post(route('prescriptions.items.store', $first), $dipyrone)->assertSessionHasErrors('justification');
        $this->post(route('prescriptions.items.store', $first), [...$dipyrone, 'justification' => 'Reação leve antiga; analgesia necessária.'])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.items.store', $first), ['medication_id' => $medications['Morfina'], 'dose' => '2', 'route' => 'iv', 'schedule' => 'interval', 'frequency_hours' => 6, 'prn' => '0'])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.sign', $first), ['password' => 'password'])->assertRedirect(route('ward.show', $admission));
        $this->assertSame([$first->id, '52998224725'], [PrescriptionSignature::withoutTenantScope()->sole()->prescription_id, PrescriptionSignature::withoutTenantScope()->sole()->signer_cpf]);

        // 7. Pharmacy: the queue, the review (read logged) and the dispensing for 24 hours.
        $this->actingAs($users['farmacia'])->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->has('prescriptions', 1)->where('prescriptions.0.pending', 2));
        $this->get(route('pharmacy.show', $first))->assertOk();
        // Step 4c.2: the dispensing takes from the stock, so the pharmacy enters the lots first.
        foreach (['Dipirona', 'Morfina'] as $ingredient) {
            $this->post(route('stock.entries.store'), ['unit_id' => $unit->id, 'medication_id' => $medications[$ingredient], 'lot_code' => 'J1', 'expires_on' => '2027-06-30', 'quantity' => 100])->assertSessionHasNoErrors();
        }
        foreach (PrescriptionItem::withoutTenantScope()->where('prescription_id', $first->id)->get() as $item) {
            $this->post(route('pharmacy.review', $item), ['decision' => 'approved'])->assertSessionHasNoErrors();
            $this->post(route('pharmacy.dispense', $item), ['quantity' => 4])->assertSessionHasNoErrors();
        }
        $this->assertSame([96, 96], DB::table('stock_lots')->orderBy('id')->pluck('balance')->all());
        $this->get(route('ward.show', $admission))->assertForbidden();

        // 8. Administration: dipyrone by one nurse; morphine needs the second nurse's password.
        $this->actingAs($nurse)->post(route('medication.dose', $this->firstDose($first, 'Dipirona')), ['outcome' => 'administered'])->assertSessionHasNoErrors();
        $morphine = $this->firstDose($first, 'Morfina');
        $this->post(route('medication.dose', $morphine), ['outcome' => 'administered'])->assertSessionHasErrors('witness_user_id', null, "dose{$morphine}");
        $this->post(route('medication.dose', $morphine), ['outcome' => 'administered', 'witness_user_id' => $witness->id, 'witness_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame([false, $witness->id], [MedicationAdministration::withoutTenantScope()->latest('id')->firstOrFail()->exception, MedicationAdministration::withoutTenantScope()->latest('id')->firstOrFail()->witness_user_id]);
        $this->get(route('ward.index'))->assertOk();

        // 9. The next morning the doctor renews: the new prescription replaces the first and returns to the queue.
        $this->at('2026-11-21 11:00');
        $this->actingAs($doctorUser)->post(route('ward.prescriptions.start', $admission))->assertRedirect();
        $renewal = Prescription::withoutTenantScope()->where('status', 'draft')->sole();
        $this->assertSame(2, PrescriptionItem::withoutTenantScope()->where('prescription_id', $renewal->id)->count());
        $this->post(route('prescriptions.sign', $renewal), ['password' => 'password'])->assertRedirect(route('ward.show', $admission));
        $this->get(route('ward.show', $admission))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.id', $renewal->id)->has('prescriptions.previous', 1)->where('prescriptions.active.canCertify', false));
        $this->assertSame(2, PrescriptionSignature::withoutTenantScope()->count());
        // The replaced one stays open only to return what was dispensed (step 4b.3b), never to dispense again.
        $this->actingAs($users['farmacia'])->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions', 1)->where('prescriptions.0.id', $renewal->id)->has('returns', 1)->where('returns.0.id', $first->id));
        $this->post(route('pharmacy.dispense', PrescriptionItem::withoutTenantScope()->where('prescription_id', $first->id)->firstOrFail()), ['quantity' => 1])->assertForbidden();
        // What came back returns to the lot it left.
        $this->post(route('pharmacy.return', PrescriptionDispensing::withoutTenantScope()->orderBy('id')->firstOrFail()), ['quantity' => 3, 'reason' => 'Doses não administradas.'])->assertSessionHasNoErrors();
        $this->assertSame(195, (int) DB::table('stock_lots')->sum('balance'));

        // 10. Discharge closes the prescription: out of the queue, nothing more to administer or download.
        $this->at('2026-11-21 15:00');
        $this->actingAs($doctorUser)->post(route('ward.discharge', $admission), ['type' => 'improved', 'summary' => 'Fratura estabilizada, analgesia oral.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($users['farmacia'])->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->has('prescriptions', 0));
        $this->actingAs($nurse)->post(route('medication.dose', $this->firstDose($renewal, 'Dipirona')), ['outcome' => 'administered'])->assertForbidden();
        $this->actingAs($doctorUser)->get(route('prescriptions.signature', [$renewal, 'pdf']))->assertForbidden();

        // 11. Reconciliation: the pharmacy reads are in the trail; the audit carries no clinical text or identity.
        $this->assertGreaterThan(0, DB::table('clinical_reads')->where('reason', 'pharmacy')->where('user_id', $users['farmacia']->id)->count());
        $this->actingAs($users['auditoria'])->get(route('clinical-access.index'))->assertOk();
        $audit = (string) json_encode(AuditEvent::where('tenant_id', $unit->tenant_id)->get(['action', 'context']));
        foreach (['Benedita', '11144477735', '52998224725', 'Dipirona', 'Morfina', 'Reação leve', 'Fratura', 'Doses não', 'password'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $audit, "Audit carries \"{$sensitive}\"");
        }
        foreach (['prescription.signed', 'prescription.certified', 'prescription_item.reviewed', 'prescription_item.dispensed', 'medication.recorded', 'admission.discharged'] as $action) {
            $this->assertTrue(AuditEvent::where('tenant_id', $unit->tenant_id)->where('action', $action)->exists(), "Missing audit event {$action}");
        }
    }
}
