<?php

namespace Tests\Feature\Clinical;

use App\Jobs\DeliverNotifications;
use App\Models\AuditEvent;
use App\Models\BillingAccountItem;
use App\Models\BillingItem;
use App\Models\EmergencyEpisode;
use App\Models\Material;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\Prescription;
use App\Models\PrescriptionDispensing;
use App\Models\PrescriptionItem;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\StockMovement;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 4b.3a (ADR-0005): the unit's pharmacy reviews each item of the valid prescription, sees only the
 * prescription, the allergies and the patient's identification, and every opening is in the read trail. An
 * approved item is dispensed once for the 24 hours and its return recorded once (step 4b.3b), taking from the unit's
 * stock lots and putting back into them (step 4c.2, ADR-0007).
 */
class PharmacyTest extends ClinicalTestCase
{
    private Visit $visit;

    private Prescription $prescription;

    private Membership $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->forceFill(['council' => 'CRM', 'council_number' => '123456', 'council_uf' => 'SP'])->save();
        $medications = [];
        foreach ([['Dipirona', '500 mg', ['oral']], ['Ceftriaxona', '1 g', ['iv']]] as [$ingredient, $strength, $routes]) {
            $medications[] = Medication::query()->create(['active_ingredient' => $ingredient, 'form' => 'Comprimido', 'strength' => $strength, 'dose_unit' => 'mg', 'routes' => $routes, 'controlled' => false, 'high_alert' => false]);
        }
        app(CurrentTenant::class)->set(null);
        // Step 4c.2: dipyrone has stock; ceftriaxone has none.
        $this->stockLot($this->unit->id, $medications[0]);
        $this->pharmacist = Membership::factory()->for($this->unit->tenant)->create();
        $this->pharmacist->grant(Role::Pharmacy, $this->unit);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Febre.']);
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
        $this->post(route('patient-allergies.store', $this->patient), ['substance' => 'Penicilina', 'severity' => 'severe'])->assertSessionHasNoErrors();
        $this->post(route('visits.prescriptions.start', $this->visit))->assertRedirect();
        $this->prescription = Prescription::withoutTenantScope()->sole();
        foreach ([[$medications[0], 'oral', 500], [$medications[1], 'iv', 1000]] as [$medication, $route, $dose]) {
            $this->post(route('prescriptions.items.store', $this->prescription), ['medication_id' => $medication->id, 'dose' => $dose, 'route' => $route, 'schedule' => 'interval', 'frequency_hours' => 12, 'prn' => '0'])->assertSessionHasNoErrors();
        }
        $this->post(route('prescriptions.sign', $this->prescription), ['password' => 'password'])->assertSessionHasNoErrors();
    }

    public function test_the_pharmacist_opens_only_the_prescription_and_every_opening_is_logged(): void
    {
        $reads = DB::table('clinical_reads')->count();
        $this->actingAs($this->pharmacist->user)->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->component('pharmacy/index')
            ->has('prescriptions', 1)->where('prescriptions.0.patient', 'Joana Prado')->where('prescriptions.0.pending', 2));
        $this->assertSame($reads, DB::table('clinical_reads')->count());

        $this->get(route('pharmacy.show', $this->prescription))->assertInertia(fn (Assert $page) => $page->component('pharmacy/show')
            ->has('items', 2)->where('allergies.0.substance', 'Penicilina')->where('patient', ['name' => 'Joana Prado', 'age' => 36, 'sex' => 'female'])
            ->missing('record')->missing('documents')->missing('drafts'));
        $read = DB::table('clinical_reads')->latest('id')->first();
        $this->assertSame(['pharmacy', $this->prescription->id, $this->pharmacist->user_id], [$read->reason, $read->prescription_id, $read->user_id]);
        // The auditor's access trail labels it as a pharmacy read, not an emergency.
        $auditor = Membership::factory()->for($this->unit->tenant)->create();
        $auditor->grant(Role::Auditor);
        $this->actingAs($auditor->user)->get(route('clinical-access.index'))->assertInertia(fn (Assert $page) => $page->where('reads.0.reason', 'pharmacy'));
        $this->actingAs($this->pharmacist->user);
        // The pharmacy reaches neither the record nor the visit.
        $this->get(route('visits.show', $this->visit))->assertForbidden();
        $this->get(route('clinical-record.show', $this->patient))->assertForbidden();
    }

    public function test_each_item_is_reviewed_once_and_a_refusal_reaches_the_prescriber(): void
    {
        [$dipyrone, $ceftriaxone] = PrescriptionItem::withoutTenantScope()->orderBy('id')->get()->all();
        $this->actingAs($this->pharmacist->user);
        $this->post(route('pharmacy.review', $dipyrone), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->post(route('pharmacy.review', $dipyrone), ['decision' => 'refused', 'note' => 'x'])->assertSessionHasErrors('decision', null, "review{$dipyrone->id}");
        $this->post(route('pharmacy.review', $ceftriaxone), ['decision' => 'refused'])->assertSessionHasErrors('note', null, "review{$ceftriaxone->id}");
        $this->post(route('pharmacy.review', $ceftriaxone), ['decision' => 'refused', 'note' => 'Paciente alérgica a penicilina: risco cruzado.'])->assertSessionHasNoErrors();

        $audit = AuditEvent::where('action', 'prescription_item.reviewed')->latest('id')->firstOrFail();
        $this->assertEquals(['decision' => 'refused', 'prescription_id' => $this->prescription->id, 'author_user_id' => $this->doctorAccount->user_id, 'visit_id' => $this->visit->id], $audit->context);
        (new DeliverNotifications)->handle();
        $notification = DB::table('user_notifications')->where('user_id', $this->doctorAccount->user_id)->where('audit_event_id', $audit->id)->sole();
        $this->assertSame(['A farmácia recusou um item da sua prescrição', "/visits/{$this->visit->id}"], [$notification->title, $notification->url]);

        $this->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->where('prescriptions.0.pending', 0));
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.items.0.review.label', 'Aprovado')
            ->where('prescriptions.active.items.1.review.note', 'Paciente alérgica a penicilina: risco cruzado.'));

        app(CurrentTenant::class)->set($this->unit->tenant);
        try {
            DB::transaction(fn () => DB::table('prescription_reviews')->where('prescription_item_id', $dipyrone->id)->update(['decision' => 'refused', 'note' => 'x']));
            $this->fail('A review changed.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }
    }

    public function test_only_the_units_pharmacy_and_only_the_valid_prescription(): void
    {
        $item = PrescriptionItem::withoutTenantScope()->orderBy('id')->firstOrFail();
        // Pharmacy of another unit, and no pharmacy role at all.
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Pharmacy, $this->other);
        $this->actingAs($elsewhere->user)->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->has('prescriptions', 0));
        $this->get(route('pharmacy.show', $this->prescription))->assertForbidden();
        $this->post(route('pharmacy.review', $item), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->nurseAccount->user)->get(route('pharmacy.index'))->assertForbidden();

        // A suspended item is not reviewed.
        $this->actingAs($this->doctorAccount->user)->post(route('prescription-items.suspend', $item), ['reason' => 'Sem febre.'])->assertSessionHasNoErrors();
        $this->actingAs($this->pharmacist->user)->post(route('pharmacy.review', $item), ['decision' => 'approved'])->assertSessionHasErrors('decision', null, "review{$item->id}");

        // Replaced, expired or with the visit closed, the prescription is out of reach.
        $this->actingAs($this->doctorAccount->user)->post(route('visits.prescriptions.start', $this->visit))->assertRedirect();
        $this->post(route('prescriptions.sign', Prescription::withoutTenantScope()->where('status', 'draft')->sole()), ['password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($this->pharmacist->user)->get(route('pharmacy.show', $this->prescription))->assertForbidden();
        $this->post(route('pharmacy.review', PrescriptionItem::withoutTenantScope()->where('prescription_id', $this->prescription->id)->latest('id')->firstOrFail()), ['decision' => 'approved'])->assertForbidden();
        $newest = Prescription::withoutTenantScope()->latest('id')->firstOrFail();
        $this->get(route('pharmacy.show', $newest))->assertOk();
        $this->travel(25)->hours();
        $this->get(route('pharmacy.show', $newest))->assertForbidden();
        $this->travelBack();

        // The role revoked: nothing.
        $this->pharmacist->roleAssignments()->where('role', Role::Pharmacy)->delete();
        $this->get(route('pharmacy.index'))->assertForbidden();
    }

    public function test_only_an_approved_item_is_dispensed_once_and_returned_once(): void
    {
        [$dipyrone, $ceftriaxone] = PrescriptionItem::withoutTenantScope()->orderBy('id')->get()->all();
        $this->actingAs($this->pharmacist->user);
        $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => 2])->assertSessionHasErrors('quantity', null, "dispense{$dipyrone->id}");
        $this->post(route('pharmacy.review', $dipyrone), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->post(route('pharmacy.review', $ceftriaxone), ['decision' => 'refused', 'note' => 'Risco cruzado.'])->assertSessionHasNoErrors();
        foreach ([0, 501, 'dois'] as $quantity) {
            $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => $quantity])->assertSessionHasErrors('quantity', null, "dispense{$dipyrone->id}");
        }
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Pharmacy, $this->other);
        $this->actingAs($elsewhere->user)->post(route('pharmacy.dispense', $dipyrone), ['quantity' => 2])->assertForbidden();
        $this->actingAs($this->pharmacist->user)->post(route('pharmacy.dispense', $ceftriaxone), ['quantity' => 2])->assertSessionHasErrors('quantity', null, "dispense{$ceftriaxone->id}");
        $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => 2])->assertSessionHasNoErrors();
        $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => 2])->assertSessionHasErrors('quantity', null, "dispense{$dipyrone->id}");
        $this->assertSame(1, DB::table('prescription_dispensings')->count());
        $audit = AuditEvent::where('action', 'prescription_item.dispensed')->sole();
        // Step 4c.2: the lot ids and quantities taken (no lot code or medication name), and the balance moved.
        $lot = DB::table('stock_lots')->where('medication_id', $dipyrone->medication_id)->sole();
        $this->assertEquals(['quantity' => 2, 'prescription_id' => $this->prescription->id, 'visit_id' => $this->visit->id, 'stock_lots' => [$lot->id => 2]], $audit->context);
        $this->assertSame(998, $lot->balance);

        // The doctor and the nursing see what was dispensed.
        $this->actingAs($this->doctorAccount->user)->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.items.0.dispensing.quantity', 2)->where('prescriptions.active.items.1.dispensing', null));

        // Renewed: the old prescription leaves the queue but its dispensing can still be returned, only that.
        $this->post(route('visits.prescriptions.start', $this->visit))->assertRedirect();
        $this->post(route('prescriptions.sign', Prescription::withoutTenantScope()->where('status', 'draft')->sole()), ['password' => 'password'])->assertSessionHasNoErrors();
        $this->actingAs($this->pharmacist->user)->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page
            ->has('prescriptions', 1)->has('returns', 1)->where('returns.0.id', $this->prescription->id));
        $this->get(route('pharmacy.show', $this->prescription))->assertInertia(fn (Assert $page) => $page
            ->where('prescription.current', false)->where('items.0.returnable', true)->where('items.1.returnable', false));
        $this->assertSame(['pharmacy', $this->prescription->id], [DB::table('clinical_reads')->latest('id')->value('reason'), DB::table('clinical_reads')->latest('id')->value('prescription_id')]);
        $this->post(route('pharmacy.review', $ceftriaxone), ['decision' => 'approved'])->assertForbidden();
        $this->post(route('pharmacy.dispense', $ceftriaxone), ['quantity' => 2])->assertForbidden();
        $this->actingAs($elsewhere->user)->get(route('pharmacy.show', $this->prescription))->assertForbidden();
        $this->actingAs($elsewhere->user)->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->has('returns', 0));
        $this->actingAs($this->pharmacist->user);
        $dispensing = PrescriptionDispensing::withoutTenantScope()->sole();
        $this->post(route('pharmacy.return', $dispensing), ['quantity' => 3, 'reason' => 'Sobra.'])->assertSessionHasErrors('quantity', null, "return{$dispensing->id}");
        $this->post(route('pharmacy.return', $dispensing), ['quantity' => 1, 'reason' => 'Prescrição renovada.'])->assertSessionHasNoErrors();
        // Out of the queue and nothing left to return, the prescription is out of reach.
        $this->post(route('pharmacy.return', $dispensing), ['quantity' => 1, 'reason' => 'De novo.'])->assertForbidden();
        $this->assertSame([1, 'Prescrição renovada.'], [$dispensing->fresh()->returned_quantity, $dispensing->fresh()->return_reason]);
        $this->assertEquals(['quantity' => 1, 'prescription_id' => $this->prescription->id, 'visit_id' => $this->visit->id, 'stock_lots' => [$lot->id => 1]], AuditEvent::where('action', 'prescription_item.returned')->sole()->context);
        $this->assertSame(999, DB::table('stock_lots')->where('id', $lot->id)->value('balance'));

        // Returned, the old prescription is out of reach again; nothing of the dispensing changes.
        $this->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->has('returns', 0));
        $this->get(route('pharmacy.show', $this->prescription))->assertForbidden();
        app(CurrentTenant::class)->set($this->unit->tenant);
        try {
            DB::transaction(fn () => DB::table('prescription_dispensings')->where('id', $dispensing->id)->update(['quantity' => 5]));
            $this->fail('A dispensing changed.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->getCode());
        }
        foreach ([
            fn () => DB::table('prescription_dispensings')->where('id', $dispensing->id)->update(['returned_quantity' => 2, 'return_reason' => 'x', 'returned_at' => now()]),
            fn () => DB::table('prescription_dispensings')->where('id', $dispensing->id)->delete(),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('A dispensing changed.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->getCode());
            }
        }
    }

    public function test_the_dispensing_takes_from_the_lots_that_expire_first_and_the_return_puts_back(): void
    {
        [$dipyrone, $ceftriaxone] = PrescriptionItem::withoutTenantScope()->orderBy('id')->get()->all();
        $medication = Medication::withoutTenantScope()->findOrFail($dipyrone->medication_id);
        $later = DB::table('stock_lots')->sole()->id;
        $soon = $this->stockLot($this->unit->id, $medication, 3, 'CEDO', now()->addMonth()->toDateString())->id;
        // Expired by the application's clock and by the database's (the tests travel; the database does not).
        $expired = $this->stockLot($this->unit->id, $medication, 5, 'VENCIDO', '2026-01-01')->id;
        $elsewhere = $this->stockLot($this->other->id, $medication, 50, 'OUTRA')->id;
        app(CurrentTenant::class)->set($this->unit->tenant);
        $billable = BillingItem::query()->create(['kind' => 'medication', 'medication_id' => $medication->id, 'name' => 'Dipirona 500 mg comprimido', 'code' => '90000001']);
        $table = PriceTable::query()->create(['name' => 'Particular', 'private' => true]);
        PriceEntry::query()->create(['price_table_id' => $table->id, 'billing_item_id' => $billable->id, 'price_cents' => 35, 'valid_from' => '2026-01-01', 'created_by_user_id' => $this->manager->user_id, 'created_at' => now()]);
        app(CurrentTenant::class)->set(null);
        $this->actingAs($this->pharmacist->user);
        foreach ([$dipyrone, $ceftriaxone] as $item) {
            $this->post(route('pharmacy.review', $item), ['decision' => 'approved'])->assertSessionHasNoErrors();
        }

        // The screen suggests the valid lots of the unit, the ones that expire first first; never the expired one.
        $this->get(route('pharmacy.show', $this->prescription))->assertInertia(fn (Assert $page) => $page
            ->where('items.0.lots.0.code', 'CEDO')->where('items.0.lots.1.code', 'TESTE1')->has('items.0.lots', 2)
            ->where('items.0.stockUnit', 'unidade')->has('items.1.lots', 0));

        // A chosen expired lot, another unit's lot or more than a lot has is refused, and so is a sum other than the quantity.
        foreach ([[$expired, 1], [$elsewhere, 1], [$soon, 4]] as [$lot, $quantity]) {
            $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => $quantity, 'lots' => [['id' => $lot, 'quantity' => $quantity]]])
                ->assertSessionHasErrors('quantity', null, "dispense{$dipyrone->id}");
        }
        $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => 3, 'lots' => [['id' => $soon, 'quantity' => 1], ['id' => $later, 'quantity' => 1]]])
            ->assertSessionHasErrors('quantity', null, "dispense{$dipyrone->id}");
        // All at zero is no choice (not the suggestion), and a lot sent twice is refused.
        foreach ([[['id' => $soon, 'quantity' => 0]], [['id' => $soon, 'quantity' => 1], ['id' => (string) $soon, 'quantity' => 1]]] as $lots) {
            $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => 2, 'lots' => $lots])->assertSessionHasErrors('quantity', null, "dispense{$dipyrone->id}");
        }
        $this->assertSame(0, DB::table('prescription_dispensings')->count());

        // Without a choice, the suggestion: the lot that expires first, then the next one.
        $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => 5])->assertSessionHasNoErrors();
        $balances = fn () => DB::table('stock_lots')->orderBy('id')->pluck('balance', 'id')->all();
        $this->assertSame([$later => 998, $soon => 0, $expired => 5, $elsewhere => 50], $balances());

        // The return goes back to those lots, up to what each gave, the longest expiry first.
        $dispensing = PrescriptionDispensing::withoutTenantScope()->sole();
        $this->post(route('pharmacy.return', $dispensing), ['quantity' => 4, 'reason' => 'Sobra.'])->assertSessionHasNoErrors();
        $this->assertSame([$later => 1000, $soon => 2, $expired => 5, $elsewhere => 50], $balances());
        // Step 6.2b: the account bills the dispensing and the return (negative), once each, at the price of the day.
        $this->assertSame([['dispensing', 5, '90000001', 35], ['dispensing_return', -4, '90000001', 35]],
            BillingAccountItem::withoutTenantScope()->orderBy('id')->get()->map(fn ($item) => [$item->origin_type, $item->quantity, $item->code, $item->unit_price_cents])->all());
        // The movements report shows them by type only: nothing that leads to the patient (step 4c.4b).
        $this->get(route('stock.movements.index', ['medication' => $dipyrone->medication_id]))->assertInertia(fn (Assert $page) => $page
            ->where('summary.0.dispensing', -5)->where('summary.0.return', 4)
            ->where('movements.0.type', 'Devolução')->where('movements.0.reason', '')
            ->where('movements.0', fn ($row) => array_keys($row->all()) === ['id', 'at', 'medication', 'controlled', 'lot', 'expiresOn', 'type', 'in', 'out', 'reason', 'user']));

        // No stock: refused with the way out on the same screen, the lot entry (question 4); the pharmacy's choice
        // then splits the quantity, a zero leaving a lot out.
        $this->post(route('pharmacy.dispense', $ceftriaxone), ['quantity' => 2])->assertSessionHasErrors(['quantity' => 'Sem saldo deste medicamento no estoque da unidade. Registre a entrada do lote.'], null, "dispense{$ceftriaxone->id}");
        $this->post(route('stock.entries.store'), ['unit_id' => $this->unit->id, 'medication_id' => $ceftriaxone->medication_id, 'lot_code' => 'C1', 'expires_on' => now()->addYear()->toDateString(), 'quantity' => 10])->assertSessionHasNoErrors();
        $c1 = DB::table('stock_lots')->where('lot_code', 'C1')->value('id');
        $this->post(route('pharmacy.dispense', $ceftriaxone), ['quantity' => 1, 'lots' => [['id' => $later, 'quantity' => 1]]])->assertSessionHasErrors(['quantity' => 'Lote fora do estoque deste medicamento na unidade.'], null, "dispense{$ceftriaxone->id}");
        $this->post(route('pharmacy.dispense', $ceftriaxone), ['quantity' => 2, 'lots' => [['id' => $c1, 'quantity' => 2], ['id' => $later, 'quantity' => 0]]])->assertSessionHasNoErrors();
        $this->assertSame(8, DB::table('stock_lots')->where('id', $c1)->value('balance'));
        // A material lot of the same unit, with balance (step 5b.6b): never dispensed.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $gauze = Material::query()->create(['name' => 'Compressa', 'stock_unit' => 'pacote']);
        app(CurrentTenant::class)->set(null);
        $this->post(route('stock.entries.store'), ['unit_id' => $this->unit->id, 'material_id' => $gauze->id, 'lot_code' => 'G1', 'expires_on' => now()->addYear()->toDateString(), 'quantity' => 10])->assertSessionHasNoErrors();
        $material = DB::table('stock_lots')->where('lot_code', 'G1')->value('id');
        // The database keeps a return to what its dispensing took from each lot.
        $ceftriaxoneDispensing = PrescriptionDispensing::withoutTenantScope()->where('prescription_item_id', $ceftriaxone->id)->sole()->id;
        app(CurrentTenant::class)->set($this->unit->tenant);
        // ...and a dispensing to a valid lot of the prescription's unit and of the item's medication.
        $dipyroneDispensing = PrescriptionDispensing::withoutTenantScope()->where('prescription_item_id', $dipyrone->id)->sole()->id;
        foreach ([$elsewhere, $expired, $c1, $material] as $lot) {
            try {
                DB::transaction(fn () => DB::table('stock_movements')->insert(['tenant_id' => $this->unit->tenant_id, 'stock_lot_id' => $lot, 'type' => 'dispensing', 'quantity' => -1, 'user_id' => $this->pharmacist->user_id, 'prescription_dispensing_id' => $dipyroneDispensing, 'created_at' => now()]));
                $this->fail('A dispensing took from a lot it may not.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->getCode());
            }
        }
        foreach ([[$c1, 3], [$later, 1]] as [$lot, $quantity]) {
            try {
                DB::transaction(fn () => DB::table('stock_movements')->insert(['tenant_id' => $this->unit->tenant_id, 'stock_lot_id' => $lot, 'type' => 'return', 'quantity' => $quantity, 'user_id' => $this->pharmacist->user_id, 'prescription_dispensing_id' => $ceftriaxoneDispensing, 'created_at' => now()]));
                $this->fail('A return beyond what was taken was recorded.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->getCode());
            }
        }
        app(CurrentTenant::class)->set(null);

        // The ledger reconciles: every balance is the sum of its movements.
        foreach (DB::table('stock_lots')->get() as $lot) {
            $this->assertSame($lot->balance, (int) DB::table('stock_movements')->where('stock_lot_id', $lot->id)->sum('quantity'));
        }
    }

    public function test_a_return_into_a_lot_expired_in_the_meantime_is_discarded_at_once(): void
    {
        [$dipyrone] = PrescriptionItem::withoutTenantScope()->orderBy('id')->get()->all();
        $today = $this->stockLot($this->unit->id, Medication::withoutTenantScope()->findOrFail($dipyrone->medication_id), 3, 'HOJE', now('America/Sao_Paulo')->toDateString())->id;
        $this->actingAs($this->pharmacist->user)->post(route('pharmacy.review', $dipyrone), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => 2, 'lots' => [['id' => $today, 'quantity' => 2]]])->assertSessionHasNoErrors();

        // The next day the lot is expired: what comes back goes in and out as a discard, and the pharmacy is told.
        $this->travel(1)->days();
        $this->post(route('pharmacy.return', PrescriptionDispensing::withoutTenantScope()->sole()), ['quantity' => 2, 'reason' => 'Sobra.'])
            ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Devolução registrada. 2 voltaram de lote vencido e foram baixadas como descarte: separe-as para descartar.');
        $this->assertSame(1, DB::table('stock_lots')->where('id', $today)->value('balance'));
        $this->assertSame(['discard' => -2, 'dispensing' => -2, 'entry' => 3, 'return' => 2], DB::table('stock_movements')->where('stock_lot_id', $today)->orderBy('type')->pluck('quantity', 'type')->map(fn ($quantity) => (int) $quantity)->all());
        $this->assertSame('expired', DB::table('stock_movements')->where('type', 'discard')->value('reason'));
        $this->assertEquals([$today => 2], AuditEvent::where('action', 'prescription_item.returned')->sole()->context['discarded']);

        // The stock screen keeps the whole history of discards (no patient data), 20 per page, newest first.
        $this->travel(31)->days();
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->has('discards', 1)->where('discardPages', ['newer' => null, 'older' => null])
            ->where('discards.0', fn ($discard) => $discard->only('lot', 'quantity', 'reason')->all() === ['lot' => 'HOJE', 'quantity' => 2, 'reason' => 'expired']));
        $other = $this->stockLot($this->unit->id, Medication::withoutTenantScope()->findOrFail($dipyrone->medication_id), 100, 'OUTRO')->id;
        app(CurrentTenant::class)->set($this->unit->tenant);
        foreach (range(1, 20) as $ignored) {
            StockMovement::create(['stock_lot_id' => $other, 'type' => 'discard', 'quantity' => -1, 'reason' => 'expired', 'user_id' => $this->pharmacist->user_id, 'created_at' => now()]);
        }
        app(CurrentTenant::class)->set(null);
        $this->get(route('stock.index'))->assertInertia(fn (Assert $page) => $page->has('discards', 20)->where('discards.0.lot', 'OUTRO')
            ->where('discardPages.newer', null)->where('discardPages.older', fn ($url) => str_contains((string) $url, 'discards=2')));
        $this->get(route('stock.index', ['discards' => 2]))->assertInertia(fn (Assert $page) => $page->has('discards', 1)->where('discards.0.lot', 'HOJE')
            ->where('discardPages.older', null));
    }

    public function test_a_dispensing_from_before_the_stock_returns_without_a_movement(): void
    {
        [$dipyrone] = PrescriptionItem::withoutTenantScope()->orderBy('id')->get()->all();
        $this->actingAs($this->pharmacist->user)->post(route('pharmacy.review', $dipyrone), ['decision' => 'approved'])->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $dispensing = PrescriptionDispensing::query()->create(['prescription_item_id' => $dipyrone->id, 'quantity' => 4, 'dispensed_by_user_id' => $this->pharmacist->user_id, 'created_at' => now()]);
        app(CurrentTenant::class)->set(null);
        $this->post(route('pharmacy.return', $dispensing), ['quantity' => 2, 'reason' => 'Sobra.'])->assertSessionHasNoErrors();
        $this->assertSame([2, 1, 1000], [$dispensing->fresh()->returned_quantity, DB::table('stock_movements')->count(), DB::table('stock_lots')->sole()->balance]);
    }

    public function test_a_suspended_item_is_not_dispensed_and_a_return_ends_after_48_hours(): void
    {
        [$dipyrone, $ceftriaxone] = PrescriptionItem::withoutTenantScope()->orderBy('id')->get()->all();
        $this->actingAs($this->pharmacist->user);
        foreach ([$dipyrone, $ceftriaxone] as $item) {
            $this->post(route('pharmacy.review', $item), ['decision' => 'approved_with_note', 'note' => 'Conferir função renal.'])->assertSessionHasNoErrors();
        }
        $this->post(route('pharmacy.dispense', $dipyrone), ['quantity' => 2])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->post(route('prescription-items.suspend', $ceftriaxone), ['reason' => 'Sem febre.'])->assertSessionHasNoErrors();
        $this->actingAs($this->pharmacist->user)->post(route('pharmacy.dispense', $ceftriaxone), ['quantity' => 2])->assertSessionHasErrors('quantity', null, "dispense{$ceftriaxone->id}");

        // Another unit's pharmacy reaches neither the dispensing nor its return.
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Pharmacy, $this->other);
        $dispensing = PrescriptionDispensing::withoutTenantScope()->sole();
        $this->actingAs($elsewhere->user)->post(route('pharmacy.return', $dispensing), ['quantity' => 1, 'reason' => 'x'])->assertForbidden();

        // Expired, the prescription opens only to record the return.
        $this->travel(25)->hours();
        $this->actingAs($this->pharmacist->user)->get(route('pharmacy.show', $this->prescription))->assertInertia(fn (Assert $page) => $page->where('prescription.current', false));
        $this->post(route('pharmacy.dispense', $ceftriaxone), ['quantity' => 2])->assertForbidden();
        $this->travel(24)->hours();
        $this->actingAs($this->pharmacist->user)->get(route('pharmacy.show', $this->prescription))->assertForbidden();
        $this->post(route('pharmacy.return', $dispensing), ['quantity' => 1, 'reason' => 'Tarde.'])->assertForbidden();
        $this->get(route('pharmacy.index'))->assertInertia(fn (Assert $page) => $page->has('prescriptions', 0)->has('returns', 0));
    }
}
