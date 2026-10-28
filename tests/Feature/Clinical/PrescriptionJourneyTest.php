<?php

namespace Tests\Feature\Clinical;

use App\Clinical\TestPrescriptionSigner;
use App\Jobs\ApplyImportBatch;
use App\Jobs\DeliverNotifications;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\ImportBatch;
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
use App\Models\StockInventory;
use App\Models\StockLot;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
 *
 * Step 4c.5 (ADR-0007): the pharmacy stock along the same journey: the initial balance imported by CSV, a lot entry
 * (an expired one refused), the dispensing split between two lots, no balance → quick entry → dispensing, the
 * return into its lots, a discard, an inventory approved by another person, the daily alert and the movements CSV.
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

        // 3. People by invitation: reception, bed management, pharmacy (unit role), the unit manager, auditor.
        $users = [];
        foreach (['recepcao' => ['reception', $unit], 'leitos' => ['bed_management', $unit], 'farmacia' => ['pharmacy', $unit], 'gestor' => ['unit_manager', $unit], 'auditoria' => ['auditor', null]] as $key => [$role, $roleUnit]) {
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
        $this->post(route('emergency.arrive', $unit), ['patient_id' => $patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($doctorUser)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Fratura de fêmur após queda.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->post(route('visits.close', Visit::withoutTenantScope()->sole()), ['cid_principal' => 'J18.9', 'outcome' => 'admission_requested', 'requested_sector_id' => $ward->id, 'responsible_professional_id' => $doctor->id])->assertSessionHasNoErrors();
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
        // Step 4c: the initial balance of dipyrone imported by CSV (2 left of a lot that expires first), then a lot
        // entry; an expired lot never enters.
        $this->post(route('imports.store'), ['type' => 'stock', 'source_system' => 'Sistema antigo', 'file' => UploadedFile::fake()->createWithContent('saldo.csv',
            "source_id;unit;active_ingredient;form;strength;lot_code;expires_on;quantity;manufacturer\r\n1;Hospital Centro;Dipirona;Comprimido;500 mg;IMP1;31/01/2027;2;")])->assertRedirect();
        $batch = ImportBatch::withoutTenantScope()->sole();
        $this->post(route('imports.confirm', $batch))->assertSessionHasNoErrors();
        // The queued apply, run here; it applies as the batch's creator and then signs out, so the pharmacy signs in again.
        (new ApplyImportBatch($batch->tenant_id, $batch->id))->handle(app(CurrentTenant::class));
        $this->actingAs($users['farmacia']);
        $entry = ['unit_id' => $unit->id, 'medication_id' => $medications['Dipirona'], 'lot_code' => 'J1', 'quantity' => 100];
        $this->post(route('stock.entries.store'), [...$entry, 'expires_on' => '2026-11-19'])->assertSessionHasErrorsIn('entry', ['expires_on' => 'Lote vencido não entra no estoque.']);
        $this->post(route('stock.entries.store'), [...$entry, 'expires_on' => '2027-06-30'])->assertSessionHasNoErrors();
        [$dipyroneItem, $morphineItem] = PrescriptionItem::withoutTenantScope()->where('prescription_id', $first->id)->orderBy('id')->get()->all();
        foreach ([$dipyroneItem, $morphineItem] as $item) {
            $this->post(route('pharmacy.review', $item), ['decision' => 'approved'])->assertSessionHasNoErrors();
        }
        // Dipyrone splits between the two lots, the one that expires first first.
        $this->post(route('pharmacy.dispense', $dipyroneItem), ['quantity' => 4])->assertSessionHasNoErrors();
        // Morphine has no balance: refused, the quick lot entry on the same screen, then dispensed.
        $this->post(route('pharmacy.dispense', $morphineItem), ['quantity' => 4])->assertSessionHasErrors(['quantity' => 'Sem saldo deste medicamento no estoque da unidade. Registre a entrada do lote.'], null, "dispense{$morphineItem->id}");
        $this->post(route('stock.entries.store'), [...$entry, 'medication_id' => $medications['Morfina'], 'expires_on' => '2027-06-30'])->assertSessionHasNoErrors();
        $this->post(route('pharmacy.dispense', $morphineItem), ['quantity' => 4])->assertSessionHasNoErrors();
        $balances = fn () => DB::table('stock_lots')->join('medications', 'medications.id', '=', 'stock_lots.medication_id')->orderBy('stock_lots.id')
            ->get(['medications.active_ingredient', 'stock_lots.lot_code', 'stock_lots.balance'])->map(fn ($lot) => "{$lot->active_ingredient} {$lot->lot_code} {$lot->balance}")->all();
        $this->assertSame(['Dipirona IMP1 0', 'Dipirona J1 98', 'Morfina J1 96'], $balances());
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
        // What came back returns to the lots it left, up to what each gave, the longest expiry first.
        $this->post(route('pharmacy.return', PrescriptionDispensing::withoutTenantScope()->orderBy('id')->firstOrFail()), ['quantity' => 3, 'reason' => 'Doses não administradas.'])->assertSessionHasNoErrors();
        $this->assertSame(['Dipirona IMP1 1', 'Dipirona J1 100', 'Morfina J1 96'], $balances());

        // Step 4c.3: a broken ampoule discarded; an inventory counted by the pharmacy finds one dipyrone missing and
        // is approved by another person, the unit manager.
        $lot = fn (string $ingredient, string $code) => StockLot::withoutTenantScope()->where(['medication_id' => $medications[$ingredient], 'lot_code' => $code])->sole();
        $this->post(route('stock.discards.store', $lot('Morfina', 'J1')), ['reason' => 'damage', 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(route('stock.inventories.store'), ['unit_id' => $unit->id])->assertSessionHasNoErrors();
        $inventory = StockInventory::withoutTenantScope()->sole();
        foreach ($inventory->lines as $line) {
            $balance = StockLot::withoutTenantScope()->findOrFail($line->stock_lot_id)->balance;
            $this->put(route('stock.inventories.count', [$inventory, $line]), ['counted' => $line->stock_lot_id === $lot('Dipirona', 'J1')->id ? $balance - 1 : $balance])->assertSessionHasNoErrors();
        }
        $this->post(route('stock.inventories.submit', $inventory))->assertSessionHasNoErrors();
        $this->post(route('stock.inventories.approve', $inventory))->assertForbidden();
        $this->actingAs($users['gestor'])->post(route('stock.inventories.approve', $inventory))->assertSessionHasNoErrors();
        $this->assertSame(['Dipirona IMP1 1', 'Dipirona J1 99', 'Morfina J1 95'], $balances());

        // Step 4c.4: below the minimum, the pharmacy and the manager hear of it once today (8 h in the unit); the
        // movements CSV matches the ledger and carries no patient.
        $this->actingAs($users['farmacia'])->put(route('stock.minimums.update'), ['unit_id' => $unit->id, 'medication_id' => $medications['Morfina'], 'minimum' => 200])->assertSessionHasNoErrors();
        $this->artisan('stock:alerts')->expectsOutput('Avisos enviados: 1.');
        $this->artisan('stock:alerts')->expectsOutput('Avisos enviados: 0.');
        (new DeliverNotifications)->handle();
        $this->assertEqualsCanonicalizing([$users['farmacia']->id, $users['gestor']->id],
            DB::table('user_notifications')->where('title', 'Estoque: 1 medicamento(s) abaixo do mínimo')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $csv = $this->actingAs($users['gestor'])->get(route('stock.movements.csv', ['unit' => $unit->id, 'from' => '2026-11-20', 'to' => '2026-11-21']))->streamedContent();
        $this->assertCount(1 + DB::table('stock_movements')->count(), explode("\n", trim($csv)));
        $this->assertStringNotContainsString('Benedita', $csv);
        $this->actingAs($users['gestor'])->get(route('stock.indicators.index'))->assertInertia(fn (Assert $page) => $page->where('units.0.below', 1)->where('units.0.losses', ['damage' => 1, 'inventory' => 1]));

        // 10. Discharge closes the prescription: out of the queue, nothing more to administer or download.
        $this->at('2026-11-21 15:00');
        $this->actingAs($doctorUser)->post(route('ward.discharge', $admission), ['cid_principal' => 'J18.9', 'type' => 'improved', 'summary' => 'Fratura estabilizada, analgesia oral.', 'password' => 'password'])->assertSessionHasNoErrors();
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
        foreach (['prescription.signed', 'prescription.certified', 'prescription_item.reviewed', 'prescription_item.dispensed', 'medication.recorded', 'admission.discharged',
            'stock.entry', 'stock.discard', 'stock.inventory_approved', 'stock.daily_alert', 'stock.movements_exported'] as $action) {
            $this->assertTrue(AuditEvent::where('tenant_id', $unit->tenant_id)->where('action', $action)->exists(), "Missing audit event {$action}");
        }
    }
}
