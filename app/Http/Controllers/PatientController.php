<?php

namespace App\Http\Controllers;

use App\Clinical\PatientIdentity;
use App\Http\Requests\Clinical\PatientRequest;
use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\PatientMerge;
use App\Models\Unit;
use App\Policies\PatientPolicy;
use App\Policies\UnitPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patients of the network (step 3.2). The registry is searched, never listed whole: the front desk sees
 * only what it needs to tell people apart. No delete: clinical history will reference them.
 */
class PatientController extends Controller
{
    private const SEARCH_LIMIT = 20;

    public function index(): Response
    {
        Gate::authorize('viewAny', Patient::class);

        return self::results('', []);
    }

    /**
     * Sent in the request body, never in the URL: names, documents and birth dates stay out of server
     * logs and browser history (delivery 3 contract).
     */
    public function search(Request $request): Response
    {
        Gate::authorize('viewAny', Patient::class);
        $term = is_string($request->input('q')) ? trim($request->input('q')) : '';
        $query = self::matching($term);

        return self::results($term, $query === null ? [] : array_values($query->limit(self::SEARCH_LIMIT)->get()->map(fn (Patient $patient) => self::summary($patient))->all()));
    }

    /**
     * @param  list<array<string, mixed>>  $patients
     */
    private static function results(string $term, array $patients): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('patients/index', [
            'q' => $term,
            'searched' => $term !== '',
            'patients' => $patients,
            'canCreate' => Gate::allows('create', Patient::class),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Patient::class);
        $duplicates = Patient::query()->whereKey((array) $request->session()->get('patient_duplicates', []))->with('registeredUnit:id,name')->get();

        return Inertia::render('patients/form', [
            'patient' => null,
            'units' => self::units($request),
            'duplicates' => $duplicates->map(fn (Patient $patient) => self::summary($patient)),
        ]);
    }

