<?php

namespace App\Http\Controllers;

use App\Clinical\PatientIdentity;
use App\Models\Patient;
use App\Models\PatientMerge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Merging duplicate registrations (step 3.3): a queue of suspicions, the decision with a reason, and
 * the undoing of recent merges. Identity data only.
 */
class PatientMergeController extends Controller
{
    private const QUEUE_LIMIT = 50;

    public function index(): Response
    {
        Gate::authorize('merge', PatientMerge::class);
        Inertia::encryptHistory();

        // Same birth date and same name, or same first and last names; pairs already judged different
        // (a merge that was undone) do not come back.
        $rows = DB::select(<<<'SQL'
            SELECT a.id AS a, b.id AS b FROM patients a
            JOIN patients b ON b.tenant_id = a.tenant_id AND b.id > a.id AND b.birth_date = a.birth_date
            WHERE a.merged_into_id IS NULL AND b.merged_into_id IS NULL
              AND (a.name_key = b.name_key OR (split_part(a.name_key, ' ', 1) = split_part(b.name_key, ' ', 1)
                   AND regexp_replace(a.name_key, '^.* ', '') = regexp_replace(b.name_key, '^.* ', '')))
              AND NOT EXISTS (SELECT 1 FROM patient_merges m WHERE m.undone_at IS NOT NULL
                   AND ((m.primary_patient_id = a.id AND m.duplicate_patient_id = b.id) OR (m.primary_patient_id = b.id AND m.duplicate_patient_id = a.id)))
            ORDER BY a.id, b.id LIMIT ?
            SQL, [self::QUEUE_LIMIT]);
        /** @var list<array{int, int}> $pairs */
        $pairs = array_map(fn (\stdClass $row) => [(int) $row->a, (int) $row->b], $rows);
        $patients = Patient::query()->whereKey(array_unique(array_merge(...array_map(fn (array $pair) => $pair, $pairs ?: [[]]))))
            ->with('registeredUnit:id,name')->get()->keyBy('id');

        $merges = PatientMerge::query()->whereNull('undone_at')->with(['primary.registeredUnit:id,name', 'duplicate.registeredUnit:id,name', 'mergedBy:id,name'])
            ->latest('merged_at')->limit(20)->get();

        return Inertia::render('patients/merges', [
            'pairs' => array_map(fn (array $pair) => [self::identity($patients->get($pair[0])), self::identity($patients->get($pair[1]))], $pairs),
            'merges' => $merges->map(fn (PatientMerge $merge) => [
                'id' => $merge->id,
                'primary' => self::identity($merge->primary),
                'duplicate' => self::identity($merge->duplicate),
                'reason' => $merge->reason,
                'by' => $merge->mergedBy?->name,
                'at' => $merge->merged_at->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('merge', PatientMerge::class);
        $data = $request->validate([
            'a_id' => ['required', 'integer'],
            'b_id' => ['required', 'integer', 'different:a_id'],
            'primary_id' => ['required', 'integer', 'in:'.$request->integer('a_id').','.$request->integer('b_id')],
            'reason' => ['required', 'string', 'max:1000'],
        ], ['primary_id.in' => 'Escolha um dos dois cadastros como principal.'], ['primary_id' => 'cadastro principal', 'reason' => 'motivo']);
        // The one not chosen as primary is the duplicate.
        $duplicateId = (int) $data['primary_id'] === (int) $data['a_id'] ? (int) $data['b_id'] : (int) $data['a_id'];

        Patient::merge(Patient::query()->findOrFail((int) $data['primary_id']), Patient::query()->findOrFail($duplicateId), (string) $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cadastros unificados.']);

        return to_route('patient-merges.index');
    }

    public function undo(Request $request, PatientMerge $merge): RedirectResponse
    {
        Gate::authorize('merge', PatientMerge::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'motivo']);

        Patient::undoMerge($merge, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unificação desfeita.']);

        return to_route('patient-merges.index');
    }

    /**
     * @return array<string, mixed>
     */
    private static function identity(?Patient $patient): array
    {
        return $patient === null ? [] : [
            'id' => $patient->id,
            'name' => $patient->name,
            'socialName' => $patient->social_name,
            'birthDate' => $patient->birth_date?->toDateString(),
            'motherName' => $patient->mother_name,
            'cpf' => PatientIdentity::maskCpf($patient->cpf),
            'cns' => PatientIdentity::maskCns($patient->cns),
            'unit' => $patient->registeredUnit?->name,
            'status' => $patient->status,
        ];
    }
}
