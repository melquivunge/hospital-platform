<?php

namespace Tests\Feature\Clinical;

use App\Billing\BpaFile;
use App\Billing\SigtapImport;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\BillingAccountItem;
use App\Models\BillingItem;
use App\Models\Membership;
use App\Models\SusBpaFile;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use ZipArchive;

/**
 * Step 6b.6a2 (ADR-0011): the BPA file in the official export layout (resources/sus/bpa/), position by position: the
 * header with its control field, a BPA-I line and a BPA-C line; generated once per unit and competence, with the
 * closed accounts not presented yet; downloaded as generated.
 */
class BpaFileTest extends ClinicalTestCase
{
    private Membership $billing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // The real 10/2026 SIGTAP cut, as the 11/2026 competence of the visits here.
        $path = tempnam(sys_get_temp_dir(), 'sigtap').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach (glob(base_path('tests/Fixtures/sigtap/*.txt')) ?: [] as $file) {
            $zip->addFromString(basename($file), str_contains($file, '_layout') ? (string) file_get_contents($file) : str_replace('202610', '202611', (string) file_get_contents($file)));
        }
        $zip->close();
        app(CurrentTenant::class)->set($this->unit->tenant);
        SigtapImport::read($path)->apply($this->unit->tenant_id, 'TabelaUnificada_202611.zip', str_repeat('a', 64), $this->manager->user);
        BillingItem::query()->create(['kind' => 'consultation', 'name' => 'Consulta especializada', 'code' => '10101012', 'sigtap_code' => '0301010072']);
        DB::table('patients')->where('id', $this->patient->id)->update(['cns' => '123456789010000', 'race_color' => '03', 'address_municipality' => '3550308']);
        DB::table('professionals')->where('id', $this->doctor->id)->update(['cns' => '700123456780900']);
        DB::table('job_functions')->update(['cbo' => '225125']);
        app(CurrentTenant::class)->set(null);
        $this->billing = Membership::factory()->for($this->unit->tenant)->create();
        $this->billing->grant(Role::Billing, $this->unit);
    }

    /** A SUS consultation closed with its CID and its account closed by billing. */
    private function closedConsultation(string $time): BillingAccount
    {
        $appointment = $this->arrived($this->doctor, $this->unit, $time);
        $this->actingAs($this->doctorAccount->user)->post(route('visits.open', $appointment))->assertRedirect();
        $this->post(route('visits.close', Visit::withoutTenantScope()->latest('id')->firstOrFail()), ['cid_principal' => 'J18.9'])->assertSessionHasNoErrors();
        $account = BillingAccount::withoutTenantScope()->where('appointment_id', $appointment->id)->sole();
        $this->actingAs($this->billing->user)->post(route('billing-accounts.close', $account))->assertSessionHasNoErrors();

        return $account;
    }

    private function headerData(string $level = 'M'): void
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        DB::table('units')->where('id', $this->unit->id)->update(['name' => 'Hospital Aurora', 'cnes' => '7654321', 'cnpj' => '12345678000190', 'sus_acronym' => 'HA',
            'sus_manager_name' => 'Secretaria Municipal de Saúde', 'sus_manager_level' => $level]);
        app(CurrentTenant::class)->set(null);
    }

    public function test_the_bpa_file_follows_the_official_layout_and_presents_each_account_once(): void
    {
        $account = $this->closedConsultation('08:20');
        $generate = fn (string $competence) => $this->actingAs($this->billing->user)->post(route('sus-bpa.store'), ['unit_id' => $this->unit->id, 'competence' => $competence]);
        $this->actingAs($this->billing->user)->get(route('sus-bpa.index'))->assertInertia(fn (Assert $page) => $page->component('sus-bpa/index')->where('ready', 1)->has('files', 0));

        // Without the unit's header data, nothing is generated.
        $generate('2026-11')->assertSessionHasErrors('competence');
        $this->assertSame(0, SusBpaFile::withoutTenantScope()->count());
        $this->headerData();
        $generate('2026-12')->assertSessionHasErrors(['competence' => 'A competência não pode estar no futuro.']);
        // Review finding: done in 11/2026, the account waits for that competence (a line never later than its header).
        $generate('2026-10')->assertSessionHasErrors(['competence' => 'Nenhuma conta SUS de BPA fechada para apresentar nesta unidade até esta competência.']);
        $this->assertSame(0, SusBpaFile::withoutTenantScope()->count());
        $generate('2026-11')->assertSessionHasNoErrors();

        $file = SusBpaFile::withoutTenantScope()->sole();
        $text = (string) Storage::disk('local')->get($file->path);
        $this->assertSame(hash('sha256', $text), $file->sha256);
        $lines = explode("\r\n", $text);
        $this->assertSame('', array_pop($lines));
        [$header, $line] = $lines;
        $field = fn (string $line, int $start, int $end) => substr($line, $start - 1, $end - $start + 1);

        // Header: 130 positions + CR LF; control = (code + quantity) mod 1111 + 1111.
        $this->assertSame(130, strlen($header));
        $this->assertSame(['01', '#BPA#', '202611', '000001', '000001', (string) ((301010072 + 1) % 1111 + 1111)], [
            $field($header, 1, 2), $field($header, 3, 7), $field($header, 8, 13), $field($header, 14, 19), $field($header, 20, 25), $field($header, 26, 29),
        ]);
        $this->assertSame(['HOSPITAL AURORA', 'HA', '12345678000190', 'SECRETARIA MUNICIPAL DE SAUDE', 'M'], [
            rtrim($field($header, 30, 59)), rtrim($field($header, 60, 65)), $field($header, 66, 79), rtrim($field($header, 80, 119)), $field($header, 120, 120),
        ]);

        // BPA-I (the consultation accepts it): 351 positions + CR LF.
        $this->assertSame(351, strlen($line));
        $this->assertSame([
            '03', '7654321', '202611', '700123456780900', '225125', '20261116', '001', '01', '0301010072', '123456789010000', 'F', '355030', 'J189',
            '036', '000001', '01', '             ', 'BPA', 'JOANA PRADO', '19900102', '03', '    ', '           ',
        ], [
            $field($line, 1, 2), $field($line, 3, 9), $field($line, 10, 15), $field($line, 16, 30), $field($line, 31, 36), $field($line, 37, 44),
            $field($line, 45, 47), $field($line, 48, 49), $field($line, 50, 59), $field($line, 60, 74), $field($line, 75, 75), $field($line, 76, 81),
            $field($line, 82, 85), $field($line, 86, 88), $field($line, 89, 94), $field($line, 95, 96), $field($line, 97, 109), $field($line, 110, 112),
            rtrim($field($line, 113, 142)), $field($line, 143, 150), $field($line, 151, 152), $field($line, 153, 156), $field($line, 339, 349),
        ]);

        // Presented once: the same competence is refused; another finds nothing left.
        $generate('2026-11')->assertSessionHasErrors(['competence' => 'O BPA desta competência já foi gerado nesta unidade.']);
        $generate('2026-09')->assertSessionHasErrors(['competence' => 'Nenhuma conta SUS de BPA fechada para apresentar nesta unidade até esta competência.']);
        $this->get(route('sus-bpa.index'))->assertInertia(fn (Assert $page) => $page->where('ready', 0)->where('files.0.lines', 1)->where('files.0.accounts', 1));

        // Downloaded as generated, each patient's read in the trail; billing of another unit does not reach it.
        $download = $this->get(route('sus-bpa.download', $file))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=US-ASCII');
        $this->assertSame($text, $download->getContent());
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertTrue(DB::table('clinical_reads')->where('user_id', $this->billing->user_id)->where('billing_account_id', $account->id)->exists());
        foreach ([fn () => DB::table('sus_bpa_files')->update(['line_count' => 9]), fn () => DB::table('sus_bpa_file_accounts')->delete()] as $change) {
            try {
                DB::transaction($change);
                $this->fail('The database accepted a change to a BPA file.');
            } catch (QueryException) {
            }
        }
        app(CurrentTenant::class)->set(null);
        $this->assertStringNotContainsString('JOANA', AuditEvent::query()->pluck('context')->toJson());
        $outsider = Membership::factory()->for($this->unit->tenant)->create();
        $outsider->grant(Role::Billing, $this->other);
        $this->actingAs($outsider->user)->get(route('sus-bpa.download', $file))->assertForbidden();
        $this->actingAs($outsider->user)->post(route('sus-bpa.store'), ['unit_id' => $this->unit->id, 'competence' => '2026-11'])->assertForbidden();
    }

    /** A procedure the SIGTAP accepts only in the consolidated BPA goes to a BPA-C line, by competence, CBO and age. */
    public function test_a_consolidated_only_procedure_goes_to_a_bpa_c_line(): void
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        DB::statement("UPDATE sigtap_procedures SET registers = '{01}' WHERE code = '0301010072'");
        app(CurrentTenant::class)->set(null);
        $this->headerData('E');
        $this->closedConsultation('08:20');
        $this->closedConsultation('08:40');
        $this->actingAs($this->billing->user)->post(route('sus-bpa.store'), ['unit_id' => $this->unit->id, 'competence' => '2026-11'])->assertSessionHasNoErrors();
        $lines = explode("\r\n", (string) Storage::disk('local')->get(SusBpaFile::withoutTenantScope()->sole()->path));
        $field = fn (int $start, int $end) => substr($lines[1], $start - 1, $end - $start + 1);

        // Two consultations, one line: quantity 2; 48 positions + CR LF.
        $this->assertCount(3, $lines);
        $this->assertSame(48, strlen($lines[1]));
        $this->assertSame(['02', '7654321', '202611', '225125', '001', '01', '0301010072', '036', '000002', 'BPA'], [
            $field(1, 2), $field(3, 9), $field(10, 15), $field(16, 21), $field(22, 24), $field(25, 26), $field(27, 36), $field(37, 39), $field(40, 45), $field(46, 48),
        ]);
        $this->assertSame([(string) ((301010072 + 2) % 1111 + 1111), 'E'], [substr($lines[0], 25, 4), substr($lines[0], 119, 1)]);
    }

    public function test_billing_records_the_return_per_line_and_a_rejected_line_goes_again_later(): void
    {
        $rejected = $this->closedConsultation('08:20');
        $approved = $this->closedConsultation('09:00');
        $this->headerData();
        $this->actingAs($this->billing->user)->post(route('sus-bpa.store'), ['unit_id' => $this->unit->id, 'competence' => '2026-11'])->assertSessionHasNoErrors();
        $file = SusBpaFile::withoutTenantScope()->sole();
        $lines = DB::table('sus_bpa_file_lines')->orderBy('sequence')->get();
        $this->assertSame([['I', 1, 1, '0301010072', '202611', 1], ['I', 1, 2, '0301010072', '202611', 1]], $lines->map(fn ($line) => [$line->kind, $line->sheet, $line->sequence, $line->procedure_code, $line->done_competence, $line->quantity])->all());
        [$first, $second] = $lines->pluck('id')->all();
        $this->get(route('sus-bpa.show', $file))->assertInertia(fn (Assert $page) => $page->component('sus-bpa/show')
            ->where('lines.0.accounts', [$rejected->id])->where('lines.0.editable', true)->where('lines.1.accounts', [$approved->id]));

        $return = fn (int $line, array $data) => $this->post(route('sus-bpa.returns.store', $file), ['line_id' => $line, ...$data]);
        $return($first, ['approved' => '0'])->assertSessionHasErrors('reason');
        $return($first, ['approved' => '0', 'reason' => 'CBO incompatível.'])->assertSessionHasNoErrors();
        $return($second, ['approved' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('audit_events')->where('action', 'sus_bpa_return.recorded')->where('context', 'like', '%CBO%')->exists());

        // Step 6b.7c: the manager's report of the competence, by the return, with the rejection's reason (its read logged).
        $price = (int) DB::table('billing_account_items')->where('billing_account_id', $rejected->id)->value('unit_price_cents');
        $this->assertGreaterThan(0, $price);
        $reads = DB::table('clinical_reads')->where('user_id', $this->manager->user->id)->count();
        $this->actingAs($this->manager->user)->get(route('billing-reports.index', ['unit' => $this->unit->id]))->assertInertia(fn (Assert $page) => $page
            ->where('filters.competence', '2026-11')->where('sus.competences', ['2026-11'])
            ->where('sus.bpa', ['approved' => ['quantity' => 1, 'totalCents' => $price], 'rejected' => ['quantity' => 1, 'totalCents' => $price],
                'waiting' => ['quantity' => 0, 'totalCents' => 0], 'presented' => ['quantity' => 2, 'totalCents' => 2 * $price]])
            ->where('sus.aih.presented', ['quantity' => 0, 'totalCents' => 0])
            ->where('sus.rejections', [['label' => 'BPA-I folha 1 linha 1 · 0301010072', 'quantity' => 1, 'totalCents' => $price, 'reason' => 'CBO incompatível.', 'reopen' => []]]));
        $this->assertSame($reads + 1, DB::table('clinical_reads')->where('user_id', $this->manager->user->id)->count());
        $this->get(route('billing-reports.index', ['unit' => $this->unit->id, 'competence' => '2026-10']))->assertInertia(fn (Assert $page) => $page
            ->where('sus.bpa.presented.quantity', 0)->where('sus.rejections', []));
        foreach (['2026-13', ['2026-11']] as $invalid) {
            $this->get(route('billing-reports.index', ['unit' => $this->unit->id, 'competence' => $invalid]))->assertInertia(fn (Assert $page) => $page->where('filters.competence', '2026-11'));
        }
        $this->actingAs($this->billing->user)->get(route('billing-reports.index', ['competence' => '2026-11']))->assertForbidden();

        $this->assertSame([$rejected->id], BpaFile::accounts($this->unit->id)->modelKeys());
        $this->post(route('billing-accounts.reopen', $approved))->assertSessionHasErrors('reopen', null, 'reopen');
        $this->get(route('billing-accounts.show', $rejected))->assertInertia(fn (Assert $page) => $page
            ->where('bpa.rejected', [['procedure' => '0301010072', 'competence' => '11/2026', 'reason' => 'CBO incompatível.']])->where('bpa.canReopen', true));

        // Another unit's billing sees nothing of it.
        $outsider = Membership::factory()->for($this->unit->tenant)->create();
        $outsider->grant(Role::Billing, $this->other);
        $this->actingAs($outsider->user)->get(route('sus-bpa.show', $file))->assertForbidden();
        $this->post(route('sus-bpa.returns.store', $file), ['line_id' => $first, 'approved' => '1'])->assertForbidden();
        $this->post(route('billing-accounts.reopen', $rejected))->assertForbidden();

        // Never again in the competence it was presented in; reopened, corrected and closed again, the line's return is final.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->assertNotSame([], BpaFile::build($this->unit->fresh(), '2026-11', BpaFile::accounts($this->unit->id))['problems']);
        app(CurrentTenant::class)->set(null);
        $this->travelTo('2026-12-02 09:00:00');
        $this->actingAs($this->billing->user)->post(route('billing-accounts.reopen', $rejected))->assertSessionHasNoErrors();
        $this->assertSame('open', BillingAccount::withoutTenantScope()->findOrFail($rejected->id)->status);
        $this->post(route('billing-accounts.close', $rejected))->assertSessionHasNoErrors();
        $return($first, ['approved' => '1'])->assertSessionHasErrors('reason', null, "return{$first}");
        $this->get(route('sus-bpa.show', $file))->assertInertia(fn (Assert $page) => $page->where('lines.0.editable', false)->where('lines.1.editable', true));

        $this->post(route('sus-bpa.store'), ['unit_id' => $this->unit->id, 'competence' => '2026-12'])->assertSessionHasNoErrors();
        $again = SusBpaFile::withoutTenantScope()->where('competence', '2026-12-01')->sole();
        $this->assertSame([1, 1], [$again->line_count, $again->account_count]);
        $this->assertSame(['202611', '0301010072'], [substr((string) Storage::disk('local')->get($again->path), 130 + 2 + 9, 6), DB::table('sus_bpa_file_lines')->where('sus_bpa_file_id', $again->id)->value('procedure_code')]);
        $this->assertSame(2, DB::table('sus_bpa_file_accounts')->where('billing_account_id', $rejected->id)->count());
        $this->assertSame([], BpaFile::accounts($this->unit->id)->all());

        // The database presents an item again only after a rejection.
        app(CurrentTenant::class)->set($this->unit->tenant);
        $item = (int) DB::table('sus_bpa_file_line_items')->where('sus_bpa_file_line_id', $second)->value('billing_account_item_id');
        foreach ([
            fn () => DB::table('sus_bpa_file_line_items')->insert(['tenant_id' => $this->unit->tenant_id, 'sus_bpa_file_line_id' => $first, 'billing_account_item_id' => $item]),
            fn () => DB::table('sus_bpa_file_accounts')->insert(['tenant_id' => $this->unit->tenant_id, 'sus_bpa_file_id' => $again->id, 'billing_account_id' => $approved->id]),
            fn () => DB::table('sus_bpa_line_returns')->update(['approved' => true]),
            fn () => DB::table('billing_accounts')->where('id', $approved->id)->update(['status' => 'open', 'closed_at' => null, 'closed_by_user_id' => null]),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('The database accepted it.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('P0001', $exception->getMessage());
            }
        }
        app(CurrentTenant::class)->set(null);
    }

    /**
     * A rejected BPA-C line sends back the items of each account it summed; an account whose rejected item billing
     * compensated (and replaced) is done with it: out of the queue, not reopened again.
     */
    public function test_a_rejected_consolidated_line_returns_the_items_of_each_account_but_a_compensated_one(): void
    {
        app(CurrentTenant::class)->set($this->unit->tenant);
        DB::statement("UPDATE sigtap_procedures SET registers = '{01}' WHERE code = '0301010072'");
        app(CurrentTenant::class)->set(null);
        $this->headerData();
        $corrected = $this->closedConsultation('08:20');
        $resent = $this->closedConsultation('08:40');
        $this->actingAs($this->billing->user)->post(route('sus-bpa.store'), ['unit_id' => $this->unit->id, 'competence' => '2026-11'])->assertSessionHasNoErrors();
        $file = SusBpaFile::withoutTenantScope()->sole();
        $line = DB::table('sus_bpa_file_lines')->sole();
        $this->assertSame(['C', 2], [$line->kind, $line->quantity]);
        $this->get(route('sus-bpa.show', $file))->assertInertia(fn (Assert $page) => $page->where('lines.0.accounts', [$corrected->id, $resent->id]));
        $this->post(route('sus-bpa.returns.store', $file), ['line_id' => $line->id, 'approved' => '0', 'reason' => 'Quantidade acima do teto.'])->assertSessionHasNoErrors();
        $this->assertSame([$corrected->id, $resent->id], BpaFile::accounts($this->unit->id)->modelKeys());

        // One account reopened and its rejected item compensated (the screen does not compensate SUS items: the
        // database rule is checked directly): that account is done with the rejection.
        $this->travelTo('2026-12-02 09:00:00');
        $this->post(route('billing-accounts.reopen', $corrected))->assertSessionHasNoErrors();
        $item = BillingAccountItem::withoutTenantScope()->where('billing_account_id', $corrected->id)->sole();
        app(CurrentTenant::class)->set($this->unit->tenant);
        DB::table('billing_account_items')->insert([
            'tenant_id' => $this->unit->tenant_id, 'billing_account_id' => $corrected->id, 'origin_type' => 'compensation', 'origin_id' => $item->id, 'code' => $item->code, 'name' => $item->name,
            'quantity' => -$item->quantity, 'unit_price_cents' => $item->unit_price_cents, 'needs_authorization' => false, 'performed_on' => '2026-12-02', 'reason' => 'Não realizado.', 'author_user_id' => $this->billing->user_id, 'created_at' => now(),
        ]);
        app(CurrentTenant::class)->set(null);
        $this->get(route('billing-accounts.show', $corrected))->assertInertia(fn (Assert $page) => $page->where('bpa.rejected', []));
        $this->assertSame([$resent->id], BpaFile::accounts($this->unit->id)->modelKeys());
        $this->post(route('billing-accounts.reopen', $resent))->assertSessionHasNoErrors();
        $this->post(route('billing-accounts.close', $resent))->assertSessionHasNoErrors();

        // The other account's item goes again, alone.
        $this->post(route('sus-bpa.store'), ['unit_id' => $this->unit->id, 'competence' => '2026-12'])->assertSessionHasNoErrors();
        $again = SusBpaFile::withoutTenantScope()->where('competence', '2026-12-01')->sole();
        $this->assertSame([1, 1], [$again->line_count, $again->account_count]);
        $this->assertSame(1, DB::table('sus_bpa_file_lines')->where('sus_bpa_file_id', $again->id)->value('quantity'));
        $this->assertSame([], BpaFile::accounts($this->unit->id)->all());
        $this->get(route('sus-bpa.show', $file))->assertInertia(fn (Assert $page) => $page->where('lines.0.editable', false));
    }
}
