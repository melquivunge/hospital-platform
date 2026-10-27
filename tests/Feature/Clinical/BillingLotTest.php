<?php

namespace Tests\Feature\Clinical;

use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\BillingItem;
use App\Models\BillingLot;
use App\Models\EmergencyEpisode;
use App\Models\Insurer;
use App\Models\InsurerContract;
use App\Models\Membership;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\Sector;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 6.5b1 (ADR-0010): closed insurance accounts are grouped by insurer, competence and guide type; a lot takes the
 * group's accounts (up to 100), an account is in at most one active lot, and cancelling a lot with a reason releases
 * its accounts. Nothing is deleted.
 */
class BillingLotTest extends ClinicalTestCase
{
    private Insurer $insurer;

    private Membership $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->unit->tenant;
        app(CurrentTenant::class)->set($tenant);
        $table = PriceTable::query()->create(['name' => 'Convênios']);
        foreach ([['consultation', 'Consulta em consultório', '10101012'], ['emergency_consultation', 'Consulta de urgência', '10101039']] as [$kind, $name, $code]) {
            $item = BillingItem::query()->create(['kind' => $kind, 'name' => $name, 'code' => $code]);
            PriceEntry::query()->create(['price_table_id' => $table->id, 'billing_item_id' => $item->id, 'price_cents' => 10000, 'valid_from' => '2026-01-01', 'created_by_user_id' => $this->manager->user_id, 'created_at' => now()]);
        }
        $this->insurer = Insurer::query()->create(['name' => 'Saúde Boa', 'ans_code' => '123456']);
        InsurerContract::query()->create(['insurer_id' => $this->insurer->id, 'price_table_id' => $table->id, 'adjustment_bp' => 0]);
        app(CurrentTenant::class)->set(null);
        $this->billing = Membership::factory()->for($tenant)->create();
        $this->billing->grant(Role::Billing, $this->unit);
    }

    /**
     * @return array<string, mixed>
     */
    private function insurance(): array
    {
        return ['modality' => 'insurance', 'insurer_id' => $this->insurer->id, 'insurance_card_number' => '0012 3456', 'insurance_card_valid_until' => '2027-03-31'];
    }

    private function closedConsultation(string $time): BillingAccount
    {
        $appointment = $this->arrived($this->doctor, $this->unit, $time, $this->insurance());
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment))->assertRedirect();
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()))->assertSessionHasNoErrors();
        $account = BillingAccount::withoutTenantScope()->where('appointment_id', $appointment->id)->sole();
        $this->actingAs($this->billing->user)->post(route('billing-accounts.close', $account))->assertSessionHasNoErrors();

        return $account->fresh();
    }

    private function competence(): string
    {
        return now($this->unit->timezone ?? 'UTC')->format('Y-m');
    }

    /**
     * @return array<string, mixed>
     */
    private function lotRequest(string $guideType = 'sp_sadt'): array
    {
        return ['unit_id' => $this->unit->id, 'insurer_id' => $this->insurer->id, 'competence' => $this->competence(), 'guide_type' => $guideType];
    }

    public function test_ready_accounts_are_grouped_and_a_lot_takes_them_once(): void
    {
        $first = $this->closedConsultation('08:20');
        $second = $this->closedConsultation('09:20');

        $this->actingAs($this->billing->user)->get(route('billing-lots.index'))->assertInertia(fn (Assert $page) => $page->component('billing-lots/index')
            ->where('ready', [['insurerId' => $this->insurer->id, 'insurer' => 'Saúde Boa', 'competence' => $this->competence(), 'guideType' => 'sp_sadt', 'accounts' => 2, 'totalCents' => 20000]])
            ->where('lots', []));

        $this->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasNoErrors();
        $lot = BillingLot::withoutTenantScope()->sole();
        $this->assertSame([$this->unit->id, $this->insurer->id, 'sp_sadt', '4.03.00', 'generated', 2, 20000], [$lot->unit_id, $lot->insurer_id, $lot->guide_type, $lot->tiss_version, $lot->status, $lot->guide_count, $lot->total_cents]);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], DB::table('billing_lot_accounts')->where('billing_lot_id', $lot->id)->pluck('billing_account_id')->all());
        $this->assertEquals(['insurer_id' => $this->insurer->id, 'guide_type' => 'sp_sadt', 'guide_count' => 2, 'total_cents' => 20000], AuditEvent::where('action', 'billing_lot.generated')->sole()->context);

        // Nothing is ready any more: a second lot is refused, and the database refuses the same account twice.
        $this->get(route('billing-lots.index'))->assertInertia(fn (Assert $page) => $page->where('ready', [])->where('lots.0.guideCount', 2));
        $this->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasErrorsIn('lot', ['lot' => 'Não há contas prontas para este lote.']);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $other = BillingLot::query()->forceCreate(['unit_id' => $this->unit->id, 'insurer_id' => $this->insurer->id, 'competence' => $this->competence().'-01', 'guide_type' => 'sp_sadt',
            'tiss_version' => '4.03.00', 'guide_count' => 1, 'total_cents' => 10000, 'created_by_user_id' => $this->billing->user_id, 'created_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('billing_lot_accounts')->insert(['tenant_id' => $other->tenant_id, 'billing_lot_id' => $other->id, 'billing_account_id' => $first->id]);
    }

    public function test_cancelling_a_lot_needs_a_reason_releases_its_accounts_and_deletes_nothing(): void
    {
        $account = $this->closedConsultation('08:20');
        $this->actingAs($this->billing->user)->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasNoErrors();
        $lot = BillingLot::withoutTenantScope()->sole();

        $this->post(route('billing-lots.cancel', $lot), ['reason' => '   '])->assertSessionHasErrorsIn("cancel-{$lot->id}", 'reason');
        $this->post(route('billing-lots.cancel', $lot), ['reason' => 'Competência errada.'])->assertSessionHasNoErrors();
        $lot = BillingLot::withoutTenantScope()->findOrFail($lot->id);
        $this->assertSame(['cancelled', 'Competência errada.', $this->billing->user_id], [$lot->status, $lot->cancel_reason, $lot->cancelled_by_user_id]);
        $this->assertNotNull(DB::table('billing_lot_accounts')->where('billing_lot_id', $lot->id)->value('released_at'));
        $this->assertTrue(AuditEvent::where('action', 'billing_lot.cancelled')->exists());
        $this->post(route('billing-lots.cancel', $lot), ['reason' => 'De novo.'])->assertSessionHasErrorsIn("cancel-{$lot->id}", ['reason' => 'Este lote já foi cancelado.']);

        // The account is ready again and goes into a new lot.
        $this->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasNoErrors();
        $this->assertSame([$account->id], DB::table('billing_lot_accounts')->whereNull('released_at')->pluck('billing_account_id')->all());

        app(CurrentTenant::class)->set($this->unit->tenant);
        foreach ([
            fn () => DB::table('billing_lots')->where('id', $lot->id)->delete(),
            fn () => DB::table('billing_lots')->where('id', $lot->id)->update(['total_cents' => 1]),
            fn () => DB::table('billing_lot_accounts')->where('billing_lot_id', $lot->id)->delete(),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('The database accepted a change to a lot.');
            } catch (QueryException) {
            }
        }
    }

    public function test_only_closed_insurance_accounts_enter_and_an_admitted_episode_is_a_hospitalization_summary(): void
    {
        // An open consultation account is not ready, and the database refuses it in a lot.
        $appointment = $this->arrived($this->doctor, $this->unit, '08:20', $this->insurance());
        $open = BillingAccount::withoutTenantScope()->where('appointment_id', $appointment->id)->sole();
        // An emergency episode that became an admission: the hospitalization summary (requested to a sector with a bed).
        $sector = Sector::withoutGlobalScopes()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $sector->id, 'room' => '1', 'code' => 'A-1', 'type' => 'ward'])->assertSessionHasNoErrors();
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, ...$this->insurance()])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()), ['outcome' => 'admission_requested',
            'requested_sector_id' => $sector->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admitted = BillingAccount::withoutTenantScope()->where('emergency_episode_id', $episode->id)->sole();
        app(CurrentTenant::class)->set($this->unit->tenant);
        // ponytail: closed in the database; the discharge the closing waits for is covered by the closing's own tests.
        DB::table('billing_accounts')->where('id', $admitted->id)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $this->billing->user_id]);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->billing->user)->get(route('billing-lots.index'))->assertInertia(fn (Assert $page) => $page
            ->where('ready', [['insurerId' => $this->insurer->id, 'insurer' => 'Saúde Boa', 'competence' => $this->competence(), 'guideType' => 'hospitalization', 'accounts' => 1, 'totalCents' => 10000]]));
        $this->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasErrorsIn('lot', ['lot' => 'Não há contas prontas para este lote.']);
        $this->post(route('billing-lots.store'), $this->lotRequest('hospitalization'))->assertSessionHasNoErrors();
        $lot = BillingLot::withoutTenantScope()->sole();
        $this->assertSame(['hospitalization', 1], [$lot->guide_type, $lot->guide_count]);

        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->expectException(QueryException::class);
        DB::table('billing_lot_accounts')->insert(['tenant_id' => $lot->tenant_id, 'billing_lot_id' => $lot->id, 'billing_account_id' => $open->id]);
    }

    public function test_only_billing_of_the_unit_or_the_network_admin_works_the_lots(): void
    {
        $this->closedConsultation('08:20');
        $this->actingAs($this->billing->user)->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasNoErrors();
        $lot = BillingLot::withoutTenantScope()->sole();

        // The unit manager does not bill; billing of another unit cannot generate here nor cancel this unit's lot.
        $this->actingAs($this->manager->user)->get(route('billing-lots.index'))->assertForbidden();
        $this->post(route('billing-lots.store'), $this->lotRequest())->assertForbidden();
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Billing, $this->other);
        $this->actingAs($elsewhere->user)->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasErrorsIn('lot', 'unit_id');
        $this->post(route('billing-lots.cancel', $lot), ['reason' => 'Não é meu.'])->assertForbidden();

        // Another network: its insurer is invalid here, and this lot does not exist for it.
        app(CurrentTenant::class)->set(null);
        $stranger = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $foreignInsurer = Insurer::withoutTenantScope()->forceCreate(['tenant_id' => $stranger->tenant_id, 'name' => 'Outra']);
        $this->actingAs($this->billing->user)->post(route('billing-lots.store'), [...$this->lotRequest(), 'insurer_id' => $foreignInsurer->id])->assertSessionHasErrorsIn('lot', 'insurer_id');
        $this->actingAs($stranger->user)->post(route('billing-lots.cancel', $lot), ['reason' => 'X.'])->assertNotFound();

        // The network admin works the lots of every unit.
        app(CurrentTenant::class)->set(null);
        $admin = Membership::factory()->for($this->unit->tenant)->withRole(Role::NetworkAdmin)->create();
        $this->actingAs($admin->user)->get(route('billing-lots.index', ['unit' => $this->unit->id]))->assertOk();
        $this->post(route('billing-lots.cancel', $lot), ['reason' => 'Revisão.'])->assertSessionHasNoErrors();
    }

    /**
     * Closed complementary accounts without items (total zero), inserted straight in the database, oldest first.
     *
     * @return list<int>
     */
    private function closedComplements(BillingAccount $of, int $count, array $overrides = []): array
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        $ids = [];
        foreach (range(1, $count) as $i) {
            $ids[] = DB::table('billing_accounts')->insertGetId([
                'tenant_id' => $of->tenant_id, 'unit_id' => $of->unit_id, 'patient_id' => $of->patient_id, 'complement_of_id' => $of->id,
                'status' => 'closed', 'modality' => 'insurance', 'insurer_id' => $this->insurer->id, 'insurance_card_number' => '0012 3456',
                'insurance_card_valid_until' => '2027-03-31', 'opened_at' => now(), 'closed_at' => now()->addSeconds($i), 'closed_by_user_id' => $this->billing->user_id,
                ...$overrides,
            ]);
        }
        app(CurrentTenant::class)->set(null);

        return $ids;
    }

    public function test_a_lot_takes_the_100_oldest_and_a_negative_account_or_bad_input_never_breaks_it(): void
    {
        $original = $this->closedConsultation('08:20');
        // Compensating the closed account's item lands in a complementary account that, closed, is negative: no guide.
        $item = DB::table('billing_account_items')->where('billing_account_id', $original->id)->value('id');
        $this->actingAs($this->billing->user)->post(route('billing-account-items.compensate', $item), ['reason' => 'Cobrado em duplicidade.'])->assertSessionHasNoErrors();
        $complement = BillingAccount::withoutTenantScope()->where('complement_of_id', $original->id)->sole();
        $this->post(route('billing-accounts.close', $complement))->assertSessionHasNoErrors();
        $this->get(route('billing-lots.index'))->assertInertia(fn (Assert $page) => $page->where('ready.0.accounts', 1)->where('ready.0.totalCents', 10000));

        // 1 + 100 ready: the lot takes the 100 oldest, the newest stays ready.
        $zeros = $this->closedComplements($original, 100);
        $this->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasNoErrors();
        $taken = DB::table('billing_lot_accounts')->pluck('billing_account_id')->all();
        $this->assertCount(100, $taken);
        $this->assertSame([end($zeros)], array_values(array_diff([$original->id, ...$zeros], $taken)));
        $this->assertNotContains($complement->id, $taken);

        // A malformed insurer is a validation error, not a failure.
        $this->post(route('billing-lots.store'), [...$this->lotRequest(), 'insurer_id' => 'abc'])->assertSessionHasErrorsIn('lot', 'insurer_id');

        // The database refuses a private account in a lot.
        [$private] = $this->closedComplements($original, 1, ['modality' => 'private', 'insurer_id' => null, 'insurance_card_number' => null, 'insurance_card_valid_until' => null]);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->expectException(QueryException::class);
        DB::table('billing_lot_accounts')->insert(['tenant_id' => $original->tenant_id, 'billing_lot_id' => BillingLot::query()->value('id'), 'billing_account_id' => $private]);
    }
}
