<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\MedicationInteraction;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Network medication catalog and safety rules (step 4b.1a): maximum doses per presentation and
 * interactions between active ingredients, kept by the network admin. The audit keeps the rule values,
 * since they are network settings, not patient data.
 */
class MedicationController extends Controller
{
    private const LIMIT = 100;

    public function index(Request $request): Response
    {
        Gate::authorize('manage', Medication::class);
        // A non-text q (?q[]=x) is ignored rather than breaking the page.
        $search = is_string($q = $request->query('q')) ? Medication::key(mb_substr($q, 0, 255)) : '';
        $like = '%'.addcslashes($search, '%_\\').'%';

        return Inertia::render('medications/index', [
            'q' => $search,
            'total' => Medication::query()->count(),
            'limit' => self::LIMIT,
            'medications' => Medication::query()->when($search !== '', fn (Builder $query) => $query->where('ingredient_key', 'like', $like))
                ->orderByDesc('active')->orderBy('ingredient_key')->orderBy('form')->orderBy('strength')->limit(self::LIMIT)
                ->get(['id', 'active_ingredient', 'form', 'strength', 'dose_unit', 'routes', 'controlled', 'high_alert', 'max_single_dose', 'max_daily_dose', 'active', 'updated_at']),
            'totalInteractions' => MedicationInteraction::query()->count(),
            'interactions' => MedicationInteraction::query()
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $either) => $either->where('ingredient_a', 'like', $like)->orWhere('ingredient_b', 'like', $like)))
                ->orderByDesc('active')->orderBy('ingredient_a')->orderBy('ingredient_b')->limit(self::LIMIT)
                ->get(['id', 'ingredient_a', 'ingredient_b', 'severity', 'note', 'active', 'updated_at']),
            'ingredients' => Medication::query()->where('active', true)->distinct()->orderBy('ingredient_key')->pluck('ingredient_key'),
            'doseUnits' => Medication::DOSE_UNITS,
            'routes' => Medication::ROUTES,
            'severities' => Medication::SEVERITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $validated = self::validated($request, 'newMedication');

        self::unique(fn () => DB::transaction(function () use ($validated) {
            // Refreshed: the audit records the stored values (defaults and decimals as the database keeps them).
            $medication = Medication::create($validated)->refresh();
            AuditEvent::record('medication.created', $medication->tenant_id, $medication, self::rules($medication));
        }), 'newMedication');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Medicamento cadastrado.']);

