<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Medication;
use App\Models\MedicationInteraction;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 4b.2a: the doctor prescribes in an emergency visit (the admission path is in WardTest), every safety alert
 * is justified, signing checks them again and the signed prescription never changes and lasts 24 hours.
 */
class PrescriptionTest extends ClinicalTestCase
{
    private Visit $visit;

    /** @var array<string, Medication> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->forceFill(['council' => 'CRM', 'council_number' => '123456', 'council_uf' => 'SP'])->save();
        foreach ([
            'dipirona' => ['Dipirona', 'Comprimido', '500 mg', 'mg', ['oral'], '1000', '4000'],
            'varfarina' => ['Varfarina', 'Comprimido', '5 mg', 'mg', ['oral'], null, null],
            'aas' => ['Ácido acetilsalicílico', 'Comprimido', '100 mg', 'mg', ['oral'], null, null],
        ] as $key => [$ingredient, $form, $strength, $unit, $routes, $single, $daily]) {
            $this->catalog[$key] = Medication::query()->create([
                'active_ingredient' => $ingredient, 'form' => $form, 'strength' => $strength, 'dose_unit' => $unit, 'routes' => $routes,
                'controlled' => false, 'high_alert' => false, 'max_single_dose' => $single, 'max_daily_dose' => $daily,
            ]);
        }
        MedicationInteraction::query()->create(['ingredient_a' => 'varfarina', 'ingredient_b' => 'ácido acetilsalicílico', 'severity' => 'severe', 'note' => 'Risco de sangramento.', 'created_by_user_id' => $this->manager->user_id]);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dor torácica.']);
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    private function draft(): Prescription
    {
        $this->actingAs($this->doctorAccount->user)->post(route('visits.prescriptions.start', $this->visit))->assertRedirect();

        return Prescription::withoutTenantScope()->where('status', 'draft')->sole();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function add(Prescription $prescription, string $medication, array $data = []): TestResponse
    {
        return $this->post(route('prescriptions.items.store', $prescription), [
            'medication_id' => $this->catalog[$medication]->id, 'dose' => '1', 'route' => 'oral', 'schedule' => 'interval',
            'frequency_hours' => 24, 'prn' => '0', ...$data,
        ]);
    }

    public function test_alerts_are_justified_and_the_signed_prescription_never_changes(): void
    {
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'dipirona', 'severity' => 'severe'])->assertSessionHasNoErrors();
        $prescription = $this->draft();
        $this->post(route('visits.prescriptions.start', $this->visit))->assertRedirect(route('prescriptions.edit', $prescription));
        $this->assertSame(1, Prescription::withoutTenantScope()->count());

        $this->add($prescription, 'varfarina', ['dose' => '5'])->assertSessionHasNoErrors();
        // Interaction with the earlier item: reported on this one, and it must be justified.
        $this->add($prescription, 'aas', ['dose' => '100'])->assertSessionHasErrors('justification');
        $this->add($prescription, 'aas', ['dose' => '100', 'justification' => 'Síndrome coronariana; benefício maior.'])->assertSessionHasNoErrors();
        // Allergy, dose above the single maximum and the daily maximum (1500 mg every 6 h = 6000 mg).
        $this->add($prescription, 'dipirona', ['dose' => '1500', 'frequency_hours' => 6, 'route' => 'iv', 'justification' => 'x'])->assertSessionHasErrors('route');
        $this->add($prescription, 'dipirona', ['dose' => '1500', 'frequency_hours' => 6])->assertSessionHasErrors('justification');
        $this->add($prescription, 'dipirona', ['dose' => '1500', 'schedule' => 'times', 'times' => '8, 20h', 'prn' => '0', 'justification' => 'Alergia questionada pela família.'])->assertSessionHasNoErrors();

        $items = PrescriptionItem::withoutTenantScope()->orderBy('id')->get();
        $this->assertSame([[], 1], [$items[0]->alerts, count($items[1]->alerts)]);
        $this->assertStringContainsString('Interação grave com Varfarina', $items[1]->alerts[0]);
        $this->assertSame(['08:00', '20:00'], $items[2]->times);
        $this->assertSame(['Alergia registrada a dipirona (grave).', 'Dose acima do máximo por administração (1000 mg).'], $items[2]->alerts);
        $this->get(route('prescriptions.edit', $prescription))->assertInertia(fn (Assert $page) => $page->component('prescriptions/edit')
            ->has('items', 3)->where('items.2.pending', [])->where('items.2.schedule', 'às 08:00, 20:00'));

        // Wrong password: not signed. Then signed for 24 hours.
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'errada'])->assertSessionHasErrors('password');
        $this->assertSame(1, AuditEvent::where('action', 'prescription.sign_failed')->count());
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertRedirect(route('visits.show', $this->visit));
        $prescription->refresh();
        $this->assertSame(['signed', 'password', 24.0], [$prescription->status, $prescription->signature_method, $prescription->signed_at?->diffInHours($prescription->valid_until)]);
        $audit = AuditEvent::where('action', 'prescription.signed')->sole();
        // Doses: warfarin and aspirin once a day, dipyrone at 20:00 and 08:00 (unit time).
        $this->assertEquals(['items' => 3, 'alerts' => 2, 'doses' => 4], $audit->context);

        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions.active.items', 3)->where('prescriptions.draftId', null)->where('prescriptions.canPrescribe', true));
        $this->get(route('prescriptions.edit', $prescription))->assertForbidden();
        $this->delete(route('prescription-items.destroy', $items[0]))->assertForbidden();

        // The database refuses any change to the signed prescription or its items.
        app(CurrentTenant::class)->set($this->unit->tenant);
        foreach ([
            fn () => DB::table('prescription_items')->where('id', $items[0]->id)->update(['dose' => 50]),
            fn () => DB::table('prescription_items')->where('id', $items[0]->id)->delete(),
            fn () => DB::table('prescription_items')->insert(collect((array) DB::table('prescription_items')->where('id', $items[0]->id)->first())->except('id')->all()),
            fn () => DB::table('prescriptions')->where('id', $prescription->id)->update(['valid_until' => now()->addDays(9)]),
            fn () => DB::table('prescriptions')->where('id', $prescription->id)->delete(),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('A signed prescription changed.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->getCode());
            }
        }
        app(CurrentTenant::class)->set(null);

        // After 24 hours it is no longer the active one.
        $this->travel(25)->hours();
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active', null)->has('prescriptions.previous', 1));
    }

    public function test_an_alert_that_appears_after_the_item_blocks_signing_until_justified(): void
    {
        $prescription = $this->draft();
        $this->add($prescription, 'dipirona', ['dose' => '500', 'frequency_hours' => 6])->assertSessionHasNoErrors();
        $item = PrescriptionItem::withoutTenantScope()->sole();
        $this->assertSame([], $item->alerts);

        // The allergy is recorded afterwards: signing finds it and refuses.
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Dipirona', 'severity' => 'moderate'])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertSame('draft', $prescription->fresh()->status);
        $this->get(route('prescriptions.edit', $prescription))->assertInertia(fn (Assert $page) => $page->where('items.0.pending', ['Alergia registrada a Dipirona (moderada).']));

        $this->post(route('prescription-items.justify', $item), ['justification' => 'Reação leve antiga; observar.'])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame(['signed', ['Alergia registrada a Dipirona (moderada).']], [$prescription->fresh()->status, $item->fresh()->alerts]);
    }

    public function test_only_a_doctor_with_access_prescribes(): void
    {
        $prescription = $this->draft();
        $this->add($prescription, 'varfarina')->assertSessionHasNoErrors();

        // No access to the patient: nothing.
        $this->actingAs($this->nurseAccount->user);
        $this->post(route('visits.prescriptions.start', $this->visit))->assertForbidden();
        $this->get(route('prescriptions.edit', $prescription))->assertForbidden();
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertForbidden();

        // Without the CRM council the doctor reads but no longer prescribes.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->forceFill(['council' => 'COREN', 'council_number' => '1', 'council_uf' => 'SP'])->save();
        app(CurrentTenant::class)->set(null);
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('prescriptions.canPrescribe', false));
        $this->add($prescription, 'aas')->assertForbidden();

        // Back with the CRM: the visit closes and the draft can no longer be signed.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->forceFill(['council' => 'CRM', 'council_number' => '123456', 'council_uf' => 'SP'])->save();
        app(CurrentTenant::class)->set(null);
        $this->post(route('visits.close', $this->visit), ['outcome' => 'discharged'])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertForbidden();
        $this->assertSame('draft', $prescription->fresh()->status);
    }

    public function test_the_daily_maximum_counts_every_item_and_a_new_prescription_replaces_the_valid_one(): void
    {
        $prescription = $this->draft();
        $this->add($prescription, 'dipirona', ['dose' => '1000', 'frequency_hours' => 6])->assertSessionHasNoErrors();
        // Same ingredient split in a second item: 8 g a day against 4 g, and a repeated ingredient.
        $this->add($prescription, 'dipirona', ['dose' => '1000', 'frequency_hours' => 6])->assertSessionHasErrors('justification');
        $this->add($prescription, 'dipirona', ['dose' => '1000', 'frequency_hours' => 6, 'justification' => 'Esquema de resgate.'])->assertSessionHasNoErrors();
        $this->assertSame(['Dose do dia (8000 mg) acima do máximo por dia (4000 mg).', 'Dipirona já está em outro item desta prescrição.'], PrescriptionItem::withoutTenantScope()->latest('id')->firstOrFail()->alerts);

        // Validation of the schedule and of the start; a justification needs an alert.
        $this->add($prescription, 'varfarina', ['schedule' => 'times', 'times' => '25:00'])->assertSessionHasErrors('times');
        $this->add($prescription, 'varfarina', ['starts_at' => '2030-01-01T08:00'])->assertSessionHasErrors('starts_at');
        $this->add($prescription, 'varfarina', ['prn' => '1', 'prn_condition' => 'Dor', 'schedule' => 'times', 'times' => '08:00'])->assertSessionHasErrors('schedule');
        $first = PrescriptionItem::withoutTenantScope()->orderBy('id')->firstOrFail();
        $this->post(route('prescription-items.justify', $first), ['justification' => 'Nada a justificar.'])->assertSessionHasErrors('justification', null, "justify{$first->id}");
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertSessionHasNoErrors();

        // The next draft is told what it replaces; signing it leaves only its own items active.
        $next = $this->draft();
        $this->get(route('prescriptions.edit', $next))->assertInertia(fn (Assert $page) => $page->where('prescription.replaces', 2)->has('items', 2));
        // It starts as a copy (step 4b.2b); the doctor removes both copies and prescribes only warfarin.
        foreach (PrescriptionItem::withoutTenantScope()->where('prescription_id', $next->id)->get() as $copy) {
            $this->delete(route('prescription-items.destroy', $copy))->assertSessionHasNoErrors();
        }
        $this->add($next, 'varfarina', ['dose' => '5'])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.sign', $next), ['password' => 'password'])->assertSessionHasNoErrors();
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions.active.items', 1)->where('prescriptions.active.items.0.medication', 'Varfarina 5 mg (Comprimido)')->has('prescriptions.previous', 1));
    }

    public function test_a_draft_is_only_its_authors_and_can_be_discarded(): void
    {
        $prescription = $this->draft();
        $this->add($prescription, 'varfarina')->assertSessionHasNoErrors();
        // Someone else's draft on the same visit (written directly): neither it nor its items are reachable.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $foreign = Prescription::query()->create(['patient_id' => $this->patient->id, 'unit_id' => $this->unit->id, 'visit_id' => $this->visit->id, 'author_user_id' => $this->manager->user_id, 'professional_id' => $this->doctor->id, 'created_at' => now()]);
        $foreignItem = PrescriptionItem::query()->create(['prescription_id' => $foreign->id, 'medication_id' => $this->catalog['varfarina']->id, 'medication_label' => 'Varfarina', 'dose' => '5', 'dose_unit' => 'mg', 'route' => 'oral', 'frequency_hours' => 24, 'prn' => false, 'starts_at' => now(), 'alerts' => [], 'created_at' => now()]);
        app(CurrentTenant::class)->set(null);
        $this->get(route('prescriptions.edit', $foreign))->assertForbidden();
        $this->post(route('prescription-items.justify', $foreignItem), ['justification' => 'x'])->assertForbidden();
        $this->delete(route('prescription-items.destroy', $foreignItem))->assertForbidden();
        $this->delete(route('prescriptions.discard', $foreign))->assertForbidden();

        $this->delete(route('prescriptions.discard', $prescription))->assertRedirect(route('visits.show', $this->visit));
        $this->assertSame([$foreign->id], Prescription::withoutTenantScope()->pluck('id')->all());
        $this->assertSame(1, PrescriptionItem::withoutTenantScope()->count());
        $this->assertSame(1, AuditEvent::where('action', 'prescription.discarded')->count());
    }

    public function test_signing_schedules_the_doses_in_the_unit_timezone(): void
    {
        // 12:00 UTC is 09:00 in São Paulo; the prescription runs until 09:00 tomorrow.
        $prescription = $this->draft();
        $this->add($prescription, 'varfarina', ['frequency_hours' => 8])->assertSessionHasNoErrors();
        $this->add($prescription, 'aas', ['schedule' => 'times', 'times' => '08:00, 20:00', 'justification' => 'Benefício maior.'])->assertSessionHasNoErrors();
        $this->add($prescription, 'dipirona', ['dose' => '500', 'prn' => '1', 'prn_condition' => 'Dor', 'frequency_hours' => 6])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertSessionHasNoErrors();

        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.items.0.doses', ['13/11 09:00', '13/11 17:00', '14/11 01:00'])
            ->where('prescriptions.active.items.1.doses', ['13/11 20:00', '14/11 08:00'])
            ->where('prescriptions.active.items.2.doses', []));
        $this->assertSame(5, AuditEvent::where('action', 'prescription.signed')->sole()->context['doses']);

        app(CurrentTenant::class)->set($this->unit->tenant);
        try {
            DB::transaction(fn () => DB::table('prescription_doses')->limit(1)->update(['scheduled_at' => now()->addDay()]));
            $this->fail('A scheduled dose changed.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }
    }

    public function test_an_item_is_suspended_once_and_the_next_draft_copies_the_rest(): void
    {
        $prescription = $this->draft();
        $this->add($prescription, 'varfarina', ['dose' => '5'])->assertSessionHasNoErrors();
        $this->add($prescription, 'aas', ['dose' => '100', 'justification' => 'Síndrome coronariana.'])->assertSessionHasNoErrors();
        $this->add($prescription, 'dipirona', ['dose' => '500', 'frequency_hours' => 6])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertSessionHasNoErrors();
        [$warfarin, $aspirin, $dipyrone] = PrescriptionItem::withoutTenantScope()->orderBy('id')->get()->all();

        // Suspension: with a reason, once, only by a prescriber with access.
        $this->post(route('prescription-items.suspend', $dipyrone), [])->assertSessionHasErrors('reason', null, "suspend{$dipyrone->id}");
        $this->post(route('prescription-items.suspend', $dipyrone), ['reason' => 'Sem dor há 24 horas.'])->assertSessionHasNoErrors();
        $this->post(route('prescription-items.suspend', $dipyrone), ['reason' => 'De novo.'])->assertSessionHasErrors('reason', null, "suspend{$dipyrone->id}");
        $this->actingAs($this->nurseAccount->user)->post(route('prescription-items.suspend', $warfarin), ['reason' => 'x'])->assertForbidden();
        $this->assertSame([null, 'Sem dor há 24 horas.'], [$warfarin->fresh()->suspended_at, $dipyrone->fresh()->suspension_reason]);
        $this->assertSame(1, AuditEvent::where('action', 'prescription_item.suspended')->count());
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.items.2.suspensionReason', 'Sem dor há 24 horas.')->where('prescriptions.canSuspend', true));
        app(CurrentTenant::class)->set($this->unit->tenant);
        try {
            DB::transaction(fn () => DB::table('prescription_items')->where('id', $warfarin->id)->update(['suspended_at' => now(), 'suspended_by_user_id' => $this->doctorAccount->user_id, 'suspension_reason' => 'x', 'dose' => 50]));
            $this->fail('A signed item changed along with its suspension.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }
        app(CurrentTenant::class)->set(null);

        // The next day's draft starts as a copy, without the suspended item, keeping the justification that still covers.
        $this->travel(20)->hours();
        $next = $this->draft();
        $copies = PrescriptionItem::withoutTenantScope()->where('prescription_id', $next->id)->orderBy('id')->get();
        $this->assertSame([$warfarin->medication_id, $aspirin->medication_id], $copies->pluck('medication_id')->all());
        $this->assertSame([null, 'Síndrome coronariana.'], $copies->pluck('justification')->all());
        // Warfarin, once a day since 12:00 UTC: the copy keeps 12:00 of the next day, not "now" (08:00).
        $this->assertSame('2026-11-14 12:00', $copies[0]->starts_at->utc()->format('Y-m-d H:i'));
        $this->assertSame(['copied_items' => 2], AuditEvent::where('action', 'prescription.created')->latest('id')->firstOrFail()->context);
        $this->get(route('prescriptions.edit', $next))->assertInertia(fn (Assert $page) => $page->has('items', 2)->where('items.1.pending', []));
        $this->post(route('prescriptions.sign', $next), ['password' => 'password'])->assertSessionHasNoErrors();

        // Only items of the active prescription are suspended.
        $this->post(route('prescription-items.suspend', $warfarin), ['reason' => 'Tarde demais.'])->assertForbidden();
    }
}
