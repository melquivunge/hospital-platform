<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\BillingAccountItem;
use App\Models\BillingItem;
use App\Models\Insurer;
use App\Models\InsurerContract;
use App\Models\Membership;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\Unit;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 6.2a (ADR-0010): the account opens on the arrival with the stay's modality and card, and receives one item per
 * care record, priced by the table of the day and frozen; generating again, or at the same time, adds nothing twice.
 */
class BillingAccountTest extends ClinicalTestCase
{
    private Insurer $insurer;

    private BillingItem $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->unit->tenant;
        app(CurrentTenant::class)->set($tenant);
        $private = PriceTable::query()->create(['name' => 'Particular', 'private' => true]);
        $insurance = PriceTable::query()->create(['name' => 'Convênios']);
        $this->consultation = BillingItem::query()->create(['kind' => 'consultation', 'name' => 'Consulta em consultório', 'code' => '10101012']);
        foreach ([[$private, 15000], [$insurance, 10000]] as [$table, $cents]) {
            PriceEntry::query()->create(['price_table_id' => $table->id, 'billing_item_id' => $this->consultation->id, 'price_cents' => $cents, 'valid_from' => '2026-01-01', 'created_by_user_id' => $this->manager->user_id, 'created_at' => now()]);
        }
        $this->insurer = Insurer::query()->create(['name' => 'Saúde Boa', 'ans_code' => '123456']);
        $contract = InsurerContract::query()->create(['insurer_id' => $this->insurer->id, 'price_table_id' => $insurance->id, 'adjustment_bp' => 1050]);
        DB::table('insurer_contract_authorizations')->insert(['tenant_id' => $tenant->id, 'insurer_contract_id' => $contract->id, 'billing_item_id' => $this->consultation->id]);
        app(CurrentTenant::class)->set(null);
    }

    /**
     * @param  array<string, mixed>  $modality
     */
    private function closedConsultation(array $modality, string $time = '08:20'): BillingAccount
    {
        $appointment = $this->arrived($this->doctor, $this->unit, $time, $modality);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment))->assertRedirect();
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()))->assertSessionHasNoErrors();

        return BillingAccount::withoutTenantScope()->where('appointment_id', $appointment->id)->sole();
    }

    public function test_a_consultation_by_insurer_bills_its_visit_once_at_the_contract_price(): void
    {
        $account = $this->closedConsultation(['modality' => 'insurance', 'insurer_id' => $this->insurer->id, 'insurance_card_number' => '0012 3456', 'insurance_card_valid_until' => '2027-03-31']);
        $this->assertSame(['open', 'insurance', $this->insurer->id, '0012 3456', $this->unit->id, $this->patient->id],
            [$account->status, $account->modality, $account->insurer_id, $account->insurance_card_number, $account->unit_id, $account->patient_id]);

        // 100,00 in the insurers' table, +10.5% in the contract; the contract asks for an authorization.
        $item = BillingAccountItem::withoutTenantScope()->sole();
        $this->assertSame(['visit', Visit::withoutTenantScope()->sole()->id, $this->consultation->id, '10101012', 'Consulta em consultório', 1, 11050, true, '2026-11-16'],
            [$item->origin_type, $item->origin_id, $item->billing_item_id, $item->code, $item->name, $item->quantity, $item->unit_price_cents, $item->needs_authorization, $item->performed_on->toDateString()]);

        // Generating again adds nothing, and a new price does not change the item: it is frozen.
        app(CurrentTenant::class)->set($this->unit->tenant);
        PriceEntry::query()->where('billing_item_id', $this->consultation->id)->update(['valid_until' => '2026-11-30']);
        $this->assertSame(0, $account->generate());
        $this->assertSame(11050, BillingAccountItem::query()->sole()->unit_price_cents);

        // The item is never changed nor deleted.
        foreach ([fn () => DB::table('billing_account_items')->update(['unit_price_cents' => 1]), fn () => DB::table('billing_account_items')->delete()] as $write) {
            try {
                DB::transaction($write);
                $this->fail('The database must refuse it.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('never changed nor deleted', $e->getMessage());
            }
        }

        // Another client sees neither the account nor its items (forced RLS).
        app(CurrentTenant::class)->set(null);
        app(CurrentTenant::class)->set(Membership::factory()->withRole(Role::NetworkAdmin)->create()->tenant);
        $this->assertSame([0, 0], [DB::table('billing_accounts')->count(), DB::table('billing_account_items')->count()]);
    }

    public function test_a_private_consultation_uses_the_private_table_and_sus_waits_for_its_delivery(): void
    {
        $first = $this->closedConsultation(['modality' => 'private']);
        $item = BillingAccountItem::withoutTenantScope()->sole();
        $this->assertSame([15000, false], [$item->unit_price_cents, $item->needs_authorization]);

        // The patient's next consultation has its own account: each bills its own visit only.
        $this->travelTo('2026-11-13 12:00:00');
        $second = $this->closedConsultation(['modality' => 'private'], '09:00');
        $visits = Visit::withoutTenantScope()->orderBy('id')->pluck('id')->all();
        $this->assertSame([[$first->id, $visits[0]], [$second->id, $visits[1]]],
            BillingAccountItem::withoutTenantScope()->orderBy('id')->get()->map(fn ($row) => [$row->billing_account_id, $row->origin_id])->all());

        // SUS (delivery 6b): the account opens, with no items.
        $this->travelTo('2026-11-13 12:00:00');
        $account = $this->closedConsultation(['modality' => 'sus'], '09:20');
        $this->assertSame('sus', $account->modality);
        $this->assertSame(0, BillingAccountItem::withoutTenantScope()->where('billing_account_id', $account->id)->count());
    }

    public function test_an_emergency_visit_without_a_billable_item_is_pending(): void
    {
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'private'])->assertSessionHasNoErrors();
        $account = BillingAccount::withoutTenantScope()->sole();
        $this->assertNotNull($account->emergency_episode_id);
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $account->emergency_episode_id), ['level' => 3, 'complaint' => 'Febre.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $account->emergency_episode_id))->assertSessionHasNoErrors();
        $this->post(route('visits.close', Visit::withoutTenantScope()->sole()), ['outcome' => 'discharged'])->assertSessionHasNoErrors();

        // No billable item for an emergency consultation: the item exists with the catalog name and no price.
        $item = BillingAccountItem::withoutTenantScope()->sole();
        $this->assertSame([$account->id, null, null, 'Consulta de urgência', null], [$item->billing_account_id, $item->billing_item_id, $item->code, $item->name, $item->unit_price_cents]);
    }

    public function test_billing_of_the_unit_opens_the_account_without_the_record_and_each_opening_is_logged(): void
    {
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Billing, $this->other);
        $stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $stranger->grant(Role::Billing, Unit::factory()->for($stranger->tenant)->create());
        // An emergency stay by insurer whose visit was billed while it was SUS: nothing billed until it is opened.
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, 'modality' => 'sus'])->assertSessionHasNoErrors();
        $account = BillingAccount::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $account->emergency_episode_id), ['level' => 3, 'complaint' => 'Queixa sigilosa.'])->assertSessionHasNoErrors();
        $this->post(route('emergency.call', $account->emergency_episode_id))->assertSessionHasNoErrors();
        $this->post(route('visits.close', Visit::withoutTenantScope()->sole()), ['outcome' => 'discharged'])->assertSessionHasNoErrors();
        app(CurrentTenant::class)->set($this->unit->tenant);
        DB::table('billing_accounts')->update(['modality' => 'private']);
        app(CurrentTenant::class)->set(null);

        // The list: identification and totals, no read logged.
        $this->actingAs($billing->user)->get(route('billing-accounts.index'))->assertInertia(fn (Assert $page) => $page->component('billing-accounts/index')
            ->has('accounts', 1)->where('accounts.0.patient', 'Joana Prado')->where('accounts.0.stay', 'Urgência')->where('accounts.0.modality', 'Particular')
            ->where('accounts.0.items', 0)->where('accounts.0.totalCents', 0));
        $this->get(route('billing-accounts.index', ['pending' => 1]))->assertInertia(fn (Assert $page) => $page->has('accounts', 0));
        $this->assertSame(0, DB::table('clinical_reads')->where('reason', 'billing')->count());

        // Opening it bills what was missing (the emergency visit, without a billable item: pending) and logs the read.
        $response = $this->get(route('billing-accounts.show', $account))->assertInertia(fn (Assert $page) => $page->component('billing-accounts/show')
            ->where('account.patient', 'Joana Prado')->where('account.pending', 1)->has('items', 1)
            ->where('items.0.origin', 'visit')->where('items.0.name', 'Consulta de urgência')->where('items.0.unitPriceCents', null));
        $this->assertStringNotContainsString('Queixa sigilosa', $response->getContent());
        app(CurrentTenant::class)->set($this->unit->tenant);
        $read = DB::table('clinical_reads')->where('reason', 'billing')->sole();
        $this->assertSame([$billing->user_id, $this->patient->id, $account->id], [$read->user_id, $read->patient_id, $read->billing_account_id]);
        app(CurrentTenant::class)->set(null);
        $this->get(route('billing-accounts.index', ['pending' => 1]))->assertInertia(fn (Assert $page) => $page->has('accounts', 1)->where('accounts.0.pending', 1));
        // A compensated item no longer holds the account (step 6.3a).
        $this->post(route('billing-account-items.compensate', BillingAccountItem::withoutTenantScope()->sole()), ['reason' => 'Atendimento não cobrado.'])->assertSessionHasNoErrors();
        $this->get(route('billing-accounts.index', ['pending' => 1]))->assertInertia(fn (Assert $page) => $page->has('accounts', 0));
        $this->get(route('billing-accounts.index'))->assertInertia(fn (Assert $page) => $page->where('accounts.0.pending', 0)->where('accounts.0.items', 2));

        // Billing of another unit, another client, or without the role: nothing.
        $this->actingAs($elsewhere->user)->get(route('billing-accounts.show', $account))->assertForbidden();
        $this->get(route('billing-accounts.index'))->assertInertia(fn (Assert $page) => $page->has('accounts', 0));
        // Patients' names stay out of the browser's history in plain text.
        $this->get(route('billing-accounts.index'))->assertSee('"encryptHistory":true}', false);
        $elsewhere->revoke();
        $this->get(route('billing-accounts.index'))->assertForbidden();
        $this->actingAs($stranger->user)->get(route('billing-accounts.show', $account))->assertNotFound();
        $this->actingAs($this->doctorAccount->user)->get(route('billing-accounts.index'))->assertForbidden();
        $this->actingAs($this->manager->user)->get(route('billing-accounts.show', $account))->assertForbidden();
        // The network admin bills every unit of the network (user's decision, 27/10/2026); the opening is logged too.
        $admin = Membership::query()->withoutGlobalScopes()->where('tenant_id', $this->unit->tenant_id)->whereHas('roleAssignments', fn ($query) => $query->where('role', Role::NetworkAdmin->value))->firstOrFail();
        $this->actingAs($admin->user)->get(route('billing-accounts.show', $account))->assertOk();
        $this->assertSame(2, DB::table('clinical_reads')->where('reason', 'billing')->count());
        $this->actingAs($billing->user);
        $billing->roleAssignments()->where('role', Role::Billing)->delete();
        $this->actingAs($billing->user)->get(route('billing-accounts.index'))->assertForbidden();
        $this->get(route('billing-accounts.show', $account))->assertForbidden();
        $this->assertSame(2, DB::table('clinical_reads')->where('reason', 'billing')->count());
    }

    public function test_billing_adds_a_manual_item_and_compensates_an_item_once_with_the_reason(): void
    {
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Billing, $this->other);
        $account = $this->closedConsultation(['modality' => 'private']);
        $visitItem = BillingAccountItem::withoutTenantScope()->sole();
        $add = fn (array $data = []) => $this->post(route('billing-accounts.items.store', $account),
            [...['billing_item_id' => $this->consultation->id, 'performed_on' => '2026-11-16', 'quantity' => 2, 'reason' => 'Retorno cobrado no mesmo dia.'], ...$data]);

        // Only billing of the account's unit.
        $this->actingAs($elsewhere->user);
        $add()->assertForbidden();
        $this->post(route('billing-account-items.compensate', $visitItem), ['reason' => 'Erro.'])->assertForbidden();

        $this->actingAs($billing->user);
        $add(['reason' => ' '])->assertSessionHasErrorsIn('item', ['reason']);
        $add(['performed_on' => '2026-11-17'])->assertSessionHasErrorsIn('item', ['performed_on' => 'A data não pode estar no futuro.']);
        $add(['performed_on' => '2026-11-15'])->assertSessionHasErrorsIn('item', ['performed_on' => 'A data não pode ser anterior à abertura da conta.']);
        $add(['quantity' => 0])->assertSessionHasErrorsIn('item', ['quantity']);
        $add()->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Item incluído.');
        $manual = BillingAccountItem::withoutTenantScope()->where('origin_type', 'manual')->sole();
        $this->assertSame([null, 2, 15000, 'Retorno cobrado no mesmo dia.', $billing->user_id], [$manual->origin_id, $manual->quantity, $manual->unit_price_cents, $manual->reason, $manual->author_user_id]);
        $this->assertEquals(['billing_account_id' => $account->id, 'billing_item_id' => $this->consultation->id, 'quantity' => 2, 'unit_price_cents' => 15000],
            AuditEvent::where('action', 'billing_item.added')->sole()->context);

        // The visit's item is compensated once: a negative item at its quantity and price, with the reason.
        $this->post(route('billing-account-items.compensate', $visitItem), ['reason' => ' '])->assertSessionHasErrorsIn("compensate{$visitItem->id}", ['reason']);
        $this->post(route('billing-account-items.compensate', $visitItem), ['reason' => 'Consulta não realizada.'])->assertSessionHasNoErrors();
        $this->post(route('billing-account-items.compensate', $visitItem), ['reason' => 'De novo.'])
            ->assertSessionHasErrorsIn("compensate{$visitItem->id}", ['reason' => 'Este item já foi estornado.']);
        $compensation = BillingAccountItem::withoutTenantScope()->where('origin_type', 'compensation')->sole();
        $this->assertSame([$visitItem->id, -1, 15000], [$compensation->origin_id, $compensation->quantity, $compensation->unit_price_cents]);
        $this->post(route('billing-account-items.compensate', $compensation), ['reason' => 'Desfazer.'])
            ->assertSessionHasErrorsIn("compensate{$compensation->id}", ['reason' => 'Um estorno não é estornado.']);

        // Generating again does not bring the visit back; the screen shows who and why, and the total.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertSame(0, $account->generate());
        // The database keeps a compensation to one item of its account, at its quantity and price.
        try {
            DB::transaction(fn () => DB::table('billing_account_items')->insert([...collect($compensation->getAttributes())->except('id')->all(), 'origin_id' => $manual->id, 'quantity' => -1]));
            $this->fail('The database must refuse it.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('a compensation undoes one item', $e->getMessage());
        }
        app(CurrentTenant::class)->set(null);
        $this->get(route('billing-accounts.show', $account))->assertInertia(fn (Assert $page) => $page
            ->where('account.totalCents', 30000)->where('canChange', true)->has('items', 3)
            ->where('items.0.compensated', true)->where('items.1.author', $billing->user->name)->where('items.2.reason', 'Consulta não realizada.'));

        // A manual item is compensated too, at its quantity and price.
        $this->post(route('billing-account-items.compensate', $manual), ['reason' => 'Retorno não cobrado.'])->assertSessionHasNoErrors();
        $this->assertSame([-2, 15000], [BillingAccountItem::withoutTenantScope()->where('origin_type', 'compensation')->where('origin_id', $manual->id)->value('quantity'),
            BillingAccountItem::withoutTenantScope()->where('origin_type', 'compensation')->where('origin_id', $manual->id)->value('unit_price_cents')]);

        // Billing of another client finds neither the account nor the item.
        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $stranger->grant(Role::Billing, Unit::factory()->for($stranger->tenant)->create());
        $this->actingAs($stranger->user)->post(route('billing-accounts.items.store', $account), ['billing_item_id' => $this->consultation->id, 'performed_on' => '2026-11-16', 'quantity' => 1, 'reason' => 'X.'])->assertNotFound();
        $this->post(route('billing-account-items.compensate', $visitItem), ['reason' => 'X.'])->assertNotFound();

        // A SUS account takes no change here (delivery 6b).
        $this->travelTo('2026-11-13 12:00:00');
        $sus = $this->closedConsultation(['modality' => 'sus'], '09:00');
        $this->actingAs($billing->user)->post(route('billing-accounts.items.store', $sus), ['billing_item_id' => $this->consultation->id, 'performed_on' => '2026-11-16', 'quantity' => 1, 'reason' => 'Teste.'])
            ->assertSessionHasErrorsIn('item', ['item']);
    }

    public function test_billing_records_the_insurers_answer_by_item_or_guide_and_only_authorized_or_urgency_clears_it(): void
    {
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $account = $this->closedConsultation(['modality' => 'insurance', 'insurer_id' => $this->insurer->id, 'insurance_card_number' => '0012 3456', 'insurance_card_valid_until' => '2027-03-31']);
        $visitItem = BillingAccountItem::withoutTenantScope()->sole();
        $pending = fn () => $this->get(route('billing-accounts.index'))->assertInertia(fn (Assert $page) => $page)->viewData('page')['props']['accounts'][0]['pending'];
        $answer = fn (array $data) => $this->post(route('billing-accounts.authorizations.store', $account), $data);

        $this->actingAs($billing->user);
        $this->assertSame(1, $pending());
        $answer(['status' => 'authorized', 'number' => 'G-1', 'valid_until' => '2026-12-31'])->assertSessionHasErrorsIn('authorization', ['item_ids']);
        $answer(['item_ids' => [$visitItem->id], 'status' => 'authorized', 'valid_until' => '2026-12-31'])->assertSessionHasErrorsIn('authorization', ['number']);
        $answer(['item_ids' => [$visitItem->id], 'status' => 'denied'])->assertSessionHasErrorsIn('authorization', ['reason']);
        $answer(['item_ids' => [$visitItem->id], 'status' => 'authorized', 'number' => 'G-1', 'valid_until' => '2026-11-15'])
            ->assertSessionHasErrorsIn('authorization', ['valid_until' => 'A validade não cobre a data de todos os itens escolhidos.']);
        $answer(['item_ids' => [$visitItem->id + 999], 'status' => 'denied', 'reason' => 'X.'])
            ->assertSessionHasErrorsIn('authorization', ['item_ids' => 'Escolha itens desta conta que exigem autorização do convênio.']);

        // Denied: still pending; then authorized on appeal: cleared. The latest answer is the state; both stay.
        $answer(['item_ids' => [$visitItem->id], 'status' => 'denied', 'reason' => 'Carência do plano.'])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Negativa registrada.');
        $this->assertSame(1, $pending());
        $answer(['item_ids' => [$visitItem->id], 'status' => 'authorized', 'number' => 'G-77', 'valid_until' => '2026-12-31'])->assertSessionHasNoErrors();
        $this->assertSame(0, $pending());

        // A guide covers several items at once; urgency clears one without authorization, with the reason.
        $this->post(route('billing-accounts.items.store', $account), ['billing_item_id' => $this->consultation->id, 'performed_on' => '2026-11-16', 'quantity' => 1, 'reason' => 'Retorno.'])->assertSessionHasNoErrors();
        $manual = BillingAccountItem::withoutTenantScope()->where('origin_type', 'manual')->sole();
        $this->assertTrue($manual->needs_authorization);
        $this->assertSame(1, $pending());
        $answer(['item_ids' => [$manual->id], 'status' => 'urgency', 'reason' => 'Atendimento de urgência.'])->assertSessionHasNoErrors();
        $this->assertSame(0, $pending());
        $this->get(route('billing-accounts.show', $account))->assertInertia(fn (Assert $page) => $page
            ->where('items.0.authorization.status', 'authorized')->where('items.0.authorization.number', 'G-77')->where('items.0.authorization.validUntil', '31/12/2026')
            ->where('items.1.authorization.status', 'urgency')->where('items.1.authorization.reason', 'Atendimento de urgência.'));
        $this->assertEquals(['item_ids' => [$manual->id], 'status' => 'urgency'], AuditEvent::where('action', 'billing_item.authorization')->latest('id')->first()->context);

        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertSame(3, DB::table('billing_authorizations')->count());
        try {
            DB::transaction(fn () => DB::table('billing_authorizations')->update(['status' => 'denied', 'number' => null, 'valid_until' => null, 'reason' => 'X']));
            $this->fail('The database must refuse it.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('insert-only', $e->getMessage());
        }
        app(CurrentTenant::class)->set(null);

        // Billing of another unit cannot answer; a compensated item is no longer a target.
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Billing, $this->other);
        $this->actingAs($elsewhere->user)->post(route('billing-accounts.authorizations.store', $account), ['item_ids' => [$manual->id], 'status' => 'urgency', 'reason' => 'X.'])->assertForbidden();
        $this->actingAs($billing->user)->post(route('billing-account-items.compensate', $manual), ['reason' => 'Lançado errado.'])->assertSessionHasNoErrors();
        $answer(['item_ids' => [$manual->id], 'status' => 'denied', 'reason' => 'X.'])->assertSessionHasErrorsIn('authorization', ['item_ids']);
        $answer(['item_ids' => [$visitItem->id], 'status' => 'approved'])->assertSessionHasErrorsIn('authorization', ['status']);

        // A private account has no insurer to answer.
        $this->travelTo('2026-11-13 12:00:00');
        $private = $this->closedConsultation(['modality' => 'private'], '09:00');
        $item = BillingAccountItem::withoutTenantScope()->where('billing_account_id', $private->id)->sole();
        $this->actingAs($billing->user)->post(route('billing-accounts.authorizations.store', $private), ['item_ids' => [$item->id], 'status' => 'urgency', 'reason' => 'X.'])
            ->assertSessionHasErrorsIn('authorization', ['item_ids']);
    }

    public function test_billing_closes_once_after_the_stay_and_without_pending_items_and_the_closed_account_never_changes(): void
    {
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $appointment = $this->arrived($this->doctor, $this->unit, '08:20', ['modality' => 'insurance', 'insurer_id' => $this->insurer->id, 'insurance_card_number' => '0012 3456', 'insurance_card_valid_until' => '2027-03-31']);
        $account = BillingAccount::withoutTenantScope()->where('appointment_id', $appointment->id)->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment))->assertRedirect();
        $close = fn () => $this->actingAs($billing->user)->post(route('billing-accounts.close', $account));

        // While the visit is open, the stay has not ended.
        $close()->assertSessionHasErrorsIn('close', ['close' => 'O atendimento ainda não terminou: a conta fecha depois do encerramento ou da alta.']);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.close', Visit::withoutTenantScope()->sole()))->assertSessionHasNoErrors();
        // The consultation needs the insurer's authorization: pending until answered.
        $close()->assertSessionHasErrorsIn('close', ['close' => 'Resolva as pendências antes de fechar: itens sem preço ou sem autorização do convênio.']);
        $item = BillingAccountItem::withoutTenantScope()->sole();
        $this->post(route('billing-accounts.authorizations.store', $account), ['item_ids' => [$item->id], 'status' => 'authorized', 'number' => 'G-9', 'valid_until' => '2026-12-31'])->assertSessionHasNoErrors();
        $close()->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Conta fechada.');
        $close()->assertSessionHasErrorsIn('close', ['close' => 'Esta conta já foi fechada.']);
        $account = $account->fresh();
        $this->assertSame(['closed', $billing->user_id], [$account->status, $account->closed_by_user_id]);
        $this->assertEquals(['unit_id' => $this->unit->id, 'total_cents' => 11050, 'complement_of_id' => null], AuditEvent::where('action', 'billing_account.closed')->sole()->context);

        // The closed account takes nothing: no manual item, no answer, no change in the database.
        $this->post(route('billing-accounts.items.store', $account), ['billing_item_id' => $this->consultation->id, 'performed_on' => '2026-11-16', 'quantity' => 1, 'reason' => 'X.'])->assertSessionHasErrorsIn('item', ['item']);
        app(CurrentTenant::class)->set($this->unit->tenant);
        foreach ([
            fn () => DB::table('billing_accounts')->where('id', $account->id)->update(['modality' => 'private', 'insurer_id' => null, 'insurance_card_number' => null, 'insurance_card_valid_until' => null]),
            fn () => DB::table('billing_account_items')->insert([...collect($item->getAttributes())->except('id')->all(), 'origin_type' => 'manual', 'origin_id' => null, 'reason' => 'X', 'author_user_id' => $billing->user_id]),
            fn () => DB::table('billing_authorizations')->insert(['tenant_id' => $item->tenant_id, 'billing_account_item_id' => $item->id, 'status' => 'urgency', 'reason' => 'X', 'author_user_id' => $billing->user_id, 'created_at' => now()]),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('The database must refuse it.');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/closed account/', $e->getMessage());
            }
        }
        app(CurrentTenant::class)->set(null);

        // A correction after the close: the compensation goes to a complementary account, which closes on its own.
        $this->post(route('billing-account-items.compensate', $item), ['reason' => 'Glosa prevista.'])->assertSessionHasNoErrors();
        $complement = BillingAccount::withoutTenantScope()->where('complement_of_id', $account->id)->sole();
        $this->assertEquals(['unit_id' => $this->unit->id, 'complement_of_id' => $account->id], AuditEvent::where('action', 'billing_account.complement_opened')->sole()->context);
        $this->assertSame(['open', 'insurance', '0012 3456'], [$complement->status, $complement->modality, $complement->insurance_card_number]);
        $this->assertSame($complement->id, BillingAccountItem::withoutTenantScope()->where('origin_type', 'compensation')->sole()->billing_account_id);
        $this->get(route('billing-accounts.show', $complement))->assertInertia(fn (Assert $page) => $page
            ->where('account.complementOf', $account->id)->where('account.stay', 'Consulta')->where('canClose', true)->where('complements', [$complement->id]));
        $this->post(route('billing-accounts.close', $complement))->assertSessionHasNoErrors();
        $this->get(route('billing-accounts.index', ['closed' => 1]))->assertInertia(fn (Assert $page) => $page->has('accounts', 2));
        $this->get(route('billing-accounts.index'))->assertInertia(fn (Assert $page) => $page->has('accounts', 0));
    }

    public function test_a_closed_private_account_is_received_once_and_its_receipt_is_printed(): void
    {
        $billing = Membership::factory()->for($this->unit->tenant)->create();
        $billing->grant(Role::Billing, $this->unit);
        $account = $this->closedConsultation(['modality' => 'private']);
        $receive = fn (array $data = ['method' => 'pix']) => $this->post(route('billing-accounts.payment.store', $account), $data);

        $this->actingAs($billing->user);
        $receive()->assertSessionHasErrorsIn('payment', ['method' => 'O recebimento é registrado numa conta particular fechada.']);
        $this->get(route('billing-accounts.receipt', $account))->assertNotFound();
        $this->post(route('billing-accounts.close', $account))->assertSessionHasNoErrors();
        $receive(['method' => 'cheque'])->assertSessionHasErrorsIn('payment', ['method']);
        $receive()->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Recebimento registrado.');
        $receive(['method' => 'cash'])->assertSessionHasErrorsIn('payment', ['method' => 'O recebimento desta conta já foi registrado.']);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $payment = DB::table('billing_payments')->sole();
        $this->assertSame([15000, 'pix', $billing->user_id], [(int) $payment->amount_cents, $payment->method, $payment->received_by_user_id]);
        try {
            DB::transaction(fn () => DB::table('billing_payments')->update(['method' => 'cash']));
            $this->fail('The database must refuse it.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('never changed', $e->getMessage());
        }
        app(CurrentTenant::class)->set(null);
        $this->get(route('billing-accounts.show', $account))->assertInertia(fn (Assert $page) => $page
            ->where('payment.method', 'Pix')->where('payment.amountCents', 15000)->where('canReceive', false));

        // The receipt: a PDF, logged as a read of the account and in the audit (ids only).
        $reads = DB::table('clinical_reads')->where('reason', 'billing')->count();
        $this->get(route('billing-accounts.receipt', $account))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Disposition', "attachment; filename=\"recibo-{$payment->id}.pdf\"");
        $this->assertSame($reads + 1, DB::table('clinical_reads')->where('reason', 'billing')->count());
        $this->assertEquals(['payment_id' => $payment->id], AuditEvent::where('action', 'billing_payment.receipt')->sole()->context);
        $this->assertEquals(['payment_id' => $payment->id, 'amount_cents' => 15000, 'method' => 'pix'], AuditEvent::where('action', 'billing_payment.received')->sole()->context);

        // Billing of another unit neither receives nor prints.
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Billing, $this->other);
        $this->actingAs($elsewhere->user)->get(route('billing-accounts.receipt', $account))->assertForbidden();
        $this->post(route('billing-accounts.payment.store', $account), ['method' => 'pix'])->assertForbidden();
        // The network admin prints it too (user's decision, 27/10/2026).
        $admin = Membership::query()->withoutGlobalScopes()->where('tenant_id', $this->unit->tenant_id)->whereHas('roleAssignments', fn ($query) => $query->where('role', Role::NetworkAdmin->value))->firstOrFail();
        $this->actingAs($admin->user)->get(route('billing-accounts.receipt', $account))->assertOk();
    }
}
