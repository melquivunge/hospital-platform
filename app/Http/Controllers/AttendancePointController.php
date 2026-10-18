<?php

namespace App\Http\Controllers;

use App\Models\AttendancePoint;
use App\Models\AuditEvent;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Authorized presence points of a unit (step 8a). A point is never edited: to change it, add a new one
 * and deactivate the old, so past decisions keep pointing at the configuration they used.
 */
class AttendancePointController extends Controller
{
    public function store(Request $request, Unit $unit): RedirectResponse
    {
        Gate::authorize('managePresence', $unit);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:1', 'max:100000'],
        ], attributes: ['name' => 'nome', 'latitude' => 'latitude', 'longitude' => 'longitude', 'radius_m' => 'raio']);

        $point = AttendancePoint::create([...$data, 'name' => trim($data['name']), 'unit_id' => $unit->id, 'created_by_user_id' => Auth::id()]);
        AuditEvent::record('attendance_point.created', $unit->tenant_id, $point, ['unit_id' => $unit->id, 'radius_m' => $point->radius_m]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ponto de presença cadastrado.']);

        return to_route('units.show', $unit);
    }

    public function deactivate(Unit $unit, AttendancePoint $attendancePoint): RedirectResponse
    {
        Gate::authorize('managePresence', $unit);
        // Conditional update: a second, concurrent deactivation changes nothing and gets "not found".
        $changed = AttendancePoint::query()->whereKey($attendancePoint->id)->whereNull('deactivated_at')
            ->update(['deactivated_at' => now(), 'deactivated_by_user_id' => Auth::id()]);
        abort_if($changed === 0, 404);
        AuditEvent::record('attendance_point.deactivated', $unit->tenant_id, $attendancePoint, ['unit_id' => $unit->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ponto de presença desativado.']);

        return to_route('units.show', $unit);
    }
}
