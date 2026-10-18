<?php

namespace App\Http\Controllers;

use App\Models\Professional;
use App\Models\Shift;
use App\Policies\UnitPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Entry and exit by location (step 8a). The browser sends one reading taken at the moment of the action;
 * the server decides and keeps the attempt. No continuous tracking.
 */
class AttendanceController extends Controller
{
    public function store(Request $request, Shift $shift): RedirectResponse
    {
        $membership = UnitPolicy::currentMembership($request->user());
        $professional = $membership === null ? null : Professional::query()->where('membership_id', $membership->id)->first();
        abort_if($professional === null, 403);
        abort_unless($shift->professional_id === $professional->id, 404);

        $request->validate([
            'kind' => ['required', 'in:check_in,check_out'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'gt:0', 'max:100000'],
            // Bounded so an absurd device clock is refused here instead of breaking the insert.
            'measured_at' => ['required', 'integer', 'min:'.now()->subDay()->getTimestampMs(), 'max:'.now()->addDay()->getTimestampMs()],
            'idempotency_key' => ['required', 'uuid'],
        ], [
            'latitude.required' => 'Não foi possível obter a localização do aparelho.',
            'longitude.required' => 'Não foi possível obter a localização do aparelho.',
            'accuracy.required' => 'Não foi possível obter a localização do aparelho.',
        ]);

        $kind = $request->string('kind')->value();
        $event = $shift->recordAttendance($professional, $kind, [
            'latitude' => (float) $request->input('latitude'),
            'longitude' => (float) $request->input('longitude'),
            'accuracy' => (float) $request->input('accuracy'),
            'measured_at' => CarbonImmutable::createFromTimestampMs($request->integer('measured_at')),
        ], $request->string('idempotency_key')->value());

        if (! $event->accepted()) {
            throw ValidationException::withMessages(['attendance' => (string) $event->reasonMessage()]);
        }

        $timezone = $shift->unit()->value('timezone') ?? 'UTC';
        $label = $kind === 'check_in' ? 'Entrada' : 'Saída';
        $point = $event->point()->value('name');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$label} registrada às {$event->received_at->setTimezone($timezone)->format('H:i')} (horário do hospital), a ".number_format((float) $event->distance_m, 0, ',', '.')." m do ponto {$point}."]);

        return to_route('shifts.mine');
    }
}
