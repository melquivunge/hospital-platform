<?php

namespace App\Http\Controllers;

use App\Billing\BpaFile;
use App\Clinical\ClinicalAccess;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\Patient;
use App\Models\SusBpaFile;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Step 6b.6a2 (ADR-0011): billing generates the BPA file of a unit and processing competence, once, with every closed
 * SUS account of the BPA not presented yet, and downloads it to import into the BPA Magnético.
 */
class BpaFileController extends Controller
{
    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get();
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';
        $files = SusBpaFile::query()->where('unit_id', $unit->id)->orderByDesc('competence')->get();
        $users = User::query()->whereKey($files->pluck('generated_by_user_id')->unique()->all())->pluck('name', 'id');

        return Inertia::render('sus-bpa/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'ready' => BpaFile::accounts($unit->id)->count(),
            'competence' => now($timezone)->format('Y-m'),
            'files' => $files->map(fn (SusBpaFile $file) => [
                'id' => $file->id,
                'competence' => $file->competence->format('m/Y'),
                'lines' => $file->line_count,
                'sheets' => $file->sheet_count,
                'accounts' => $file->account_count,
                'generatedAt' => $file->generated_at->setTimezone($timezone)->format('d/m/Y H:i'),
                'by' => $users->get($file->generated_by_user_id),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer'],
            'competence' => ['required', 'date_format:Y-m'],
        ], [], ['competence' => 'competência']);
        $unitId = (int) $data['unit_id'];
        abort_unless(in_array($unitId, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $unit = Unit::query()->findOrFail($unitId);
        if ($data['competence'] > now($unit->timezone ?? 'UTC')->format('Y-m')) {
            throw ValidationException::withMessages(['competence' => 'A competência não pode estar no futuro.']);
        }

        try {
            $file = DB::transaction(function () use ($unit, $data, $request) {
                // One generation per unit at a time: the accounts chosen are the ones no file presented yet.
                Unit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
                if (SusBpaFile::query()->where('unit_id', $unit->id)->where('competence', "{$data['competence']}-01")->exists()) {
                    throw ValidationException::withMessages(['competence' => 'O BPA desta competência já foi gerado nesta unidade.']);
                }
                $accounts = BpaFile::accounts($unit->id);
                ['text' => $text, 'lines' => $lines, 'sheets' => $sheets, 'accounts' => $presented, 'rows' => $rows, 'problems' => $problems] = BpaFile::build($unit, $data['competence'], $accounts);
                if ($problems !== []) {
                    // One message, a problem per line (the page shows the first message of a field).
                    throw ValidationException::withMessages(['competence' => implode("\n", array_slice($problems, 0, 8)).(count($problems) > 8 ? "\n…" : '')]);
                }
                $path = "sus/bpa/{$unit->tenant_id}/{$unit->id}-".str_replace('-', '', $data['competence']).'.txt';
                Storage::disk('local')->put($path, $text);
                $file = new SusBpaFile;
                $file->forceFill([
                    'unit_id' => $unit->id, 'competence' => "{$data['competence']}-01", 'path' => $path, 'sha256' => hash('sha256', $text),
                    'line_count' => $lines, 'sheet_count' => $sheets, 'account_count' => count($presented),
                    'generated_by_user_id' => $request->user()->id, 'generated_at' => now(),
                ])->save();
                // Only the accounts the file carries: one done after the competence waits for a later file.
                DB::table('sus_bpa_file_accounts')->insert(array_map(fn (int $id) => [
                    'tenant_id' => $unit->tenant_id, 'sus_bpa_file_id' => $file->id, 'billing_account_id' => $id,
                ], $presented));
                // Step 6b.7b: each line and the items it carries, for the manager's return.
                foreach ($rows as $row) {
                    $line = DB::table('sus_bpa_file_lines')->insertGetId([
                        'tenant_id' => $unit->tenant_id, 'sus_bpa_file_id' => $file->id, 'kind' => $row['kind'], 'sheet' => $row['sheet'], 'sequence' => $row['sequence'],
                        'procedure_code' => $row['code'], 'done_competence' => $row['cmp'], 'quantity' => $row['quantity'],
                    ]);
                    DB::table('sus_bpa_file_line_items')->insert(array_map(fn (int $item) => [
                        'tenant_id' => $unit->tenant_id, 'sus_bpa_file_line_id' => $line, 'billing_account_item_id' => $item,
                    ], $row['items']));
                }
                // Ids and counts only.
                AuditEvent::record('sus_bpa_file.generated', $unit->tenant_id, $file, ['unit_id' => $unit->id, 'accounts' => count($presented), 'lines' => $lines, 'sha256' => $file->sha256]);

                return $file;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['competence' => 'O BPA desta competência já foi gerado nesta unidade.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "BPA gerado: {$file->line_count} linhas de {$file->account_count} contas."]);

        return to_route('sus-bpa.index', ['unit' => $unit->id]);
    }

    /**
     * Step 6b.7b: the lines of a file and the manager's return of each (no patient data: the procedure, the realization
     * competence, the quantity and the accounts). A file generated before this step has no lines.
     */
    public function show(Request $request, SusBpaFile $file): Response
    {
        abort_unless(in_array($file->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $unit = Unit::query()->findOrFail($file->unit_id);
        $timezone = $unit->timezone ?? 'UTC';
        $lines = DB::table('sus_bpa_file_lines')->where('sus_bpa_file_id', $file->id)->orderBy('sheet')->orderBy('sequence')->get();
        $items = self::lineItems($lines->map(fn (object $line) => (int) $line->id)->all());
        $returns = DB::table('sus_bpa_line_returns')->whereIn('sus_bpa_file_line_id', $lines->pluck('id'))->orderBy('id')->get()->keyBy('sus_bpa_file_line_id');
        // A rejection's reason may name the diagnosis: its read is logged, as each account's.
        $rejected = $items->whereIn('line', $lines->pluck('id')->filter(fn (int $id) => $returns->get($id)?->approved === false)->all());
        $accounts = BillingAccount::query()->whereKey($rejected->pluck('account')->unique()->all())->get();
        $patients = Patient::query()->whereKey($accounts->pluck('patient_id')->unique()->all())->get()->keyBy('id');
        foreach ($accounts as $account) {
            ClinicalAccess::recordRead($request->user(), $patients->get($account->patient_id), $account);
        }

        return Inertia::render('sus-bpa/show', [
            'file' => ['id' => $file->id, 'unit' => $unit->name, 'competence' => $file->competence->format('m/Y')],
            'lines' => $lines->map(function (object $line) use ($items, $returns, $timezone, $file) {
                $return = $returns->get($line->id);
                $own = $items->where('line', $line->id);

                return [
                    'id' => (int) $line->id,
                    'kind' => $line->kind,
                    'sheet' => (int) $line->sheet,
                    'sequence' => (int) $line->sequence,
                    'procedure' => $line->procedure_code,
                    'done' => substr($line->done_competence, 4, 2).'/'.substr($line->done_competence, 0, 4),
                    'quantity' => (int) $line->quantity,
                    'accounts' => $own->pluck('account')->unique()->sort()->values(),
                    'approved' => $return === null ? null : (bool) $return->approved,
                    'reason' => $return?->reason,
                    'recordedAt' => $return === null ? null : Carbon::parse($return->recorded_at)->setTimezone($timezone)->format('d/m/Y H:i'),
                    'editable' => self::open($file, $own),
                ];
            })->values(),
        ]);
    }

    /** Step 6b.7b: billing records the manager's return of a line of the file; the latest one counts. */
    public function recordReturn(Request $request, SusBpaFile $file): RedirectResponse
    {
        abort_unless(in_array($file->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $data = $request->validate([
            'line_id' => ['required', 'integer'],
            'approved' => ['required', 'boolean'],
            'reason' => ['nullable', 'required_if:approved,0', 'string', 'max:500'],
        ], ['reason.required_if' => 'Informe o motivo da rejeição.'], ['reason' => 'motivo']);

        DB::transaction(function () use ($request, $file, $data) {
            // The generation's lock: the queue it reads does not change under it.
            Unit::query()->whereKey($file->unit_id)->lockForUpdate()->firstOrFail();
            $line = DB::table('sus_bpa_file_lines')->where('sus_bpa_file_id', $file->id)->where('id', $data['line_id'])->first();
            abort_if($line === null, 404);
            $items = self::lineItems([(int) $line->id]);
            BillingAccount::query()->whereKey($items->pluck('account')->unique()->all())->orderBy('id')->lockForUpdate()->get();
            if (! self::open($file, self::lineItems([(int) $line->id]))) {
                throw ValidationException::withMessages(['reason' => 'Uma conta desta linha já foi reaberta ou apresentada de novo: o retorno desta linha não muda mais.'])->errorBag("return{$line->id}");
            }
            $approved = (bool) $data['approved'];
            DB::table('sus_bpa_line_returns')->insert([
                'tenant_id' => $file->tenant_id, 'sus_bpa_file_line_id' => $line->id, 'approved' => $approved,
                'reason' => $approved ? null : trim((string) $data['reason']), 'recorded_by_user_id' => $request->user()->id, 'recorded_at' => now(),
            ]);
            // Ids only: the reason may name the diagnosis.
            AuditEvent::record('sus_bpa_return.recorded', $file->tenant_id, $file, ['sus_bpa_file_line_id' => (int) $line->id, 'approved' => $approved]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Retorno registrado.']);

        return back();
    }

    /**
     * The items of the lines, with the account, its state and whether a later line carries the item again.
     *
     * @param  array<int, int>  $lines
     * @return Collection<int, stdClass>
     */
    private static function lineItems(array $lines): Collection
    {
        return DB::table('sus_bpa_file_line_items AS li')->join('billing_account_items AS i', 'i.id', '=', 'li.billing_account_item_id')
            ->join('billing_accounts AS a', 'a.id', '=', 'i.billing_account_id')->whereIn('li.sus_bpa_file_line_id', $lines)
            ->get(['li.sus_bpa_file_line_id AS line', 'a.id AS account', 'a.status', 'a.closed_at', DB::raw('EXISTS (SELECT 1 FROM sus_bpa_file_line_items x WHERE x.billing_account_item_id = li.billing_account_item_id AND x.sus_bpa_file_line_id > li.sus_bpa_file_line_id) AS later')]);
    }

    /**
     * Whether a line's return may still be recorded: none of its items was presented again, and none of its accounts
     * was reopened since the file (closed again after it) nor is open now.
     *
     * @param  Collection<int, stdClass>  $items
     */
    private static function open(SusBpaFile $file, Collection $items): bool
    {
        return $items->isNotEmpty() && $items->every(fn (stdClass $item) => ! $item->later && $item->status === 'closed' && is_string($item->closed_at)
            && Carbon::parse($item->closed_at)->lte($file->generated_at));
    }

    /** The file as generated (its hash checked), for the BPA Magnético; each presented account's read is logged. */
    public function download(Request $request, SusBpaFile $file): SymfonyResponse
    {
        abort_unless(in_array($file->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        abort_unless(str_starts_with($file->path, "sus/bpa/{$file->tenant_id}/") && ! str_contains($file->path, '..') && Storage::disk('local')->exists($file->path), 404);
        $text = (string) Storage::disk('local')->get($file->path);
        abort_if(hash('sha256', $text) !== $file->sha256, 409, 'O arquivo guardado não confere com o gerado.');
        $accounts = BillingAccount::query()->whereIn('id', DB::table('sus_bpa_file_accounts')->where('sus_bpa_file_id', $file->id)->select('billing_account_id'))->get();
        $patients = Patient::query()->whereKey($accounts->pluck('patient_id')->unique()->all())->get()->keyBy('id');
        foreach ($accounts as $account) {
            ClinicalAccess::recordRead($request->user(), $patients->get($account->patient_id), $account);
        }
        AuditEvent::record('sus_bpa_file.downloaded', $file->tenant_id, $file, ['sha256' => $file->sha256]);
        $cnes = Unit::query()->whereKey($file->unit_id)->value('cnes');

        return response($text, 200, [
            'Content-Type' => 'text/plain; charset=US-ASCII',
            'Content-Disposition' => 'attachment; filename="BPA-'.$cnes.'-'.$file->competence->format('Ym').'.txt"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
