<?php

namespace App\Http\Controllers;

use App\Billing\AihFile;
use App\Clinical\ClinicalAccess;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\Patient;
use App\Models\SusAihFile;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Step 6b.6b2b2 (ADR-0011): billing generates the SISAIH01 import file of a unit and presentation competence, once, with
 * every closed AIH account discharged until then and not presented yet, and downloads it to import into the SISAIH01.
 */
class AihFileController extends Controller
{
    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get();
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';
        $files = SusAihFile::query()->where('unit_id', $unit->id)->orderByDesc('competence')->get();
        $users = User::query()->whereKey($files->pluck('generated_by_user_id')->unique()->all())->pluck('name', 'id');

        return Inertia::render('sus-aih/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'ready' => AihFile::accounts($unit->id)->count(),
            'competence' => now($timezone)->format('Y-m'),
            'files' => $files->map(fn (SusAihFile $file) => [
                'id' => $file->id,
                'competence' => $file->competence->format('m/Y'),
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
                if (SusAihFile::query()->where('unit_id', $unit->id)->where('competence', "{$data['competence']}-01")->exists()) {
                    throw ValidationException::withMessages(['competence' => 'O arquivo da AIH desta competência já foi gerado nesta unidade.']);
                }
                $accounts = AihFile::accounts($unit->id);
                ['text' => $text, 'accounts' => $presented, 'problems' => $problems] = AihFile::build($unit, $data['competence'], $accounts);
                if ($problems !== []) {
                    // One message, a problem per line (the page shows the first message of a field).
                    throw ValidationException::withMessages(['competence' => implode("\n", array_slice($problems, 0, 8)).(count($problems) > 8 ? "\n…" : '')]);
                }
                $path = "sus/aih/{$unit->tenant_id}/{$unit->id}-".str_replace('-', '', $data['competence']).'.txt';
                Storage::disk('local')->put($path, $text);
                $file = new SusAihFile;
                $file->forceFill([
                    'unit_id' => $unit->id, 'competence' => "{$data['competence']}-01", 'path' => $path, 'sha256' => hash('sha256', $text),
                    'account_count' => count($presented),
                    'generated_by_user_id' => $request->user()->id, 'generated_at' => now(),
                ])->save();
                // Only the accounts the file carries: one discharged after the competence waits for a later file.
                DB::table('sus_aih_file_accounts')->insert(array_map(fn (int $id) => [
                    'tenant_id' => $unit->tenant_id, 'sus_aih_file_id' => $file->id, 'billing_account_id' => $id,
                ], $presented));
                // Ids and counts only.
                AuditEvent::record('sus_aih_file.generated', $unit->tenant_id, $file, ['unit_id' => $unit->id, 'accounts' => count($presented), 'sha256' => $file->sha256]);

                return $file;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['competence' => 'O arquivo da AIH desta competência já foi gerado nesta unidade.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Arquivo da AIH gerado: {$file->account_count} AIHs."]);

        return to_route('sus-aih.index', ['unit' => $unit->id]);
    }

    /**
     * Step 6b.7a: the AIHs a file presented and the manager's return of each (no patient data: the AIH number and the
     * account). A return is recorded while the AIH was not presented again.
     */
    public function show(Request $request, SusAihFile $file): Response
    {
        abort_unless(in_array($file->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $unit = Unit::query()->findOrFail($file->unit_id);
        $timezone = $unit->timezone ?? 'UTC';
        $rows = DB::table('sus_aih_file_accounts AS fa')->join('billing_accounts AS a', 'a.id', '=', 'fa.billing_account_id')
            ->where('fa.sus_aih_file_id', $file->id)->orderBy('fa.id')
            ->get(['fa.id', 'fa.billing_account_id', 'a.patient_id', 'a.aih_number', 'a.status', 'a.closed_at', DB::raw('(SELECT max(x.id) FROM sus_aih_file_accounts x WHERE x.billing_account_id = fa.billing_account_id) AS latest')]);
        $returns = DB::table('sus_aih_returns')->whereIn('sus_aih_file_account_id', $rows->pluck('id'))->orderBy('id')->get()->keyBy('sus_aih_file_account_id');
        // A rejection's reason may name the diagnosis: its read is logged, as the account's.
        $rejected = $rows->filter(fn (object $row) => $returns->get($row->id)?->approved === false);
        $patients = Patient::query()->whereKey($rejected->pluck('patient_id')->unique()->all())->get()->keyBy('id');
        foreach (BillingAccount::query()->whereKey($rejected->pluck('billing_account_id')->all())->get() as $account) {
            ClinicalAccess::recordRead($request->user(), $patients->get($account->patient_id), $account);
        }

        return Inertia::render('sus-aih/show', [
            'file' => ['id' => $file->id, 'unitId' => $unit->id, 'unit' => $unit->name, 'competence' => $file->competence->format('m/Y')],
            'aihs' => $rows->map(function (object $row) use ($returns, $timezone, $file) {
                $return = $returns->get($row->id);

                return [
                    'id' => (int) $row->id,
                    'accountId' => (int) $row->billing_account_id,
                    'number' => $row->aih_number,
                    'approved' => $return === null ? null : (bool) $return->approved,
                    'reason' => $return?->reason,
                    'recordedAt' => $return === null ? null : Carbon::parse($return->recorded_at)->setTimezone($timezone)->format('d/m/Y H:i'),
                    'editable' => self::open($file, (int) $row->id, (int) $row->latest, $row->status, $row->closed_at),
                ];
            })->values(),
        ]);
    }

    /** Step 6b.7a: billing records the manager's return of an AIH of the file; the latest one counts. */
    public function recordReturn(Request $request, SusAihFile $file): RedirectResponse
    {
        abort_unless(in_array($file->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $data = $request->validate([
            'presentation_id' => ['required', 'integer'],
            'approved' => ['required', 'boolean'],
            'reason' => ['nullable', 'required_if:approved,0', 'string', 'max:500'],
        ], ['reason.required_if' => 'Informe o motivo da rejeição.'], ['reason' => 'motivo']);

        DB::transaction(function () use ($request, $file, $data) {
            // The generation's lock: the queue it reads does not change under it.
            Unit::query()->whereKey($file->unit_id)->lockForUpdate()->firstOrFail();
            $presentation = DB::table('sus_aih_file_accounts')->where('sus_aih_file_id', $file->id)->where('id', $data['presentation_id'])->first();
            abort_if($presentation === null, 404);
            $account = BillingAccount::query()->lockForUpdate()->whereKey($presentation->billing_account_id)->firstOrFail();
            $latest = DB::table('sus_aih_file_accounts')->where('billing_account_id', $account->id)->max('id');
            if (! self::open($file, (int) $presentation->id, (int) $latest, $account->status, $account->closed_at)) {
                throw ValidationException::withMessages(['reason' => 'Esta AIH já foi reaberta ou apresentada de novo: o retorno desta apresentação não muda mais.'])->errorBag("return{$presentation->id}");
            }
            $approved = (bool) $data['approved'];
            DB::table('sus_aih_returns')->insert([
                'tenant_id' => $file->tenant_id, 'sus_aih_file_account_id' => $presentation->id, 'approved' => $approved,
                'reason' => $approved ? null : trim((string) $data['reason']), 'recorded_by_user_id' => $request->user()->id, 'recorded_at' => now(),
            ]);
            // Ids only: the reason may name the diagnosis.
            AuditEvent::record('sus_aih_return.recorded', $file->tenant_id, $account, ['sus_aih_file_id' => $file->id, 'approved' => $approved]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Retorno registrado.']);

        return back();
    }

    /**
     * Whether a presentation's return may still be recorded: it is the account's latest, and the account was not
     * reopened since (closed again after the file was generated) nor is open now.
     */
    private static function open(SusAihFile $file, int $presentation, int $latest, ?string $status, mixed $closedAt): bool
    {
        return $presentation === $latest && $status === 'closed' && $closedAt !== null && Carbon::parse($closedAt)->lte($file->generated_at);
    }

    /** The file as generated (its hash checked), for the SISAIH01; each presented account's read is logged. */
    public function download(Request $request, SusAihFile $file): SymfonyResponse
    {
        abort_unless(in_array($file->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        abort_unless(str_starts_with($file->path, "sus/aih/{$file->tenant_id}/") && ! str_contains($file->path, '..') && Storage::disk('local')->exists($file->path), 404);
        $text = (string) Storage::disk('local')->get($file->path);
        abort_if(hash('sha256', $text) !== $file->sha256, 409, 'O arquivo guardado não confere com o gerado.');
        $accounts = BillingAccount::query()->whereIn('id', DB::table('sus_aih_file_accounts')->where('sus_aih_file_id', $file->id)->select('billing_account_id'))->get();
        $patients = Patient::query()->whereKey($accounts->pluck('patient_id')->unique()->all())->get()->keyBy('id');
        foreach ($accounts as $account) {
            ClinicalAccess::recordRead($request->user(), $patients->get($account->patient_id), $account);
        }
        AuditEvent::record('sus_aih_file.downloaded', $file->tenant_id, $file, ['sha256' => $file->sha256]);
        $cnes = Unit::query()->whereKey($file->unit_id)->value('cnes');

        return response($text, 200, [
            'Content-Type' => 'text/plain; charset=US-ASCII',
            'Content-Disposition' => 'attachment; filename="AIH-'.$cnes.'-'.$file->competence->format('Ym').'.txt"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
