<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenancy\ProfessionalRequest;
use App\Models\AuditEvent;
use App\Models\Professional;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Professionals of the current tenant (step 3a). No delete: schedules and history will reference them.
 */
class ProfessionalController extends Controller
{
    private const FIELDS = ['id', 'name', 'base_unit_id', 'council', 'council_number', 'council_uf', 'external_id', 'email', 'phone'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Professional::class);

        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        $professionals = Professional::query()
            ->visibleTo($membership)
            ->with('baseUnit:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Professional $professional) => [
                ...$professional->only('id', 'name', 'council', 'council_number', 'council_uf'),
                'baseUnit' => $professional->baseUnit?->name,
            ]);

        return Inertia::render('professionals/index', [
            'professionals' => $professionals,
            'canCreate' => Gate::allows('create', Professional::class),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Professional::class);

        return Inertia::render('professionals/form', ['professional' => null, ...self::options($request)]);
    }

    public function store(ProfessionalRequest $request): RedirectResponse
    {
        $professional = self::saving(fn () => DB::transaction(function () use ($request) {
            $professional = Professional::create($request->safe()->except('base_unit_reason'));
            AuditEvent::record('professional.created', $professional->tenant_id, $professional, ['base_unit_id' => $professional->base_unit_id]);

            return $professional;
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profissional cadastrado.']);

        return to_route('professionals.show', $professional);
    }

    public function show(Professional $professional): Response
    {
        Gate::authorize('view', $professional);

        $unitNames = Unit::query()->pluck('name', 'id');
        $history = AuditEvent::query()
            ->where('tenant_id', $professional->tenant_id)
            ->where('subject_type', $professional->getMorphClass())
            ->where('subject_id', $professional->id)
            ->whereIn('action', ['professional.created', 'professional.base_unit_changed'])
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'from' => $unitNames->get($event->context['from'] ?? 0),
                'to' => $unitNames->get($event->context['to'] ?? $event->context['base_unit_id'] ?? 0),
                'reason' => $event->context['reason'] ?? null,
                'actor' => $event->actor?->name,
                'occurredAt' => $event->occurred_at->toIso8601String(),
            ]);

        return Inertia::render('professionals/show', [
            'professional' => [...$professional->only(self::FIELDS), 'baseUnit' => $professional->baseUnit?->name],
            'history' => $history,
            'canUpdate' => Gate::allows('update', $professional),
        ]);
    }

    public function edit(Request $request, Professional $professional): Response
    {
        Gate::authorize('update', $professional);

        return Inertia::render('professionals/form', ['professional' => $professional->only(self::FIELDS), ...self::options($request)]);
    }

    public function update(ProfessionalRequest $request, Professional $professional): RedirectResponse
    {
        self::saving(fn () => DB::transaction(function () use ($request, $professional) {
            // Locked and re-checked: a concurrent base change may have moved the professional out of
            // the editor's units, or turned this save into a base change without a reason.
            $professional = Professional::query()->lockForUpdate()->findOrFail($professional->id);
            Gate::authorize('update', $professional);
            $from = $professional->base_unit_id;

            if ($request->integer('base_unit_id') !== $from && ! $request->filled('base_unit_reason')) {
                throw ValidationException::withMessages(['base_unit_reason' => 'Informe o motivo da troca de unidade-base.']);
            }

            $professional->fill($request->safe()->except('base_unit_reason'));
            $changed = array_keys($professional->getDirty());
            $professional->save();

            if (in_array('base_unit_id', $changed, true)) {
                AuditEvent::record('professional.base_unit_changed', $professional->tenant_id, $professional, [
                    'from' => $from,
                    'to' => $professional->base_unit_id,
                    'reason' => $request->string('base_unit_reason')->value(),
                ]);
            }

            $otherFields = array_values(array_diff($changed, ['base_unit_id', 'updated_at']));

            if ($otherFields !== []) {
                AuditEvent::record('professional.updated', $professional->tenant_id, $professional, ['fields' => $otherFields]);
            }
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profissional atualizado.']);

        return to_route('professionals.show', $professional);
    }

    /**
     * @return array{units: list<array{id: int, name: string}>, ufs: list<string>}
     */
    private static function options(Request $request): array
    {
        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        return [
            'units' => array_values(Professional::manageableUnits($membership)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Unit $unit) => ['id' => $unit->id, 'name' => $unit->name])
                ->all()),
            'ufs' => Unit::UFS,
        ];
    }

    /**
     * Two concurrent saves with the same identifiers: the unique index wins, the user sees a field error.
     *
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     */
    private static function saving(callable $save): mixed
    {
        try {
            return $save();
        } catch (UniqueConstraintViolationException $exception) {
            $field = str_contains($exception->getMessage(), 'external_id') ? 'external_id' : 'council_uf';

            throw ValidationException::withMessages([$field => 'Já existe um profissional com estes dados.']);
        }
    }
}
