<?php

namespace App\Http\Controllers;

use App\Models\OperatingRoom;
use App\Models\Surgery;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 5b.3c (ADR-0009): the unit manager (and the network admin) sees the surgeries still scheduled more than 24
 * hours after the start and the requests never scheduled, to chase the surgical center or the doctor who ordered.
 * Not clinical: when, where, room and who ordered; never the patient, the procedure or the record.
 */
class SurgeryOverdueController extends Controller
{
    // ponytail: oldest first and capped; paginate if a unit ever piles up more.
    private const LIMIT = 200;

    public function index(Request $request): Response|RedirectResponse
    {
        $units = Unit::query()->whereKey(ExamOverdueController::unitIds($request->user()))->orderBy('name')->get(['id', 'name', 'timezone']);
        if ($units->isEmpty()) {
            return self::withoutUnits($request->user());
        }
        $unit = $units->firstWhere('id', (int) $request->query('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';

        $scheduled = Surgery::overdue()->where('unit_id', $unit->id)->orderBy('scheduled_start')->limit(self::LIMIT)->get();
        $requested = Surgery::stalled()->where('unit_id', $unit->id)->orderBy('desired_date')->limit(self::LIMIT)->get();
        $places = ExamQueueController::places($scheduled->concat($requested));
        $authors = User::query()->whereKey($scheduled->concat($requested)->pluck('author_user_id')->unique()->all())->pluck('name', 'id');
        $rooms = OperatingRoom::query()->whereKey($scheduled->pluck('operating_room_id')->unique()->all())->pluck('name', 'id');

        return Inertia::render('surgery-overdue/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'hours' => Surgery::OVERDUE_HOURS,
            'days' => Surgery::REQUESTED_ACCESS_DAYS,
            'urgencies' => Surgery::URGENCIES,
            'scheduled' => $scheduled->map(fn (Surgery $surgery) => [
                'id' => $surgery->id,
                'start' => $surgery->scheduled_start?->setTimezone($timezone)->format('d/m/Y H:i'),
                'room' => $rooms->get((int) $surgery->operating_room_id),
                'place' => $places[$surgery->id],
                'author' => $authors->get($surgery->author_user_id),
                'urgency' => $surgery->urgency,
            ])->values(),
            'requested' => $requested->map(fn (Surgery $surgery) => [
                'id' => $surgery->id,
                'desiredDate' => $surgery->desired_date->format('d/m/Y'),
                'place' => $places[$surgery->id],
                'author' => $authors->get($surgery->author_user_id),
                'urgency' => $surgery->urgency,
            ])->values(),
        ]);
    }
}
