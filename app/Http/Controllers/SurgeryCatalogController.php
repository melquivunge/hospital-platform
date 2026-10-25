<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Material;
use App\Models\Medication;
use App\Models\OperatingRoom;
use App\Models\SurgicalProcedure;
use App\Models\Unit;
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
 * The network surgical procedures and the operating rooms of each unit (step 5b.1a, delivery 5b), and the network
 * materials (step 5b.6a), kept by the network admin. Deactivated, never deleted; a room never changes unit, since
 * surgeries will be booked in it. No patient data.
 */
class SurgeryCatalogController extends Controller
{
    private const LIMIT = 100;

    public function index(Request $request): Response
    {
        Gate::authorize('manage', SurgicalProcedure::class);
        $search = is_string($q = $request->query('q')) ? Medication::key(mb_substr($q, 0, 255)) : '';
        $materialSearch = is_string($m = $request->query('m')) ? Medication::key(mb_substr($m, 0, 255)) : '';

        return Inertia::render('surgery-catalog/index', [
            'q' => $search,
            'total' => SurgicalProcedure::query()->count(),
            'limit' => self::LIMIT,
            'procedures' => SurgicalProcedure::query()
                ->when($search !== '', fn (Builder $query) => $query->where('name_key', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orderByDesc('active')->orderBy('name_key')->limit(self::LIMIT)
                ->get(['id', 'name', 'code', 'duration_minutes', 'size', 'active']),
            'sizes' => SurgicalProcedure::SIZES,
            'units' => self::units(),
            'm' => $materialSearch,
            'materialTotal' => Material::query()->count(),
            'materials' => Material::query()
                ->when($materialSearch !== '', fn (Builder $query) => $query->where('name_key', 'like', '%'.addcslashes($materialSearch, '%_\\').'%'))
                ->orderByDesc('active')->orderBy('name_key')->limit(self::LIMIT)
                ->get(['id', 'name', 'code', 'stock_unit', 'active']),
            'stockUnits' => Material::STOCK_UNITS,
        ]);
    }

    /**
     * The units of the network with their rooms, in two queries.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function units(): array
    {
        $rooms = OperatingRoom::query()->orderByDesc('active')->orderBy('name_key')->get(['id', 'unit_id', 'name', 'active'])->groupBy('unit_id');

        return Unit::query()->orderBy('name')->get(['id', 'name'])->map(fn (Unit $unit) => [
            ...$unit->only('id', 'name'),
            'rooms' => ($rooms->get($unit->id) ?? collect())->map->only('id', 'name', 'active')->values(),
        ])->values()->all();
    }

    public function storeProcedure(Request $request): RedirectResponse
    {
        Gate::authorize('manage', SurgicalProcedure::class);
        $data = self::procedure($request, 'newProcedure');
        self::unique(function () use ($data) {
            $procedure = SurgicalProcedure::create($data);
            AuditEvent::record('surgical_procedure.created', $procedure->tenant_id, $procedure, ['size' => $procedure->size]);
        }, 'newProcedure', 'Já existe um procedimento com este nome.');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Procedimento cadastrado.']);

        return back();
    }

    public function updateProcedure(Request $request, SurgicalProcedure $procedure): RedirectResponse
    {
        Gate::authorize('manage', SurgicalProcedure::class);
        $bag = "procedure-{$procedure->id}";
        $procedure->fill(self::procedure($request, $bag, true));
        $changed = array_values(array_diff(array_keys($procedure->getDirty()), ['updated_at', 'name_key']));
        self::unique(function () use ($procedure, $changed) {
            $procedure->save();
            if ($changed !== []) {
                AuditEvent::record('surgical_procedure.updated', $procedure->tenant_id, $procedure, ['fields' => $changed, 'active' => $procedure->active]);
            }
        }, $bag, 'Já existe um procedimento com este nome.');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Procedimento atualizado.']);

        return back();
    }

    public function storeMaterial(Request $request): RedirectResponse
    {
        Gate::authorize('manage', SurgicalProcedure::class);
        $data = self::material($request, 'newMaterial');
        self::unique(function () use ($data) {
            $material = Material::create($data);
            AuditEvent::record('material.created', $material->tenant_id, $material, ['stock_unit' => $material->stock_unit]);
        }, 'newMaterial', 'Já existe um material com este nome.');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Material cadastrado.']);

        return back();
    }

    public function updateMaterial(Request $request, Material $material): RedirectResponse
    {
        Gate::authorize('manage', SurgicalProcedure::class);
        $bag = "material-{$material->id}";
        $material->fill(self::material($request, $bag, true));
        $changed = array_values(array_diff(array_keys($material->getDirty()), ['updated_at', 'name_key']));
        self::unique(function () use ($material, $changed) {
            $material->save();
            if ($changed !== []) {
                AuditEvent::record('material.updated', $material->tenant_id, $material, ['fields' => $changed, 'active' => $material->active]);
            }
        }, $bag, 'Já existe um material com este nome.');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Material atualizado.']);

        return back();
    }

    public function storeRoom(Request $request): RedirectResponse
    {
        Gate::authorize('manage', SurgicalProcedure::class);
        $data = $request->validateWithBag('newRoom', [
            // Units of the current network only (the tenant scope applies to the query).
            'unit_id' => ['required', 'integer', Rule::in(Unit::query()->pluck('id')->all())],
            'name' => ['required', 'string', 'max:100'],
        ], [], ['unit_id' => 'unidade', 'name' => 'nome']);
        self::unique(function () use ($data) {
            $room = OperatingRoom::create(['unit_id' => $data['unit_id'], 'name' => Medication::clean($data['name'])]);
            AuditEvent::record('operating_room.created', $room->tenant_id, $room, ['unit_id' => $room->unit_id]);
        }, 'newRoom', 'Esta unidade já tem uma sala com este nome.');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sala cadastrada.']);

        return back();
    }

    public function updateRoom(Request $request, OperatingRoom $room): RedirectResponse
    {
        Gate::authorize('manage', SurgicalProcedure::class);
        $bag = "room-{$room->id}";
        $data = $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:100'],
            'active' => ['required', 'boolean'],
        ], [], ['name' => 'nome']);
        $room->fill(['name' => Medication::clean($data['name']), 'active' => $data['active']]);
        $changed = array_values(array_diff(array_keys($room->getDirty()), ['updated_at', 'name_key']));
        self::unique(function () use ($room, $changed) {
            $room->save();
            if ($changed !== []) {
                AuditEvent::record('operating_room.updated', $room->tenant_id, $room, ['fields' => $changed, 'active' => $room->active]);
            }
        }, $bag, 'Esta unidade já tem uma sala com este nome.');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sala atualizada.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private static function procedure(Request $request, string $bag, bool $existing = false): array
    {
        $data = $request->validateWithBag($bag, [
            // Nothing a spreadsheet would run as a formula, as in the CSV import.
            'name' => ['required', 'string', 'max:255', 'not_regex:/^[=+\-@\t\r]/'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/', 'not_regex:/^-/'],
            'duration_minutes' => ['required', 'integer', 'between:5,1440'],
            'size' => ['required', Rule::in(array_keys(SurgicalProcedure::SIZES))],
            ...($existing ? ['active' => ['required', 'boolean']] : []),
        ], [], ['name' => 'nome', 'code' => 'código TUSS/SIGTAP', 'duration_minutes' => 'duração prevista', 'size' => 'porte']);

        return [...$data, 'name' => Medication::clean($data['name'])];
    }

    /**
     * @return array<string, mixed>
     */
    private static function material(Request $request, string $bag, bool $existing = false): array
    {
        $data = $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:255', 'not_regex:/^[=+\-@\t\r]/'],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[0-9A-Za-z.\/-]+$/', 'not_regex:/^-/'],
            'stock_unit' => ['required', Rule::in(Material::STOCK_UNITS)],
            ...($existing ? ['active' => ['required', 'boolean']] : []),
        ], [], ['name' => 'nome', 'code' => 'código', 'stock_unit' => 'unidade de estoque']);

        return [...$data, 'name' => Medication::clean($data['name'])];
    }

    /**
     * @param  Closure(): void  $save
     */
    private static function unique(Closure $save, string $bag, string $message): void
    {
        try {
            // A savepoint: the unique violation leaves the outer transaction usable.
            DB::transaction($save);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => $message])->errorBag($bag);
        }
    }
}
