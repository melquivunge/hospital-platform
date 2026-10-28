<?php

namespace App\Http\Controllers;

use App\Billing\SigtapImport;
use App\Billing\SigtapImportException;
use App\Models\Medication;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6b.3 (ADR-0011): the network admin imports the official SIGTAP zip of each competence (DATASUS) and sees the
 * competences kept; the SUS accounts take their procedures and values from it.
 */
class SigtapController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage', Medication::class);
        $competences = DB::table('sigtap_competences')->where('tenant_id', self::tenantId($request))->orderByDesc('competence')->get();
        // The network's time: its first unit's zone (the import is the network's, not a unit's).
        $timezone = Unit::query()->orderBy('id')->value('timezone') ?? 'UTC';
        $users = User::query()->whereKey($competences->pluck('imported_by_user_id')->unique()->all())->pluck('name', 'id');

        return Inertia::render('sigtap/index', [
            'competences' => $competences->map(fn ($competence) => [
                'id' => $competence->id,
                'competence' => CarbonImmutable::parse($competence->competence)->format('m/Y'),
                'fileName' => $competence->file_name,
                'procedures' => (int) $competence->procedure_count,
                'importedAt' => CarbonImmutable::parse($competence->imported_at)->setTimezone($timezone)->format('d/m/Y H:i'),
                'by' => $users->get($competence->imported_by_user_id),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:zip']], ['file.mimes' => 'Envie o ZIP oficial da SIGTAP (TabelaUnificada_AAAAMM_v....zip).'], ['file' => 'arquivo']);
        $file = $request->file('file');
        $path = (string) $file->getRealPath();
        try {
            $import = SigtapImport::read($path);
        } catch (SigtapImportException $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }
        $replaced = $import->apply(self::tenantId($request), $file->getClientOriginalName(), (string) hash_file('sha256', $path), $request->user());
        $month = CarbonImmutable::parse($import->competence)->format('m/Y');
        Inertia::flash('toast', ['type' => 'success', 'message' => ($replaced ? "SIGTAP {$month} substituída" : "SIGTAP {$month} importada").': '.count($import->procedures).' procedimentos.']);

        return to_route('sigtap.index');
    }

    private static function tenantId(Request $request): int
    {
        return (int) UnitPolicy::currentMembership($request->user())?->tenant_id;
    }
}
