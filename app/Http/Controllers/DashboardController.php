<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Professional;
use App\Models\Shift;
use App\Models\Unit;
use App\Policies\ShiftPolicy;
use App\Policies\UnitPolicy;
use App\Scheduling\ShiftStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Overview of what the member can see in the current tenant, and what waits for them:
     * their next shift, and the requests and presence occurrences of the units they coordinate.
     */
    public function __invoke(Request $request): Response
    {
        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        $units = Unit::query()->visibleTo($membership)->withCount('sectors')->get();
        $managed = ShiftPolicy::managedUnitIds($membership);

        return Inertia::render('dashboard', [
            'stats' => [
                'units' => $units->count(),
                'sectors' => (int) $units->sum('sectors_count'),
                'incomplete' => $units->reject->isComplete()->count(),
            ],
            'canCreateUnit' => Gate::allows('create', Unit::class),
            'nextShift' => self::nextShift($membership),
            // Null when this member coordinates no unit: the page then shows no scheduling pendencies.
            'pending' => $managed === [] ? null : [
                // Requests of a shift that already started expire when Solicitações opens; not counted here.
                'requests' => ShiftRequestController::awaitingDecision($managed)
                    ->whereHas('shift', fn (Builder $shift) => $shift->where('starts_at', '>', now()))
                    ->count(),
                'occurrences' => ShiftRequestController::openOccurrences($managed)->count(),
            ],
        ]);
    }

    /**
     * The member's own published shift that is running or comes next, in its unit's time zone.
     *
     * @return array{id: int, date: string, start: string, end: string, unit: string, sector: string|null, function: string|null}|null
     */
    private static function nextShift(Membership $membership): ?array
    {
        $professional = Professional::query()->where('membership_id', $membership->id)->first();

        $shift = $professional === null ? null : Shift::query()
            ->where('professional_id', $professional->id)
            ->where('status', ShiftStatus::Published)
            ->where('ends_at', '>', now())
            ->with(['unit:id,name,timezone', 'sector:id,name', 'jobFunction:id,name'])
            ->orderBy('starts_at')
            ->first();

        if ($shift === null) {
            return null;
        }

        $timezone = $shift->unit->timezone ?? 'UTC';

        return [
            'id' => $shift->id,
            'date' => $shift->starts_at->setTimezone($timezone)->toDateString(),
            'start' => $shift->starts_at->setTimezone($timezone)->format('H:i'),
            'end' => $shift->ends_at->setTimezone($timezone)->format('H:i'),
            'unit' => $shift->unit->name,
            'sector' => $shift->sector?->name,
            'function' => $shift->jobFunction?->name,
        ];
    }
}
