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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
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
                ['text' => $text, 'lines' => $lines, 'sheets' => $sheets, 'accounts' => $presented, 'problems' => $problems] = BpaFile::build($unit, $data['competence'], $accounts);
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
