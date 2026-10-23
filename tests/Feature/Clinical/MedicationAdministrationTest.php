<?php

namespace Tests\Feature\Clinical;

use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Medication;
use App\Models\MedicationAdministration;
use App\Models\Membership;
use App\Models\Prescription;
use App\Models\PrescriptionDispensing;
use App\Models\PrescriptionItem;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 4b.4 (ADR-0005): the team of the bed's sector records each dose of the active prescription once; an item
 * not dispensed only as an exception; controlled and high-alert medications with a second person.
 */
class MedicationAdministrationTest extends ClinicalTestCase
{
    private Admission $admission;

    private Prescription $prescription;

    private Membership $wardNurse;

    private Membership $secondNurse;

    private Membership $icuNurse;

    /** @var array<string, PrescriptionItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->forceFill(['council' => 'CRM', 'council_number' => '123456', 'council_uf' => 'SP'])->save();
        $ward = Sector::factory()->for($this->unit)->create(['name' => 'Clínica médica']);
        $icu = Sector::factory()->for($this->unit)->create(['name' => 'UTI']);
        $catalog = [];
        foreach ([['Dipirona', '500 mg', ['oral'], false], ['Morfina', '10 mg', ['iv'], true], ['Paracetamol', '750 mg', ['oral'], false]] as [$ingredient, $strength, $routes, $controlled]) {
            $catalog[$ingredient] = Medication::query()->create(['active_ingredient' => $ingredient, 'form' => 'Comprimido', 'strength' => $strength, 'dose_unit' => 'mg', 'routes' => $routes, 'controlled' => $controlled, 'high_alert' => false]);
        }
        app(CurrentTenant::class)->set(null);
        foreach ($catalog as $medication) {
            $this->stockLot($this->unit->id, $medication);
        }
        $this->wardNurse = $this->teamMember('Enf. Carla', $ward);
        $this->secondNurse = $this->teamMember('Enf. Beatriz', $ward);
        $this->icuNurse = $this->teamMember('Enf. Davi', $icu);

        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $ward->id, 'room' => '1', 'code' => 'E-1', 'type' => 'ward'])->assertSessionHasNoErrors();
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dor.']);
        $this->post(route('emergency.call', $episode));
        $this->post(route('visits.close', Visit::withoutTenantScope()->sole()), ['outcome' => 'admission_requested', 'requested_sector_id' => $ward->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $this->admission = Admission::withoutTenantScope()->sole();
        $bedManager = Membership::factory()->for($this->unit->tenant)->create();
        $bedManager->grant(Role::BedManagement, $this->unit);
        $this->actingAs($bedManager->user)->post(route('admissions.allocate', $this->admission), ['bed_id' => Bed::withoutGlobalScopes()->sole()->id])->assertSessionHasNoErrors();

        $this->actingAs($this->doctorAccount->user)->post(route('ward.prescriptions.start', $this->admission))->assertRedirect();
        $this->prescription = Prescription::withoutTenantScope()->sole();
        foreach ([
            'dipirona' => [$catalog['Dipirona'], 'oral', 500, '0'],
            'morfina' => [$catalog['Morfina'], 'iv', 2, '0'],
            'paracetamol' => [$catalog['Paracetamol'], 'oral', 750, '1'],
        ] as $key => [$medication, $route, $dose, $prn]) {
            $this->post(route('prescriptions.items.store', $this->prescription), [
                'medication_id' => $medication->id, 'dose' => $dose, 'route' => $route, 'schedule' => 'interval', 'frequency_hours' => 6,
                'prn' => $prn, 'prn_condition' => $prn === '1' ? 'Dor' : null,
            ])->assertSessionHasNoErrors();
            $this->items[$key] = PrescriptionItem::withoutTenantScope()->latest('id')->firstOrFail();
        }
        $this->post(route('prescriptions.sign', $this->prescription), ['password' => 'password'])->assertSessionHasNoErrors();
    }

    private function teamMember(string $name, Sector $sector): Membership
    {
        $unit = $sector->unit_id === $this->other->id ? $this->other : $this->unit;
        $account = Membership::factory()->for($this->unit->tenant)->create();
        $account->grant(Role::Professional, $unit);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $professional = Professional::factory()->create(['base_unit_id' => $unit->id, 'name' => $name]);
        $professional->forceFill(['membership_id' => $account->id])->save();
        Qualification::request($professional, $unit, ['job_function_id' => JobFunction::query()->firstOrFail()->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$sector->id])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);

        return $account;
    }

    /**
     * @return list<int>
     */
    private function doses(string $item): array
    {
        return array_map('intval', DB::table('prescription_doses')->where('prescription_item_id', $this->items[$item]->id)->orderBy('scheduled_at')->pluck('id')->all());
    }