        return back();
    }

    public function update(Request $request, Medication $medication): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $bag = "medication-{$medication->id}";
        $validated = self::validated($request, $bag, withActive: true);
        // The active ingredient is fixed: interactions refer to it. A new one is a new presentation.
        if (Medication::key($validated['active_ingredient']) !== $medication->ingredient_key) {
            throw ValidationException::withMessages(['active_ingredient' => 'O princípio ativo não muda. Desative esta apresentação e cadastre outra.'])->errorBag($bag);
        }

        self::unique(fn () => DB::transaction(function () use ($validated, $medication) {
            $medication->fill($validated);
            $changed = array_values(array_diff(array_keys($medication->getDirty()), ['updated_at', 'ingredient_key']));
            if ($changed !== []) {
                $medication->save();
                AuditEvent::record('medication.updated', $medication->tenant_id, $medication, ['fields' => $changed, ...self::rules($medication->refresh())]);
            }
        }), $bag);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Medicamento atualizado.']);

        return back();
    }

    /**
     * A pair of active ingredients of the catalog, stored once in byte order.
     */
    public function storeInteraction(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $data = $request->validateWithBag('newInteraction', [
            'ingredient_a' => ['required', 'string', 'max:255'],
            'ingredient_b' => ['required', 'string', 'max:255'],
            'severity' => ['required', Rule::in(array_keys(Medication::SEVERITIES))],
            'note' => ['required', 'string', 'max:500'],
        ], [], ['ingredient_a' => 'primeiro princípio ativo', 'ingredient_b' => 'segundo princípio ativo', 'severity' => 'gravidade', 'note' => 'orientação']);
        $pair = [Medication::key($data['ingredient_a']), Medication::key($data['ingredient_b'])];
        if ($pair[0] === $pair[1]) {
            throw ValidationException::withMessages(['ingredient_b' => 'Escolha dois princípios ativos diferentes.'])->errorBag('newInteraction');
        }
        $known = Medication::query()->whereIn('ingredient_key', $pair)->distinct()->pluck('ingredient_key')->all();
        foreach (['ingredient_a' => $pair[0], 'ingredient_b' => $pair[1]] as $field => $key) {
            if (! in_array($key, $known, true)) {
                throw ValidationException::withMessages([$field => 'Princípio ativo fora do catálogo. Cadastre o medicamento antes.'])->errorBag('newInteraction');
            }
        }
        sort($pair, SORT_STRING);

        try {
            DB::transaction(function () use ($pair, $data, $request) {
                $interaction = MedicationInteraction::create([
                    'ingredient_a' => $pair[0], 'ingredient_b' => $pair[1], 'severity' => $data['severity'], 'note' => trim($data['note']),
                    'created_by_user_id' => $request->user()->id,
                ]);
                AuditEvent::record('medication_interaction.created', $interaction->tenant_id, $interaction, ['pair' => $pair, 'severity' => $interaction->severity]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['ingredient_b' => 'Esta interação já está cadastrada. Altere a existente.'])->errorBag('newInteraction');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Interação cadastrada.']);

        return back();
    }

    public function updateInteraction(Request $request, MedicationInteraction $interaction): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $data = $request->validateWithBag("interaction-{$interaction->id}", [
            'severity' => ['required', Rule::in(array_keys(Medication::SEVERITIES))],
            'note' => ['required', 'string', 'max:500'],
            'active' => ['required', 'boolean'],
        ], [], ['severity' => 'gravidade', 'note' => 'orientação']);

        DB::transaction(function () use ($interaction, $data) {
            $interaction->fill([...$data, 'note' => trim($data['note'])]);
            $changed = array_values(array_diff(array_keys($interaction->getDirty()), ['updated_at']));
            if ($changed !== []) {
                $interaction->save();
                AuditEvent::record('medication_interaction.updated', $interaction->tenant_id, $interaction, ['fields' => $changed, 'severity' => $interaction->severity, 'active' => $interaction->active]);
            }
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Interação atualizada.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private static function validated(Request $request, string $bag, bool $withActive = false): array
    {
        $data = $request->validateWithBag($bag, [
            'active_ingredient' => ['required', 'string', 'max:255'],
            'form' => ['required', 'string', 'max:100'],
            'strength' => ['required', 'string', 'max:100'],
            'dose_unit' => ['required', Rule::in(Medication::DOSE_UNITS)],
            'routes' => ['required', 'array', 'min:1'],
            'routes.*' => ['distinct', Rule::in(array_keys(Medication::ROUTES))],
            'controlled' => ['boolean'],
            'high_alert' => ['boolean'],
            'max_single_dose' => ['nullable', 'decimal:0,3', 'gt:0', 'max:999999999'],
            // Only compared when both are given (gte would fail on an empty single maximum).
            'max_daily_dose' => ['nullable', 'decimal:0,3', 'gt:0', 'max:999999999', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                if (is_numeric($request->input('max_single_dose')) && (float) $value < (float) $request->input('max_single_dose')) {
                    $fail('A dose máxima por dia não pode ser menor que a dose máxima por administração.');
                }
            }],
            ...($withActive ? ['active' => ['required', 'boolean']] : []),
        ], [], [
            'active_ingredient' => 'princípio ativo', 'form' => 'forma', 'strength' => 'concentração', 'dose_unit' => 'unidade de dose',
            'routes' => 'vias', 'max_single_dose' => 'dose máxima por administração', 'max_daily_dose' => 'dose máxima por dia',
        ]);

        return [...$data, 'active_ingredient' => Medication::clean($data['active_ingredient']),
            'form' => Medication::clean($data['form']), 'strength' => Medication::clean($data['strength']), 'routes' => array_values($data['routes'])];
    }

    /**
     * The safety rule values, for the audit.
     *
     * @return array<string, mixed>
     */
    private static function rules(Medication $medication): array
    {
        return $medication->only('controlled', 'high_alert', 'max_single_dose', 'max_daily_dose', 'active');
    }

    private static function unique(callable $save, string $bag): void
    {
        try {
            $save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['active_ingredient' => 'Já existe esta apresentação (princípio ativo, forma e concentração).'])->errorBag($bag);
        }
    }
}
