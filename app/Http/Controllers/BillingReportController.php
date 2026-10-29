<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\BillingLot;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Step 6.6 (ADR-0010): the billing reports of a unit, for its manager (and the network admin, who holds the role in
 * memory): production by modality in the period, the accounts pending now, the glosas by reason and what was received
 * in the period; a CSV of the same numbers. Numbers only: no patient, no account id.
 */
class BillingReportController extends Controller
{
    public const MODALITIES = ['private' => 'Particular', 'insurance' => 'Convênio', 'sus' => 'SUS'];

    private const STAGES = ['open' => 'Abertas (atendimento em curso ou em revisão)', 'ready' => 'Convênio fechadas, aguardando lote',
        'in_lot' => 'Convênio em lote, aguardando retorno', 'unpaid' => 'Particular fechadas, aguardando pagamento'];

    public function index(Request $request): Response
    {
        [$units, $unit, $from, $to] = $this->scope($request);
        $competence = self::competence($request, $unit);

        return Inertia::render('billing-reports/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(), 'competence' => $competence],
            ...self::report($unit, $from, $to),
            'sus' => self::sus($request, $unit, $competence),
        ]);
    }

    public function csv(Request $request): SymfonyResponse
    {
        [, $unit, $from, $to] = $this->scope($request);
        $report = self::report($unit, $from, $to);
        $money = fn (int $cents) => ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).','.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
        $rows = [
            ...array_map(fn ($row) => ['Produção', $row['group'], "{$row['accounts']} contas, {$row['pricelessItems']} itens sem preço", (string) $row['items'], $money($row['totalCents'])], $report['production']),
            ...array_map(fn ($row) => ['Contas pendentes', $row['label'], '', (string) $row['accounts'], $money($row['totalCents'])], $report['pending']),
            ...array_map(fn ($row) => ['Glosas', "{$row['code']} {$row['term']}", "em recurso {$money($row['appealingCents'])}, recuperado {$money($row['recoveredCents'])}", (string) $row['items'], $money($row['glossedCents'])], $report['glosas']),
            ...array_map(fn ($row) => ['Recebido', $row['group'], $row['label'], (string) $row['count'], $money($row['totalCents'])], $report['received']),
        ];
        $last = $to->subDay();
        AuditEvent::record('billing.report_exported', $unit->tenant_id, $unit, ['unit_id' => $unit->id, 'from' => $from->toDateString(), 'to' => $last->toDateString(), 'rows' => count($rows)]);

        return ReportController::csv('faturamento-'.Str::slug($unit->name)."-{$from->toDateString()}-a-{$last->toDateString()}.csv",
            ['Seção', 'Grupo', 'Detalhe', 'Quantidade', 'Valor (R$)'], $rows);
    }

    /**
     * The four sections, aggregated in SQL. Production: the items performed in the period (a compensation, negative,
     * cancels its item) by modality and insurer. Pending: the accounts now open, closed waiting for a lot, in a lot
     * without the insurer's return, and private closed without payment. Glosas: the returns recorded in the period by
     * TUSS table 38 code, with what is in appeal and what the appeals recovered. Received: private payments, the
     * insurers' paid returns and the appeals' recoveries recorded in the period.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function report(Unit $unit, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // The unit's days, bound in UTC (a zoned date would be bound as its wall-clock time).
        [$start, $end] = [$from->utc(), $to->utc()];
        $amount = 'i.quantity * coalesce(i.unit_price_cents, 0)';

        $production = DB::table('billing_account_items as i')->join('billing_accounts as a', 'a.id', '=', 'i.billing_account_id')
            ->where('a.unit_id', $unit->id)->where('i.performed_on', '>=', $from->toDateString())->where('i.performed_on', '<', $to->toDateString())
            ->groupBy('a.modality', 'a.insurer_id')
            ->selectRaw("a.modality, a.insurer_id, count(DISTINCT a.id) AS accounts, sum(sign(i.quantity)) AS items, sum({$amount}) AS total, coalesce(sum(sign(i.quantity)) FILTER (WHERE i.unit_price_cents IS NULL), 0) AS priceless")
            ->get();

        // Each account once, in its stage; an insurance account whose lot has the insurer's return is done ("returned").
        $totals = DB::table('billing_account_items as i')->join('billing_accounts as own', 'own.id', '=', 'i.billing_account_id')->where('own.unit_id', $unit->id)
            ->groupBy('i.billing_account_id')->selectRaw("i.billing_account_id, sum({$amount}) AS total");
        $pending = DB::query()->fromSub(DB::table('billing_accounts as a')->leftJoinSub($totals, 't', 't.billing_account_id', '=', 'a.id')->where('a.unit_id', $unit->id)
            ->selectRaw(<<<'SQL'
                CASE
                    WHEN a.status = 'open' THEN 'open'
                    WHEN a.modality = 'private' THEN CASE WHEN EXISTS (SELECT 1 FROM billing_payments p WHERE p.billing_account_id = a.id) THEN 'paid' ELSE 'unpaid' END
                    WHEN a.modality <> 'insurance' THEN 'other'
                    ELSE coalesce((SELECT CASE WHEN EXISTS (SELECT 1 FROM billing_item_returns r WHERE r.billing_lot_id = l.id) THEN 'returned' ELSE 'in_lot' END
                        FROM billing_lot_accounts la JOIN billing_lots l ON l.id = la.billing_lot_id
                        WHERE la.billing_account_id = a.id AND la.released_at IS NULL AND l.status = 'generated' LIMIT 1), 'ready')
                END AS stage, coalesce(t.total, 0) AS total
                SQL), 'accounts')
            ->groupBy('stage')->selectRaw('stage, count(*) AS accounts, sum(total) AS total')->get()->keyBy('stage');

        $glosas = DB::table('billing_item_returns as r')->join('billing_lots as l', 'l.id', '=', 'r.billing_lot_id')
            ->leftJoin('billing_glosa_appeal_items as ai', 'ai.billing_item_return_id', '=', 'r.id')
            ->where('l.unit_id', $unit->id)->where('r.glossed_cents', '>', 0)->where('r.recorded_at', '>=', $start)->where('r.recorded_at', '<', $end)
            ->groupBy('r.glosa_code')->orderByRaw('sum(r.glossed_cents) DESC')->orderBy('r.glosa_code')
            ->selectRaw('r.glosa_code, count(*) AS items, sum(r.glossed_cents) AS glossed')
            ->selectRaw('coalesce(sum(ai.appealed_cents) FILTER (WHERE ai.id IS NOT NULL AND ai.recovered_cents IS NULL), 0) AS appealing, coalesce(sum(ai.recovered_cents), 0) AS recovered')
            ->get();

        $payments = DB::table('billing_payments as p')->join('billing_accounts as a', 'a.id', '=', 'p.billing_account_id')->where('a.unit_id', $unit->id)
            ->where('p.received_at', '>=', $start)->where('p.received_at', '<', $end)->selectRaw('count(*) AS count, coalesce(sum(p.amount_cents), 0) AS total')->first();
        $paidReturns = DB::table('billing_item_returns as r')->join('billing_lots as l', 'l.id', '=', 'r.billing_lot_id')->where('l.unit_id', $unit->id)
            ->where('r.recorded_at', '>=', $start)->where('r.recorded_at', '<', $end)->groupBy('l.insurer_id')
            ->selectRaw('l.insurer_id, count(DISTINCT l.id) AS count, sum(r.paid_cents) AS total')->get();
        $recoveries = DB::table('billing_glosa_appeal_items as ai')->join('billing_item_returns as r', 'r.id', '=', 'ai.billing_item_return_id')
            ->join('billing_lots as l', 'l.id', '=', 'r.billing_lot_id')->where('l.unit_id', $unit->id)
            ->where('ai.result_recorded_at', '>=', $start)->where('ai.result_recorded_at', '<', $end)->groupBy('l.insurer_id')
            ->selectRaw('l.insurer_id, count(*) AS count, sum(ai.recovered_cents) AS total')->get();

        $insurers = Insurer::query()->whereKey($production->pluck('insurer_id')->merge($paidReturns->pluck('insurer_id'))->merge($recoveries->pluck('insurer_id'))->filter()->unique()->all())->pluck('name', 'id');
        $group = fn (?string $modality, $insurerId) => ($modality === null ? 'Sem modalidade' : (self::MODALITIES[$modality] ?? $modality)).($insurerId === null ? '' : ' · '.$insurers->get($insurerId));
        $codes = BillingLot::glosaCodes();

        return [
            'production' => self::sortedBy($production->map(fn ($row) => [
                'group' => $group($row->modality, $row->insurer_id), 'accounts' => (int) $row->accounts, 'items' => (int) $row->items,
                'totalCents' => (int) $row->total, 'pricelessItems' => (int) $row->priceless,
            ]), 'group'),
            'pending' => array_map(fn (string $stage) => [
                'label' => self::STAGES[$stage], 'accounts' => (int) ($pending->get($stage)->accounts ?? 0), 'totalCents' => (int) ($pending->get($stage)->total ?? 0),
            ], array_keys(self::STAGES)),
            'glosas' => array_values($glosas->map(fn ($row) => [
                'code' => $row->glosa_code, 'term' => $codes[$row->glosa_code] ?? '', 'items' => (int) $row->items, 'glossedCents' => (int) $row->glossed,
                'appealingCents' => (int) $row->appealing, 'recoveredCents' => (int) $row->recovered,
            ])->all()),
            'received' => [
                ['group' => 'Particular', 'label' => 'Pagamentos recebidos', 'count' => (int) $payments->count, 'totalCents' => (int) $payments->total],
                ...self::sortedBy($paidReturns->map(fn ($row) => ['group' => 'Convênio · '.$insurers->get($row->insurer_id), 'label' => 'Pago no retorno (lotes)', 'count' => (int) $row->count, 'totalCents' => (int) $row->total]), 'group'),
                ...self::sortedBy($recoveries->map(fn ($row) => ['group' => 'Convênio · '.$insurers->get($row->insurer_id), 'label' => 'Recuperado em recurso (itens)', 'count' => (int) $row->count, 'totalCents' => (int) $row->total]), 'group'),
            ],
        ];
    }

    /**
     * Step 6b.7c (user's decision, 29/10/2026): the SUS files of a competence (YYYY-MM) and the manager's returns, BPA
     * and AIH apart: quantity and SIGTAP value presented, approved, rejected and without return, and the rejections with
     * their reasons. A BPA line's quantity and value are its items'; an AIH counts one, valued by the items its account
     * had when the file was generated. The value the manager actually paid is not recorded. A reason may name the
     * diagnosis: the read of each rejected account is logged, as on the return screens.
     *
     * @return array<string, mixed>
     */
    private static function sus(Request $request, Unit $unit, string $competence): array
    {
        $latest = fn (string $table, string $key, string $id) => "LEFT JOIN LATERAL (SELECT approved, reason FROM {$table} WHERE {$key} = {$id} ORDER BY id DESC LIMIT 1) r ON true";
        $bindings = [$unit->id, "{$competence}-01"];
        $bpa = collect(DB::select(<<<SQL
            SELECT l.kind, l.sheet, l.sequence, l.procedure_code, l.quantity, r.approved, r.reason,
                (SELECT coalesce(sum(i.quantity * coalesce(i.unit_price_cents, 0)), 0) FROM sus_bpa_file_line_items li
                    JOIN billing_account_items i ON i.id = li.billing_account_item_id WHERE li.sus_bpa_file_line_id = l.id) AS total,
                (SELECT array_agg(DISTINCT i.billing_account_id) FROM sus_bpa_file_line_items li
                    JOIN billing_account_items i ON i.id = li.billing_account_item_id WHERE li.sus_bpa_file_line_id = l.id) AS accounts
            FROM sus_bpa_files f JOIN sus_bpa_file_lines l ON l.sus_bpa_file_id = f.id {$latest('sus_bpa_line_returns', 'sus_bpa_file_line_id', 'l.id')}
            WHERE f.unit_id = ? AND f.competence = ? ORDER BY l.sheet, l.sequence
            SQL, $bindings));
        $aih = collect(DB::select(<<<SQL
            SELECT a.aih_number, a.id AS account, r.approved, r.reason,
                (SELECT coalesce(sum(i.quantity * coalesce(i.unit_price_cents, 0)), 0) FROM billing_account_items i
                    WHERE i.billing_account_id = a.id AND i.created_at <= f.generated_at) AS total
            FROM sus_aih_files f JOIN sus_aih_file_accounts fa ON fa.sus_aih_file_id = f.id JOIN billing_accounts a ON a.id = fa.billing_account_id
                {$latest('sus_aih_returns', 'sus_aih_file_account_id', 'fa.id')}
            WHERE f.unit_id = ? AND f.competence = ? ORDER BY fa.id
            SQL, $bindings));

        $rejected = fn (Collection $rows) => $rows->filter(fn (stdClass $row) => $row->approved === false);
        $accounts = BillingAccount::query()->whereKey($rejected($aih)->pluck('account')
            ->merge($rejected($bpa)->flatMap(fn (stdClass $row) => array_map('intval', explode(',', trim((string) $row->accounts, '{}')))))->unique()->all())->get();
        $patients = Patient::query()->whereKey($accounts->pluck('patient_id')->unique()->all())->get()->keyBy('id');
        foreach ($accounts as $account) {
            ClinicalAccess::recordRead($request->user(), $patients->get($account->patient_id), $account);
        }

        $totals = fn (Collection $rows, callable $quantity) => array_map(fn (?bool $approved) => [
            'quantity' => (int) $rows->whereStrict('approved', $approved)->sum($quantity), 'totalCents' => (int) $rows->whereStrict('approved', $approved)->sum('total'),
        ], ['approved' => true, 'rejected' => false, 'waiting' => null]) + ['presented' => ['quantity' => (int) $rows->sum($quantity), 'totalCents' => (int) $rows->sum('total')]];

        return [
            'competences' => DB::table('sus_bpa_files')->where('unit_id', $unit->id)->select('competence')
                ->union(DB::table('sus_aih_files')->where('unit_id', $unit->id)->select('competence'))->orderByDesc('competence')->pluck('competence')
                ->map(fn ($day) => substr((string) $day, 0, 7))->all(),
            'bpa' => $totals($bpa, fn (stdClass $row) => (int) $row->quantity),
            'aih' => $totals($aih, fn () => 1),
            'rejections' => [
                ...$rejected($bpa)->map(fn (stdClass $row) => ['label' => "BPA-{$row->kind} folha {$row->sheet} linha {$row->sequence} · {$row->procedure_code}", 'quantity' => (int) $row->quantity, 'totalCents' => (int) $row->total, 'reason' => (string) $row->reason])->values(),
                ...$rejected($aih)->map(fn (stdClass $row) => ['label' => 'AIH '.($row->aih_number ?? 'sem número'), 'quantity' => 1, 'totalCents' => (int) $row->total, 'reason' => (string) $row->reason])->values(),
            ],
        ];
    }

    /** The chosen competence (YYYY-MM), else the unit's latest SUS file's, else the previous month in its zone. */
    private static function competence(Request $request, Unit $unit): string
    {
        $chosen = $request->query('competence');
        if (is_string($chosen) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $chosen) === 1) {
            return $chosen;
        }
        $latest = DB::table('sus_bpa_files')->where('unit_id', $unit->id)->max('competence');
        $aih = DB::table('sus_aih_files')->where('unit_id', $unit->id)->max('competence');

        return substr((string) (max($latest, $aih) ?? CarbonImmutable::now($unit->timezone ?? 'UTC')->subMonthNoOverflow()->toDateString()), 0, 7);
    }

    /**
     * @template TRow of array<string, mixed>
     *
     * @param  Collection<int, TRow>  $rows
     * @return list<TRow>
     */
    private static function sortedBy(Collection $rows, string $key): array
    {
        return array_values($rows->sortBy($key)->all());
    }

    /**
     * The person's units as a manager (the network admin holds the role in memory), the chosen one, and the period in
     * its time zone: an invalid or too long one falls back to the last 30 days.
     *
     * @return array{Collection<int, Unit>, Unit, CarbonImmutable, CarbonImmutable}
     */
    private function scope(Request $request): array
    {
        $units = Unit::query()->whereKey(StockInventoryController::managerUnitIds($request->user()))->orderBy('name')->get(['id', 'tenant_id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        [$from, $to] = StockReportController::period($request, $unit->timezone ?? 'UTC');

        return [$units, $unit, $from, $to];
    }
}
