<?php

namespace App\Http\Controllers;

use App\Models\AttendanceOccurrence;
use App\Models\Professional;
use App\Models\Shift;
use App\Policies\UnitPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Presence occurrences (step 8b): the professional reports, the coordinator of the shift's unit decides.
 */
class AttendanceOccurrenceController extends Controller
{
    public function store(Request $request, Shift $shift): RedirectResponse
    {
        $membership = UnitPolicy::currentMembership($request->user());
        $professional = $membership === null ? null : Professional::query()->where('membership_id', $membership->id)->first();
        abort_if($professional === null, 403);
        abort_unless($shift->professional_id === $professional->id, 404);
        $request->validate([
            'kind' => ['required', 'in:check_in,check_out'],
            'description' => ['required', 'string', 'max:2000'],
        ], attributes: ['kind' => 'tipo', 'description' => 'descrição']);

        $shift->reportOccurrence($professional, $request->string('kind')->value(), $request->string('description')->trim()->value());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ocorrência enviada. A coordenação vai analisar.']);

        return to_route('shifts.mine');
    }

    public function decide(Request $request, AttendanceOccurrence $occurrence): RedirectResponse
    {
        $shift = $occurrence->shift()->firstOrFail();
        Gate::authorize('update', $shift);
        $request->validate([
            'grant' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
            'informed_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ], attributes: ['reason' => 'justificativa', 'informed_at' => 'horário de saída']);

        // Typed in the unit's timezone, stored in UTC; a local time that does not exist is refused.
        $informedAt = null;
        if ($request->filled('informed_at')) {
            $timezone = $shift->unit()->value('timezone') ?? 'UTC';
            $value = $request->string('informed_at')->value();
            $local = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
            if ($local === null || $local->format('Y-m-d\TH:i') !== $value) {
                throw ValidationException::withMessages(['informed_at' => 'Horário inexistente no fuso da unidade.']);
            }
            $informedAt = $local->utc();
        }

        $grant = $request->boolean('grant');
        Shift::decideOccurrence($occurrence, $grant, $request->string('reason')->trim()->value(), $informedAt);
        Inertia::flash('toast', ['type' => 'success', 'message' => $grant ? 'Ocorrência deferida.' : 'Ocorrência indeferida.']);

        return to_route('shifts.show', $shift);
    }
}
