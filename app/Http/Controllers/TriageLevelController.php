<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\TriageLevel;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The network's 5-level risk scale (step 4.2): the network admin names each level and sets its target
 * time to care. Colors and order are fixed so every unit reads the queue the same way.
 */
class TriageLevelController extends Controller
{
    public function index(Request $request): Response
    {
        self::authorize($request);

        return Inertia::render('triage-levels/index', [
            'levels' => TriageLevel::scale()->values()->map->only('id', 'level', 'name', 'color', 'target_minutes'),
        ]);
    }

    public function update(Request $request, TriageLevel $triageLevel): RedirectResponse
    {
        self::authorize($request);
        $data = $request->validateWithBag("level-{$triageLevel->level}", [
            'name' => ['required', 'string', 'max:50'],
            'target_minutes' => ['required', 'integer', 'between:0,1440'],
        ], [], ['name' => 'nome', 'target_minutes' => 'tempo-alvo']);

        $triageLevel->fill($data);
        $changed = array_keys($triageLevel->getDirty());
        if ($changed !== []) {
            $triageLevel->save();
            AuditEvent::record('triage_level.updated', $triageLevel->tenant_id, $triageLevel, ['level' => $triageLevel->level, 'fields' => $changed]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nível atualizado.']);

        return to_route('triage-levels.index');
    }

    private static function authorize(Request $request): void
    {
        abort_unless(UnitPolicy::currentMembership($request->user())?->hasRole(Role::NetworkAdmin) ?? false, 403);
    }
}
