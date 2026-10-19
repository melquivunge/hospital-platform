<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Sector;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily bed census (step 4.7) for unit managers, network admins and bed management: counts by bed state
 * per sector (the same query as the bed map), occupancy and the mean length of stay of the last 30 days.
 * Numbers only, no patient and no clinical content.
 */
class CensusController extends Controller
{
    public const STAY_WINDOW_DAYS = 30;

    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(BedController::censusUnitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $beds = Bed::query()->where('unit_id', $unit->id)->whereNull('deactivated_at')->get(['id', 'sector_id', 'status']);
        // Stays only: a discharge before a bed or in transit (step 4.9) occupied no bed of the unit.
        // ponytail: averaged in PHP over one unit and 30 days of discharges; move to SQL if this grows large.
        $stays = Admission::query()->where('unit_id', $unit->id)->where('status', 'discharged')->whereNotNull('bed_id')
            ->where('discharged_at', '>=', now()->subDays(self::STAY_WINDOW_DAYS))->with('bed:id,sector_id')
            ->get(['id', 'bed_id', 'admitted_at', 'discharged_at']);
        // Sectors with beds or with stays (a bed deactivated after the discharge): the rows add up to the total.
        $sectors = Sector::query()->where('unit_id', $unit->id)->whereIn('id', $beds->pluck('sector_id')->merge($stays->pluck('bed.sector_id')))->orderBy('name')->get(['id', 'name']);

        return Inertia::render('census/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unit' => $unit->only('id', 'name'),
            'at' => now($unit->timezone ?? 'UTC')->format('d/m/Y H:i'),
            'windowDays' => self::STAY_WINDOW_DAYS,
            'sectors' => $sectors->map(fn (Sector $sector) => ['name' => $sector->name, ...self::row($beds->where('sector_id', $sector->id), $stays->filter(fn (Admission $stay) => $stay->bed?->sector_id === $sector->id))])->values(),
            'total' => self::row($beds, $stays),
        ]);
    }

    /**
     * Occupancy: occupied over operational beds (active, not blocked). Length of stay in days, one decimal.
     *
     * @param  Collection<int, Bed>  $beds
     * @param  Collection<int, Admission>  $stays
     * @return array<string, int|float|null>
     */
    private static function row(Collection $beds, Collection $stays): array
    {
        $counts = $beds->countBy('status');
        $operational = $beds->count() - $counts->get('blocked', 0);

        return [
            'beds' => $beds->count(),
            'free' => $counts->get('free', 0),
            'reserved' => $counts->get('reserved', 0),
            'occupied' => $counts->get('occupied', 0),
            'cleaning' => $counts->get('cleaning', 0),
            'blocked' => $counts->get('blocked', 0),
            'occupancy' => $operational === 0 ? null : round(100 * $counts->get('occupied', 0) / $operational, 1),
            'discharges' => $stays->count(),
            'meanStayDays' => $stays->isEmpty() ? null : round($stays->avg(fn (Admission $stay) => $stay->admitted_at->diffInMinutes($stay->discharged_at)) / 1440, 1),
        ];
    }
}
