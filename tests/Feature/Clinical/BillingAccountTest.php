<?php

namespace Tests\Feature\Clinical;

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
        $billing->roleAssignments()->where('role', Role::Billing)->delete();
        $this->actingAs($billing->user)->get(route('billing-accounts.index'))->assertForbidden();
        $this->get(route('billing-accounts.show', $account))->assertForbidden();
        $this->assertSame(1, DB::table('clinical_reads')->where('reason', 'billing')->count());
    }
}
