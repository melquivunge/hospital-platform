<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenancy\SectorRequest;
use App\Models\AuditEvent;
use App\Models\Sector;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Sectors inside a unit. Authorization lives in SectorRequest; route bindings are scoped to the unit.
 */
class SectorController extends Controller
{
    public function store(SectorRequest $request, Unit $unit): RedirectResponse
    {
        UnitController::saving(fn () => DB::transaction(function () use ($request, $unit) {
            $sector = $unit->sectors()->create($request->validated());
            AuditEvent::record('sector.created', $sector->tenant_id, $sector, ['unit_id' => $unit->id]);
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Setor cadastrado.']);

        return to_route('units.show', $unit);
    }

    public function update(SectorRequest $request, Unit $unit, Sector $sector): RedirectResponse
    {
        UnitController::saving(fn () => DB::transaction(function () use ($request, $unit, $sector) {
            $sector->fill($request->validated());

            if ($sector->isDirty()) {
                $sector->save();
                AuditEvent::record('sector.updated', $sector->tenant_id, $sector, ['unit_id' => $unit->id]);
            }
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Setor atualizado.']);

        return to_route('units.show', $unit);
    }
}
