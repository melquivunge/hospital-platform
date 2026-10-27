<?php

namespace Tests\Feature\Clinical;

use App\Billing\TissLotXml;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\BillingAccountItem;
use App\Models\BillingItem;
use App\Models\BillingLot;
use App\Models\EmergencyEpisode;
use App\Models\Insurer;
use App\Models\InsurerContract;
use App\Models\JobFunction;
use App\Models\Material;
use App\Models\Membership;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\Professional;
use App\Models\Sector;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use DOMDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_the_lot_file_lists_what_is_missing_then_is_valid_tiss_written_once_and_its_download_is_logged(): void
    {
        Storage::fake('local');
        // A consultation with a material billed by hand: one procedure and one other expense.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $gauze = Material::query()->create(['name' => 'Compressa', 'stock_unit' => 'pacote']);
        $material = BillingItem::query()->create(['kind' => 'material', 'material_id' => $gauze->id, 'name' => 'Compressa – cirúrgica', 'code' => '70000123']);
        PriceEntry::query()->create(['price_table_id' => PriceTable::query()->value('id'), 'billing_item_id' => $material->id, 'price_cents' => 250, 'valid_from' => '2026-01-01', 'created_by_user_id' => $this->manager->user_id, 'created_at' => now()]);
        app(CurrentTenant::class)->set(null);
        $appointment = $this->arrived($this->doctor, $this->unit, '08:20', $this->insurance());
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment))->assertRedirect();
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()))->assertSessionHasNoErrors();
        $account = BillingAccount::withoutTenantScope()->where('appointment_id', $appointment->id)->sole();
        $this->actingAs($this->billing->user)->post(route('billing-accounts.items.store', $account), ['billing_item_id' => $material->id, 'performed_on' => now($this->unit->timezone ?? 'UTC')->toDateString(), 'quantity' => 2, 'reason' => 'Curativo.'])->assertSessionHasNoErrors();
        $this->post(route('billing-accounts.close', $account))->assertSessionHasNoErrors();
        $this->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasNoErrors();
        $lot = BillingLot::withoutTenantScope()->sole();

        // Nothing of the provider's data is filled in yet: the screen lists it and the file is refused.
        $this->get(route('billing-lots.index'))->assertInertia(fn (Assert $page) => $page->where('lots.0.file', null)->where('lots.0.problems', [
            'O contrato do convênio não tem o código do prestador na operadora (tela Preços).',
            'A unidade não tem o CNES (tela Unidades).',
            'Dra. Ana: sem conselho reconhecido pelo TISS, número (até 15) ou UF (tela Profissionais).',
            'Dra. Ana: sem CBO aceito pelo TISS na função (tela Funções).',
        ]));
        $this->post(route('billing-lots.file', $lot))->assertSessionHasErrorsIn("file-{$lot->id}", 'file');

        app(CurrentTenant::class)->set($this->unit->tenant);
        InsurerContract::query()->update(['provider_code' => '00012345']);
        $this->unit->forceFill(['cnes' => '1234567'])->save();
        Professional::query()->whereKey($this->doctor->id)->update(['council' => 'CRM', 'council_number' => '123456', 'council_uf' => 'SP']);
        JobFunction::query()->update(['cbo' => '225125']);
        app(CurrentTenant::class)->set(null);
        $this->get(route('billing-lots.index'))->assertInertia(fn (Assert $page) => $page->where('lots.0.problems', []));

        $this->post(route('billing-lots.file', $lot))->assertSessionHasNoErrors();
        $lot = BillingLot::withoutTenantScope()->findOrFail($lot->id);
        $xml = Storage::disk('local')->get((string) $lot->file_path);
        $this->assertSame(hash('sha256', $xml), $lot->file_sha256);
        $this->assertSame([], TissLotXml::schemaErrors($xml));
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $this->assertSame($lot->file_hash, TissLotXml::hash($doc));
        $this->assertSame($lot->file_hash, $doc->getElementsByTagName('hash')->item(0)?->textContent);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="ISO-8859-1"?>', $xml);
        $value = fn (string $tag) => $doc->getElementsByTagName($tag)->item(0)?->textContent;
        $this->assertSame([(string) $lot->id, '123456', '00012345', '1234567', '06', '35', '225125', '1', '01', '10101012', '22', '03', '19', '2.0000', '100.00', '5.00', '105.00'], [
            $value('numeroLote'), $value('registroANS'), $value('codigoPrestadorNaOperadora'), $value('CNES'), $value('conselhoProfissional'), $value('UF'), $value('CBOS'),
            $value('caraterAtendimento'), $value('regimeAtendimento'), $value('codigoProcedimento'), $value('codigoTabela'), $value('codigoDespesa'),
            $doc->getElementsByTagName('codigoTabela')->item(1)?->textContent, $doc->getElementsByTagName('quantidadeExecutada')->item(1)?->textContent,
            $value('valorProcedimentos'), $value('valorMateriais'), $value('valorTotalGeral'),
        ]);
        // Outside ISO-8859-1 ("–") becomes "?" in the file, so the insurer's hash reads the same text.
        $this->assertSame('Compressa ? cirúrgica', $doc->getElementsByTagName('descricaoProcedimento')->item(1)?->textContent);
        // The guide identifies the patient by the card only (no name since TISS 4.00.00).
        $this->assertSame('0012 3456', $value('numeroCarteira'));
        $this->assertStringNotContainsString('Joana', $xml);
        $this->assertEquals(['guide_count' => 1, 'sha256' => $lot->file_sha256], AuditEvent::where('action', 'billing_lot.file_generated')->sole()->context);

        // Written once: a second generation is refused, and the database refuses any change to it.
        $this->post(route('billing-lots.file', $lot))->assertSessionHasErrorsIn("file-{$lot->id}", ['file' => 'O arquivo deste lote já foi gerado.']);
        $this->get(route('billing-lots.index'))->assertInertia(fn (Assert $page) => $page->where('lots.0.file.sha256', $lot->file_sha256));

        // The download is the stored file, and each guide's patient is logged in the trail.
        $reads = DB::table('clinical_reads')->count();
        $this->get(route('billing-lots.download', $lot))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=ISO-8859-1')
            ->assertDownload("lote-{$lot->id}-tiss-4.03.00.xml");
        $this->assertSame($reads + 1, DB::table('clinical_reads')->count());
        $this->assertTrue(AuditEvent::where('action', 'billing_lot.file_downloaded')->exists());
        $this->actingAs($this->manager->user)->get(route('billing-lots.download', $lot))->assertForbidden();
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Billing, $this->other);
        $this->actingAs($elsewhere->user)->post(route('billing-lots.file', $lot))->assertForbidden();

        // Cancelled, its accounts may go into another lot: the file is no longer downloaded.
        $this->actingAs($this->billing->user)->post(route('billing-lots.cancel', $lot), ['reason' => 'Reenvio corrigido.'])->assertSessionHasNoErrors();
        $this->get(route('billing-lots.download', $lot))->assertNotFound();
        $this->post(route('billing-lots.file', $lot))->assertSessionHasErrorsIn("file-{$lot->id}", ['file' => 'Este lote foi cancelado.']);

        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->expectException(QueryException::class);
        DB::table('billing_lots')->where('id', $lot->id)->update(['file_sha256' => str_repeat('0', 64)]);
    }

    public function test_the_epilogue_hash_is_the_md5_of_the_leaf_texts_in_iso_8859_1(): void
    {
        $doc = new DOMDocument;
        $doc->loadXML('<m><a>  1</a><b><c>Ação</c><d/></b><e x="atributo">007</e><hash>ignorado</hash></m>');

        $this->assertSame(md5(mb_convert_encoding('  1Ação007', 'ISO-8859-1', 'UTF-8')), TissLotXml::hash($doc));
    }

    public function test_the_hospitalization_summary_carries_the_admission_and_the_insurers_password(): void
    {
        Storage::fake('local');
        // An episode with insurance becomes an admission and is discharged; its daily rate asks for the insurer's password
        // even though the contract lists no item needing authorization.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $daily = BillingItem::query()->create(['kind' => 'daily', 'bed_type' => 'ward', 'name' => 'Diária de enfermaria', 'code' => '60000775']);
        PriceEntry::query()->create(['price_table_id' => PriceTable::query()->value('id'), 'billing_item_id' => $daily->id, 'price_cents' => 48000, 'valid_from' => '2026-01-01', 'created_by_user_id' => $this->manager->user_id, 'created_at' => now()]);
        InsurerContract::query()->update(['provider_code' => '00012345']);
        $this->unit->forceFill(['cnes' => '1234567'])->save();
        app(CurrentTenant::class)->set(null);
        $bedManager = Membership::factory()->for($this->unit->tenant)->create();
        $bedManager->grant(Role::BedManagement, $this->unit);
        $sector = Sector::withoutGlobalScopes()->where('unit_id', $this->unit->id)->firstOrFail();
        $this->actingAs($this->manager->user)->post(route('beds.store', $this->unit), ['sector_id' => $sector->id, 'room' => '1', 'code' => 'A-1', 'type' => 'ward'])->assertSessionHasNoErrors();
        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id, ...$this->insurance()])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dispneia.']);
        $this->post(route('emergency.call', $episode));
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()), ['outcome' => 'admission_requested', 'requested_sector_id' => $sector->id, 'responsible_professional_id' => $this->doctor->id])->assertSessionHasNoErrors();
        $admission = Admission::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($bedManager->user)->post(route('admissions.allocate', $admission), ['bed_id' => Bed::withoutGlobalScopes()->where('code', 'A-1')->value('id')])->assertSessionHasNoErrors();
        $this->actingAs($this->doctorAccount->user)->post(route('ward.discharge', $admission), ['type' => 'improved', 'summary' => 'Alta.', 'password' => 'password'])->assertSessionHasNoErrors();
        $account = BillingAccount::withoutTenantScope()->where('emergency_episode_id', $episode->id)->sole();
        $dailyItem = BillingAccountItem::withoutTenantScope()->where('billing_account_id', $account->id)->where('origin_type', 'admission_day')->sole();
        $this->assertTrue($dailyItem->needs_authorization);

        // An urgency answer lets care go on, but the summary requires the insurer's password: the account does not close without it.
        $this->actingAs($this->billing->user)->post(route('billing-accounts.authorizations.store', $account), ['item_ids' => [$dailyItem->id], 'status' => 'urgency', 'reason' => 'Internação de urgência.'])->assertSessionHasNoErrors();
        $this->post(route('billing-accounts.close', $account))->assertSessionHasErrorsIn('close', ['close' => 'A internação pelo convênio precisa da senha antes de fechar: registre a autorização (número e validade) da diária.']);
        $this->post(route('billing-accounts.authorizations.store', $account), ['item_ids' => [$dailyItem->id], 'status' => 'authorized', 'number' => 'SENHA-778899', 'valid_until' => '2027-01-31'])->assertSessionHasNoErrors();
        $this->post(route('billing-accounts.close', $account))->assertSessionHasNoErrors();
        $this->post(route('billing-lots.store'), $this->lotRequest('hospitalization'))->assertSessionHasNoErrors();
        $lot = BillingLot::withoutTenantScope()->sole();
        // No professional is on the summary: the doctor's missing council and CBO do not hold it.
        $this->get(route('billing-lots.index'))->assertInertia(fn (Assert $page) => $page->where('lots.0.problems', []));
        $this->post(route('billing-lots.file', $lot))->assertSessionHasNoErrors();

        $lot = BillingLot::withoutTenantScope()->findOrFail($lot->id);
        $xml = Storage::disk('local')->get((string) $lot->file_path);
        $this->assertSame([], TissLotXml::schemaErrors($xml));
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $this->assertSame($lot->file_hash, TissLotXml::hash($doc));
        $value = fn (string $tag) => $doc->getElementsByTagName($tag)->item(0)?->textContent;
        $timezone = $this->unit->timezone ?? 'UTC';
        $admission = $admission->fresh();
        $this->assertSame([1, 0], [$doc->getElementsByTagName('guiaResumoInternacao')->length, $doc->getElementsByTagName('guiaSP-SADT')->length]);
        $this->assertSame([(string) $account->id, (string) $admission->id, 'SENHA-778899', '2027-01-31', '2', '4',
            $admission->admitted_at->setTimezone($timezone)->toDateString(), $admission->discharged_at->setTimezone($timezone)->format('H:i:s'), '1', '1', '9', '12',
            '10101039', '05', '60000775', '18', '100.00', '480.00', '580.00'], [
                $value('numeroGuiaPrestador'), $value('numeroGuiaSolicitacaoInternacao'), $value('senha'), $value('dataValidadeSenha'), $value('caraterAtendimento'), $value('tipoFaturamento'),
                $value('dataInicioFaturamento'), $value('horaFinalFaturamento'), $value('tipoInternacao'), $value('regimeInternacao'), $value('indicadorAcidente'), $value('motivoEncerramento'),
                $value('codigoProcedimento'), $value('codigoDespesa'), $doc->getElementsByTagName('codigoProcedimento')->item(1)?->textContent, $doc->getElementsByTagName('codigoTabela')->item(1)?->textContent,
                $value('valorProcedimentos'), $value('valorDiarias'), $value('valorTotalGeral'),
            ]);
        // The guide's totals come before the other expenses in the summary.
        $children = array_map(fn ($node) => $node->localName, array_values(array_filter(iterator_to_array($doc->getElementsByTagName('guiaResumoInternacao')->item(0)->childNodes), fn ($node) => $node instanceof \DOMElement)));
        $this->assertSame(['procedimentosExecutados', 'valorTotal', 'outrasDespesas'], array_slice($children, -3));
        $this->assertStringNotContainsString('Dra. Ana', $xml);
    }

    /**
     * Step 6.5c1: a lot of two consultations (one item of 100,00 each) with its TISS file written.
     */
    private function sentLot(): BillingLot
    {
        Storage::fake('local');
        $this->closedConsultation('08:20');
        $this->closedConsultation('09:20');
        app(CurrentTenant::class)->set($this->unit->tenant);
        InsurerContract::query()->update(['provider_code' => '00012345']);
        $this->unit->forceFill(['cnes' => '1234567'])->save();
        Professional::query()->whereKey($this->doctor->id)->update(['council' => 'CRM', 'council_number' => '123456', 'council_uf' => 'SP']);
        JobFunction::query()->update(['cbo' => '225125']);
        app(CurrentTenant::class)->set(null);
        $this->actingAs($this->billing->user)->post(route('billing-lots.store'), $this->lotRequest())->assertSessionHasNoErrors();
        $lot = BillingLot::withoutTenantScope()->sole();

        // Not sent yet: no return.
        $this->get(route('billing-lots.return', $lot))->assertNotFound();
        $this->post(route('billing-lots.return.store', $lot))->assertSessionHasErrorsIn('return', 'return');
        $this->post(route('billing-lots.file', $lot))->assertSessionHasNoErrors();

        return BillingLot::withoutTenantScope()->findOrFail($lot->id);
    }

    public function test_the_insurers_return_is_recorded_once_per_item_and_reconciles_the_lot(): void
    {
        $lot = $this->sentLot();
        [$first, $second] = $lot->guideItems()->pluck('id')->all();

        $reads = DB::table('clinical_reads')->count();
        $this->get(route('billing-lots.return', $lot))->assertInertia(fn (Assert $page) => $page->component('billing-lots/return')
            ->where('lot.totalCents', 20000)->where('recorded', null)->has('accounts', 2)
            ->where('accounts.0.items.0', ['id' => $first, 'code' => '10101012', 'name' => 'Consulta em consultório', 'quantity' => 1, 'billedCents' => 10000, 'return' => null])
            ->where('glosaCodes.1001', 'NÚMERO DA CARTEIRA INVÁLIDO'));
        // Each guide's patient is logged in the trail.
        $this->assertSame($reads + 2, DB::table('clinical_reads')->count());

        // A glosa needs a value up to the billed one and a code of TUSS table 38; nothing is recorded meanwhile.
        $this->post(route('billing-lots.return.store', $lot), ['glosas' => [$first => ['amount' => 'abc', 'code' => '1001']]])
            ->assertSessionHasErrorsIn('return', "glosas.{$first}.amount");
        $this->post(route('billing-lots.return.store', $lot), ['glosas' => [$first => ['amount' => '30,00', 'code' => '9999']]])
            ->assertSessionHasErrorsIn('return', "glosas.{$first}.code");
        $this->post(route('billing-lots.return.store', $lot), ['glosas' => [$first => ['amount' => '', 'code' => '', 'reason' => 'Só o motivo.']]])
            ->assertSessionHasErrorsIn('return', "glosas.{$first}.amount");
        $this->post(route('billing-lots.return.store', $lot), ['glosas' => [$first => ['amount' => '100,01', 'code' => '1001']]])
            ->assertSessionHasErrorsIn('return', ['return' => 'A glosa de um item passa do valor cobrado.']);
        $this->post(route('billing-lots.return.store', $lot), ['glosas' => [$first + $second + 1000 => ['amount' => '1,00', 'code' => '1001']]])
            ->assertSessionHasErrorsIn('return', ['return' => 'Um item glosado não é deste lote. Atualize a página e confira.']);
        $this->assertSame(0, DB::table('billing_item_returns')->count());

        // Recorded: the glossed item with its code and reason, the other paid in full.
        $this->post(route('billing-lots.return.store', $lot), ['glosas' => [
            $first => ['amount' => '30,00', 'code' => '1001', 'reason' => '  Carteira vencida.  '],
            $second => ['amount' => '', 'code' => '', 'reason' => ''],
        ]])->assertSessionHasNoErrors()->assertRedirect(route('billing-lots.return', $lot));
        $rows = DB::table('billing_item_returns')->orderBy('billing_account_item_id')->get();
        $this->assertSame([[$first, 10000, 7000, 3000, '1001', 'Carteira vencida.'], [$second, 10000, 10000, 0, null, null]], $rows->map(fn ($row) => [
            $row->billing_account_item_id, $row->billed_cents, $row->paid_cents, $row->glossed_cents, $row->glosa_code, $row->glosa_reason,
        ])->all());
        $this->assertEquals(['paid_cents' => 17000, 'glossed_cents' => 3000, 'glossed_items' => 1], AuditEvent::where('action', 'billing_lot.return_recorded')->sole()->context);
        $this->get(route('billing-lots.index'))->assertInertia(fn (Assert $page) => $page->where('lots.0.return', ['paidCents' => 17000, 'glossedCents' => 3000]));
        $this->get(route('billing-lots.return', $lot))->assertInertia(fn (Assert $page) => $page->where('recorded.paidCents', 17000)->where('recorded.glossedCents', 3000)
            ->where('accounts.0.items.0.return', ['paidCents' => 7000, 'glossedCents' => 3000, 'code' => '1001', 'reason' => 'Carteira vencida.']));

        // Once: a second return and the cancellation are refused.
        $this->post(route('billing-lots.return.store', $lot), [])->assertSessionHasErrorsIn('return', ['return' => 'O retorno deste lote já foi registrado.']);
        $this->post(route('billing-lots.cancel', $lot), ['reason' => 'Reenvio.'])
            ->assertSessionHasErrorsIn("cancel-{$lot->id}", ['reason' => 'O retorno da operadora já foi registrado: o lote não é mais cancelado.']);

        // Only billing of the unit (or the network admin).
        $this->actingAs($this->manager->user)->get(route('billing-lots.return', $lot))->assertForbidden();
        $elsewhere = Membership::factory()->for($this->unit->tenant)->create();
        $elsewhere->grant(Role::Billing, $this->other);
        $this->actingAs($elsewhere->user)->post(route('billing-lots.return.store', $lot), [])->assertForbidden();

        // The database keeps it: no change, no deletion, no cancellation, no return off the item's value.
        app(CurrentTenant::class)->set($this->unit->tenant);
        foreach ([
            fn () => DB::table('billing_item_returns')->where('billing_account_item_id', $first)->update(['paid_cents' => 10000, 'glossed_cents' => 0, 'glosa_code' => null, 'glosa_reason' => null]),
            fn () => DB::table('billing_item_returns')->delete(),
            fn () => DB::table('billing_lots')->where('id', $lot->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $this->billing->user_id, 'cancel_reason' => 'x']),
            fn () => DB::table('billing_item_returns')->insert(['tenant_id' => $lot->tenant_id, 'billing_lot_id' => $lot->id, 'billing_account_item_id' => $second,
                'billed_cents' => 10000, 'paid_cents' => 10000, 'glossed_cents' => 0, 'recorded_by_user_id' => $this->billing->user_id, 'recorded_at' => now()]),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('The database accepted a change to a return.');
            } catch (QueryException) {
            }
        }
    }

    public function test_a_return_is_only_of_a_guide_item_at_its_value(): void
    {
        $lot = $this->sentLot();
        [$first] = $lot->guideItems()->pluck('id')->all();
        app(CurrentTenant::class)->set($this->unit->tenant);
        $row = ['tenant_id' => $lot->tenant_id, 'billing_lot_id' => $lot->id, 'billing_account_item_id' => $first, 'billed_cents' => 10000, 'paid_cents' => 10000,
            'glossed_cents' => 0, 'recorded_by_user_id' => $this->billing->user_id, 'recorded_at' => now()];
        foreach ([
            ['billed_cents' => 9000, 'paid_cents' => 9000],
            ['paid_cents' => 7000, 'glossed_cents' => 3000],
            ['paid_cents' => 7000, 'glossed_cents' => 3000, 'glosa_code' => '10A1'],
            ['glosa_reason' => 'Sem glosa.'],
        ] as $override) {
            try {
                DB::transaction(fn () => DB::table('billing_item_returns')->insert([...$row, ...$override]));
                $this->fail('The database accepted a return off its item.');
            } catch (QueryException) {
            }
        }
        DB::table('billing_item_returns')->insert($row);
        $this->assertSame(1, DB::table('billing_item_returns')->count());
    }
}