    public function store(PatientRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('confirm_new');
        $duplicates = Patient::query()->possibleDuplicatesOf($data['name'], $data['birth_date'] ?? null)->limit(5)->pluck('id');

        // Suspicion is shown, never resolved on its own: the person confirms it is someone else.
        if ($duplicates->isNotEmpty() && ! $request->boolean('confirm_new')) {
            $request->session()->flash('patient_duplicates', $duplicates->all());

            throw ValidationException::withMessages(['confirm_new' => 'Encontramos cadastros parecidos. Confira se não é a mesma pessoa antes de continuar.']);
        }

        $patient = self::saving(fn () => DB::transaction(function () use ($data, $request, $duplicates) {
            $patient = new Patient($data);
            $patient->forceFill(['created_by_user_id' => $request->user()->id])->save();
            AuditEvent::record('patient.created', $patient->tenant_id, $patient, array_filter([
                'unit_id' => $patient->registered_unit_id,
                'status' => $patient->status,
                'confirmed_not_duplicate_of' => $duplicates->all() ?: null,
            ]));

            return $patient;
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Paciente cadastrado.']);

        return to_route('patients.show', $patient);
    }

    public function show(Patient $patient): Response|RedirectResponse
    {
        Gate::authorize('view', $patient);
        if ($patient->merged_into_id !== null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Este cadastro foi unificado. Você está vendo o cadastro principal.']);

            return to_route('patients.show', $patient->merged_into_id);
        }
        // Full documents on screen: kept encrypted in the browser history, not readable after the session.
        Inertia::encryptHistory();
        $patient->load('registeredUnit:id,name');

        return Inertia::render('patients/show', [
            'patient' => [
                ...self::summary($patient),
                ...$patient->only('mother_name', 'phone', 'email', 'guardian_name', 'guardian_relationship', 'guardian_phone'),
                'cpf' => $patient->cpf,
                'cns' => $patient->cns,
            ],
            'unifiedFrom' => PatientMerge::query()->where('primary_patient_id', $patient->id)->whereNull('undone_at')->with('duplicate')->get()
                ->map(fn (PatientMerge $merge) => [
                    'id' => $merge->duplicate_patient_id,
                    'name' => $merge->duplicate?->name,
                    'birthDate' => $merge->duplicate?->birth_date?->toDateString(),
                    'cpf' => PatientIdentity::maskCpf($merge->duplicate?->cpf),
                ]),
            'canUpdate' => Gate::allows('update', $patient),
        ]);
    }

    public function edit(Request $request, Patient $patient): Response
    {
        Gate::authorize('update', $patient);
        Inertia::encryptHistory();

        return Inertia::render('patients/form', [
            'patient' => [
                ...$patient->only('id', 'registered_unit_id', 'name', 'social_name', 'sex', 'cpf', 'cns', 'mother_name', 'phone', 'email', 'guardian_name', 'guardian_relationship', 'guardian_phone'),
                'birth_date' => $patient->birth_date?->toDateString(),
            ],
            'units' => self::units($request, $patient->registered_unit_id),
            'duplicates' => [],
        ]);
    }

    public function update(PatientRequest $request, Patient $patient): RedirectResponse
    {
        self::saving(fn () => DB::transaction(function () use ($request, $patient) {
            $patient->fill($request->safe()->except('confirm_new'));
            // Field names only: the trail keeps who changed what, not the personal data itself.
            $changed = array_values(array_diff(array_keys($patient->getDirty()), ['name_key', 'status', 'updated_at']));
            $patient->save();
            if ($changed !== []) {
                AuditEvent::record('patient.updated', $patient->tenant_id, $patient, ['fields' => $changed, 'status' => $patient->status]);
            }
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cadastro atualizado.']);

        return to_route('patients.show', $patient);
    }

    /**
     * Eleven digits: CPF; fifteen: CNS; dd/mm/yyyy: birth date; otherwise the words of the name, at
     * least one with 3 letters. Anything else finds nobody: the registry is never listed whole.
     *
     * @return Builder<Patient>|null
     */
    private static function matching(string $term): ?Builder
    {
        $digits = (string) PatientIdentity::digits($term);
        // Merged registrations never show up themselves; their documents lead to the primary one.
        $query = Patient::query()->whereNull('merged_into_id')->with('registeredUnit:id,name')->orderBy('name_key')->orderBy('id');

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $term) === 1) {
            $date = CarbonImmutable::createFromFormat('!d/m/Y', $term);

            // 31/02 must not roll over to March: only a date that reads back the same is searched.
            return $date === null || $date->format('d/m/Y') !== $term ? null : $query->where('birth_date', $date->toDateString());
        }

        if (preg_match('/^[\d.\-\s]+$/', $term) === 1) {
            if (! in_array(strlen($digits), [11, 15], true)) {
                return null;
            }
            $field = strlen($digits) === 11 ? 'cpf' : 'cns';

            return $query->where(fn (Builder $match) => $match->where($field, $digits)
                ->orWhereIn('id', Patient::query()->where($field, $digits)->whereNotNull('merged_into_id')->select('merged_into_id')));
        }

        $words = array_values(array_filter(explode(' ', PatientIdentity::nameKey($term))));
        if (array_filter($words, fn (string $word) => strlen($word) >= 3) === []) {
            return null;
        }
        foreach ($words as $word) {
            $query->where('name_key', 'like', '%'.addcslashes($word, '%_\\').'%');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(Patient $patient): array
    {
        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'socialName' => $patient->social_name,
            'birthDate' => $patient->birth_date?->toDateString(),
            'sex' => $patient->sex,
            'status' => $patient->status,
            'cpf' => PatientIdentity::maskCpf($patient->cpf),
            'cns' => PatientIdentity::maskCns($patient->cns),
            'unit' => $patient->registeredUnit?->name,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function units(Request $request, ?int $current = null): array
    {
        $membership = UnitPolicy::currentMembership($request->user());
        $ids = $membership === null ? [] : PatientPolicy::registeringUnitIds($membership);

        return array_values(Unit::query()->whereKey([...$ids, ...array_filter([$current])])->orderBy('name')->get(['id', 'name'])
            ->map(fn (Unit $unit) => ['id' => $unit->id, 'name' => $unit->name])->all());
    }

    /**
     * Two desks registering the same document at once: the unique index decides, with a clear message.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function saving(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['cpf' => 'Já existe um paciente com este CPF ou Cartão Nacional de Saúde. Busque o cadastro existente.']);
        }
    }
}
