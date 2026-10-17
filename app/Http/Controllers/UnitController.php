<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenancy\UnitRequest;
use App\Models\AuditEvent;
use App\Models\Sector;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Units (hospitals) of the current tenant. No delete: schedules will reference them (history).
 */
class UnitController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Unit::class);

        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        $units = Unit::query()
            ->visibleTo($membership)
            ->withCount('sectors')
            ->orderBy('name')
            ->get()
            ->map(fn (Unit $unit) => [
                ...$unit->only('id', 'name', 'uf', 'timezone'),
                'complete' => $unit->isComplete(),
                'sectorsCount' => $unit->sectors_count,
            ]);

        return Inertia::render('units/index', [
            'units' => $units,
            'canCreate' => Gate::allows('create', Unit::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Unit::class);

        return Inertia::render('units/form', ['unit' => null, ...self::options()]);
    }

    public function store(UnitRequest $request): RedirectResponse
    {
        $unit = self::saving(fn () => DB::transaction(function () use ($request) {
            $unit = Unit::create($request->validated());
            AuditEvent::record('unit.created', $unit->tenant_id, $unit);

            return $unit;
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unidade cadastrada.']);

        return to_route('units.show', $unit);
    }

    public function show(Unit $unit): Response
    {
        Gate::authorize('view', $unit);

        return Inertia::render('units/show', [
            'unit' => [...$unit->only('id', 'name', 'uf', 'timezone'), 'complete' => $unit->isComplete()],
            'sectors' => $unit->sectors()->orderBy('name')->get(['id', 'name']),
            'canUpdate' => Gate::allows('update', $unit),
            'canManageSectors' => Gate::allows('create', [Sector::class, $unit]),
        ]);
    }

    public function edit(Unit $unit): Response
    {
        Gate::authorize('update', $unit);

        return Inertia::render('units/form', ['unit' => $unit->only('id', 'name', 'uf', 'timezone'), ...self::options()]);
    }

    public function update(UnitRequest $request, Unit $unit): RedirectResponse
    {
        self::saving(fn () => DB::transaction(function () use ($request, $unit) {
            $unit->fill($request->validated());
            $changed = array_keys($unit->getDirty());
            $unit->save();

            if ($changed !== []) {
                AuditEvent::record('unit.updated', $unit->tenant_id, $unit, ['fields' => $changed]);
            }
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Unidade atualizada.']);

        return to_route('units.show', $unit);
    }

    /**
     * @return array{ufs: list<string>, timezones: list<string>}
     */
    private static function options(): array
    {
        return [
            'ufs' => Unit::UFS,
            'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'BR'),
        ];
    }

    /**
     * Two concurrent saves with the same name: the unique index wins, the user sees a field error.
     *
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     */
    public static function saving(callable $save): mixed
    {
        try {
            return $save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Este nome já está em uso.']);
        }
    }
}