    public function test_the_ward_team_records_each_dose_once_and_an_item_not_dispensed_only_as_an_exception(): void
    {
        [$first, $second] = $this->doses('dipirona');
        // Neither another sector's team nor the responsible doctor out of the sector.
        $this->actingAs($this->icuNurse->user)->post(route('medication.dose', $first), ['outcome' => 'administered'])->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->post(route('medication.dose', $first), ['outcome' => 'administered'])->assertForbidden();

        $this->actingAs($this->wardNurse->user);
        $this->post(route('medication.dose', $first), ['outcome' => 'administered'])->assertSessionHasErrors('reason', null, "dose{$first}");
        // The time is typed in the unit's clock (America/Sao_Paulo) and kept as that instant.
        $this->post(route('medication.dose', $first), ['outcome' => 'administered', 'reason' => 'Farmácia fechada.', 'occurred_at' => now('America/Sao_Paulo')->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertLessThan(60, abs(MedicationAdministration::withoutTenantScope()->sole()->occurred_at->diffInSeconds(now())));
        $this->post(route('medication.dose', $first), ['outcome' => 'administered', 'reason' => 'De novo.'])->assertSessionHasErrors('outcome', null, "dose{$first}");
        $this->post(route('medication.dose', $second), ['outcome' => 'administered', 'reason' => 'Cedo.'])->assertSessionHasErrors('outcome', null, "dose{$second}");
        $record = MedicationAdministration::withoutTenantScope()->sole();
        $this->assertSame(['administered', true, 'Farmácia fechada.', $this->wardNurse->user_id], [$record->outcome, $record->exception, $record->reason, $record->recorded_by_user_id]);
        $this->assertEquals([
            'outcome' => 'administered', 'prescription_item_id' => $this->items['dipirona']->id, 'prescription_dose_id' => $first,
            'prescription_id' => $this->prescription->id, 'admission_id' => $this->admission->id, 'exception' => true,
        ], AuditEvent::where('action', 'medication.recorded')->sole()->context);

        // Refused by the patient: a reason, no second person even for a controlled medication.
        [$morphine] = $this->doses('morfina');
        $this->post(route('medication.dose', $morphine), ['outcome' => 'refused'])->assertSessionHasErrors('reason', null, "dose{$morphine}");
        $this->post(route('medication.dose', $morphine), ['outcome' => 'refused', 'reason' => 'Paciente sem dor.'])->assertSessionHasNoErrors();

        // The team sees the records on the record page; the doctor too.
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $this->admission))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.administration', null)
            ->where('prescriptions.active.items.0.doses.0.record.label', 'Administrado')
            ->where('prescriptions.active.items.0.doses.0.record.exception', true)
            ->where('prescriptions.active.items.1.doses.0.record.reason', 'Paciente sem dor.'));
        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $this->admission))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.administration.witnesses', [['id' => $this->secondNurse->user_id, 'name' => $this->secondNurse->user->name]]));

        app(CurrentTenant::class)->set($this->unit->tenant);
        try {
            DB::transaction(fn () => DB::table('medication_administrations')->where('id', $record->id)->update(['outcome' => 'not_done']));
            $this->fail('An administration record changed.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }
        // The database refuses a dose of another item.
        try {
            DB::transaction(fn () => DB::table('medication_administrations')->insert([
                'tenant_id' => $this->unit->tenant_id, 'prescription_item_id' => $this->items['morfina']->id, 'prescription_dose_id' => $second,
                'outcome' => 'refused', 'occurred_at' => now(), 'reason' => 'x', 'exception' => false, 'recorded_by_user_id' => $this->wardNurse->user_id, 'created_at' => now(),
            ]));
            $this->fail('A dose of another item was recorded.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }
        app(CurrentTenant::class)->set(null);

        // Revoked, the nurse records nothing.
        $this->wardNurse->forceFill(['revoked_at' => now()])->save();
        $this->travel(6)->hours();
        $this->actingAs($this->wardNurse->user)->post(route('medication.dose', $second), ['outcome' => 'not_done', 'reason' => 'x'])->assertForbidden();
    }

    public function test_the_time_stays_near_the_scheduled_one_and_a_full_return_is_no_dispensing(): void
    {
        // Dispensed in full and all of it returned: administering is an exception again.
        $pharmacist = Membership::factory()->for($this->unit->tenant)->create();
        $pharmacist->grant(Role::Pharmacy, $this->unit);
        $this->actingAs($pharmacist->user)->post(route('pharmacy.review', $this->items['dipirona']), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->post(route('pharmacy.dispense', $this->items['dipirona']), ['quantity' => 2])->assertSessionHasNoErrors();
        $this->post(route('pharmacy.return', PrescriptionDispensing::withoutTenantScope()->sole()), ['quantity' => 2, 'reason' => 'Sobrou.'])->assertSessionHasNoErrors();
        [$first, $second] = $this->doses('dipirona');
        $this->actingAs($this->wardNurse->user)->post(route('medication.dose', $first), ['outcome' => 'administered'])->assertSessionHasErrors('reason', null, "dose{$first}");

        // Six hours later the second dose opens; it cannot say it was given six hours early.
        $this->travel(6)->hours();
        $this->post(route('medication.dose', $second), ['outcome' => 'administered', 'reason' => 'x', 'occurred_at' => now('America/Sao_Paulo')->subHours(5)->format('Y-m-d\TH:i')])->assertSessionHasErrors('occurred_at', null, "dose{$second}");
        $this->post(route('medication.dose', $second), ['outcome' => 'administered', 'reason' => 'x'])->assertSessionHasNoErrors();
    }

    public function test_a_controlled_medication_needs_a_second_person_and_an_if_needed_item_its_interval(): void
    {
        [$morphine] = $this->doses('morfina');
        $data = ['outcome' => 'administered', 'reason' => 'Farmácia fechada.'];
        $this->actingAs($this->wardNurse->user);
        $this->post(route('medication.dose', $morphine), $data)->assertSessionHasErrors('witness_user_id', null, "dose{$morphine}");
        foreach ([$this->wardNurse, $this->icuNurse] as $notAWitness) {
            $this->post(route('medication.dose', $morphine), [...$data, 'witness_user_id' => $notAWitness->user_id, 'witness_password' => 'password'])->assertSessionHasErrors('witness_user_id', null, "dose{$morphine}");
        }
        $this->post(route('medication.dose', $morphine), [...$data, 'witness_user_id' => $this->secondNurse->user_id, 'witness_password' => 'errada'])->assertSessionHasErrors('witness_password', null, "dose{$morphine}");
        $this->assertSame(1, AuditEvent::where('action', 'medication.witness_failed')->count());
        // The colleague's password never goes back to the session.
        $this->assertArrayNotHasKey('witness_password', session('_old_input', []));
        $this->post(route('medication.dose', $morphine), [...$data, 'witness_user_id' => $this->secondNurse->user_id, 'witness_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame($this->secondNurse->user_id, MedicationAdministration::withoutTenantScope()->sole()->witness_user_id);

        // "If needed": every 6 hours at most, and within the last 12 hours.
        $prn = $this->items['paracetamol'];
        $this->assertSame([], $this->doses('paracetamol'));
        $this->post(route('medication.prn', $prn), ['reason' => 'Sem dispensação.'])->assertSessionHasNoErrors();
        $this->post(route('medication.prn', $prn), ['reason' => 'Sem dispensação.'])->assertSessionHasErrors('occurred_at', null, "prn{$prn->id}");
        $this->post(route('medication.prn', $this->items['dipirona']), ['reason' => 'x'])->assertNotFound();
        $this->travel(7)->hours();
        $this->post(route('medication.prn', $prn), ['reason' => 'Sem dispensação.', 'occurred_at' => now('America/Sao_Paulo')->subHours(13)->format('Y-m-d\TH:i')])->assertSessionHasErrors('occurred_at', null, "prn{$prn->id}");
        $this->post(route('medication.prn', $prn), ['reason' => 'Sem dispensação.'])->assertSessionHasNoErrors();
        $this->assertSame(2, MedicationAdministration::withoutTenantScope()->where('prescription_item_id', $prn->id)->count());
    }

    public function test_only_the_active_prescription_and_late_doses_show_on_the_list(): void
    {
        [$first, $second] = $this->doses('dipirona');
        $this->travel(1)->hours();
        // Late: the first dose of each regular item; next, the second of them.
        $this->actingAs($this->wardNurse->user)->get(route('ward.index'))->assertInertia(fn (Assert $page) => $page
            ->where('admissions.0.doses.late', 2)->has('admissions.0.doses.next'));
        $this->actingAs($this->wardNurse->user)->get(route('ward.show', $this->admission))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.items.0.doses.0.late', true)->where('prescriptions.active.items.0.doses.1.late', false));

        // The pharmacy refuses an item: not administered.
        $pharmacist = Membership::factory()->for($this->unit->tenant)->create();
        $pharmacist->grant(Role::Pharmacy, $this->unit);
        $this->actingAs($pharmacist->user)->post(route('pharmacy.review', $this->items['morfina']), ['decision' => 'refused', 'note' => 'Dose.'])->assertSessionHasNoErrors();
        [$morphine] = $this->doses('morfina');
        $this->actingAs($this->wardNurse->user)->post(route('medication.dose', $morphine), ['outcome' => 'not_done', 'reason' => 'x'])->assertSessionHasErrors('outcome', null, "dose{$morphine}");

        // Suspended: not administered.
        $this->actingAs($this->doctorAccount->user)->post(route('prescription-items.suspend', $this->items['dipirona']), ['reason' => 'Sem febre.'])->assertSessionHasNoErrors();
        $this->actingAs($this->wardNurse->user)->post(route('medication.dose', $first), ['outcome' => 'not_done', 'reason' => 'x'])->assertSessionHasErrors('outcome', null, "dose{$first}");

        // Renewed in the middle of the day: the replaced prescription's doses are out of reach.
        $this->actingAs($this->doctorAccount->user)->post(route('ward.prescriptions.start', $this->admission))->assertRedirect();
        $this->post(route('prescriptions.sign', Prescription::withoutTenantScope()->where('status', 'draft')->sole()), ['password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($this->wardNurse->user)->post(route('medication.dose', $second), ['outcome' => 'not_done', 'reason' => 'x'])->assertForbidden();

        // Discharged: nothing is administered.
        $renewed = DB::table('prescription_doses')->join('prescription_items', 'prescription_items.id', '=', 'prescription_doses.prescription_item_id')
            ->where('prescription_items.prescription_id', Prescription::withoutTenantScope()->latest('id')->value('id'))->orderBy('scheduled_at')->value('prescription_doses.id');
        $this->actingAs($this->doctorAccount->user)->post(route('ward.discharge', $this->admission), ['type' => 'improved', 'summary' => 'Alta.', 'password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($this->wardNurse->user)->post(route('medication.dose', $renewed), ['outcome' => 'not_done', 'reason' => 'x'])->assertForbidden();
        $this->assertSame(0, MedicationAdministration::withoutTenantScope()->count());
    }

    /**
     * Step 4b.6 (integrated review): a transfer between units ends the origin's prescription. The origin's pharmacy
     * only takes back what it dispensed; the destination neither administers nor dispenses it: its doctor prescribes.
     */
    public function test_a_transfer_between_units_ends_the_prescription_of_the_origin(): void
    {
        $tenant = $this->unit->tenant;
        [$origin, $destination, $originBeds, $destinationBeds] = [Membership::factory()->for($tenant)->create(), Membership::factory()->for($tenant)->create(), Membership::factory()->for($tenant)->create(), Membership::factory()->for($tenant)->create()];
        $origin->grant(Role::Pharmacy, $this->unit);
        $destination->grant(Role::Pharmacy, $this->other);
        $originBeds->grant(Role::BedManagement, $this->unit);
        $destinationBeds->grant(Role::BedManagement, $this->other);
        $this->actingAs($origin->user)->post(route('pharmacy.review', $this->items['dipirona']), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->post(route('pharmacy.dispense', $this->items['dipirona']), ['quantity' => 4])->assertSessionHasNoErrors();
        // The doctor leaves a renewal draft at the origin, and also works at the destination.
        $this->actingAs($this->doctorAccount->user)->post(route('ward.prescriptions.start', $this->admission))->assertRedirect();
        app(CurrentTenant::class)->set($tenant);
        $sector = Sector::factory()->for($this->other)->create(['name' => 'Clínica médica']);
        $bed = Bed::query()->create(['unit_id' => $this->other->id, 'sector_id' => $sector->id, 'room' => '2', 'code' => 'D-1', 'type' => 'ward', 'status_changed_at' => now()]);
        $this->doctorAccount->grant(Role::Professional, $this->other);
        Qualification::request($this->doctor, $this->other, ['job_function_id' => JobFunction::query()->firstOrFail()->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$sector->id])
            ->transitionTo(QualificationStatus::Approved, null);
        app(CurrentTenant::class)->set(null);
        $destinationNurse = $this->teamMember('Enf. Lia', $sector);

        $this->actingAs($this->doctorAccount->user)->post(route('transfers.store', $this->admission), ['to_unit_id' => $this->other->id, 'requested_sector_id' => $sector->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $transfer = DB::table('admission_transfers')->value('id');
        $this->actingAs($destinationBeds->user)->post(route('transfers.accept', $transfer), ['bed_id' => $bed->id])->assertSessionHasNoErrors();
        $this->actingAs($originBeds->user)->post(route('transfers.depart', $transfer))->assertSessionHasNoErrors();
        $this->actingAs($destinationBeds->user)->post(route('transfers.receive', $transfer))->assertSessionHasNoErrors();

        // The origin's pharmacy: out of the queue, open only to take back the dispensing.
        $this->actingAs($origin->user)->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->has('prescriptions', 0)->has('returns', 1));
        $this->post(route('pharmacy.review', $this->items['morfina']), ['decision' => 'approved'])->assertForbidden();
        $this->post(route('pharmacy.return', PrescriptionDispensing::withoutTenantScope()->sole()), ['quantity' => 3, 'reason' => 'Paciente transferido.'])->assertSessionHasNoErrors();
        // The destination's pharmacy and team: not theirs.
        $this->actingAs($destination->user)->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->has('prescriptions', 0));
        $this->get(route('pharmacy.show', $this->prescription))->assertForbidden();
        $this->actingAs($destinationNurse->user)->post(route('medication.dose', $this->doses('morfina')[1]), ['outcome' => 'administered'])->assertForbidden();
        $this->get(route('ward.index'))->assertInertia(fn (Assert $page) => $page->where('admissions.0.doses', null));
        // The doctor at the destination: no valid prescription; the origin's draft is dropped, the new one copies the items.
        $this->actingAs($this->doctorAccount->user)->get(route('ward.show', $this->admission))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active', null)->has('prescriptions.previous', 1)->where('prescriptions.draftId', null)->where('prescriptions.previous.0.canCertify', false));
        $this->post(route('prescriptions.certify', $this->prescription))->assertForbidden();
        $this->post(route('ward.prescriptions.start', $this->admission))->assertRedirect();
        $draft = Prescription::withoutTenantScope()->where('status', 'draft')->sole();
        $this->assertSame([$this->other->id, 3], [$draft->unit_id, PrescriptionItem::withoutTenantScope()->where('prescription_id', $draft->id)->count()]);
        $this->assertSame(1, AuditEvent::where('action', 'prescription.discarded')->count());
    }

    public function test_the_double_check_is_fixed_when_prescribed_and_an_allergy_is_inactivated_by_its_author_or_a_prescriber(): void
    {
        // Unticking "controlled" in the catalog does not drop the second person of a signed prescription.
        $morphine = $this->doses('morfina')[0];
        app(CurrentTenant::class)->set($this->unit->tenant);
        Medication::query()->whereKey($this->items['morfina']->medication_id)->update(['controlled' => false]);
        app(CurrentTenant::class)->set(null);
        $this->actingAs($this->wardNurse->user)->post(route('medication.dose', $morphine), ['outcome' => 'administered', 'reason' => 'Farmácia fechada.'])->assertSessionHasErrors('witness_user_id', null, "dose{$morphine}");
        $this->assertTrue($this->items['morfina']->fresh()->double_check);

        // A nurse of the team inactivates the allergy she recorded, not the doctor's.
        $this->actingAs($this->doctorAccount->user)->post(route('patient-allergies.store', $this->patient), ['substance' => 'Dipirona', 'severity' => 'severe'])->assertSessionHasNoErrors();
        $this->actingAs($this->wardNurse->user)->post(route('patient-allergies.store', $this->patient), ['substance' => 'Látex', 'severity' => 'mild'])->assertSessionHasNoErrors();
        [$doctors, $nurses] = DB::table('patient_allergies')->orderBy('id')->pluck('id')->all();
        $this->get(route('ward.show', $this->admission))->assertInertia(fn (Assert $page) => $page->where('allergyForm.inactivateAny', false));
        $this->post(route('patient-allergies.inactivate', $doctors), ['reason' => 'Engano.'])->assertForbidden();
        $this->post(route('patient-allergies.inactivate', $nurses), ['reason' => 'Registrada no paciente errado.'])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->post(route('patient-allergies.inactivate', $doctors), ['reason' => 'Teste de provocação negativo.'])->assertSessionHasNoErrors();
    }
}
