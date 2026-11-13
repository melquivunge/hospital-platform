<?php

namespace App\Http\Controllers\Portal;

use App\Models\Appointment;
use App\Models\EmergencyEpisode;
use App\Models\TriageLevel;
use App\Models\Unit;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Steps 7b.1 and 7b.2 (contract, "Regras de posição e tempo"): where one arrival is now, in the patient's words, with
 * the position in the queue and the expected wait. The same answer for the QR and for the signed-in portal.
 *
 * @phpstan-type Journey array{kind: string, unit: string, steps: list<string>, current: int, endedAt: CarbonInterface|null, position: int|null, waitMinutes: int|null, level: string|null}
 */
final class PortalJourney
{
    /**
     * @return Journey
     */
    public static function appointment(Appointment $appointment): array
    {
        $current = match ($appointment->status) {
            'in_progress' => 2,
            'completed', 'cancelled', 'no_show' => 3,
            default => 1,
        };

        // A completed appointment ends when its visit closes (the appointment's own closed_at is for cancel and no-show).
        $ended = $appointment->status === 'completed'
            ? Visit::query()->where('appointment_id', $appointment->id)->max('closed_at')
            : $appointment->closed_at;

        $unit = Unit::query()->findOrFail($appointment->unit_id);
        $position = $wait = null;
        $day = CarbonImmutable::now($unit->timezone ?? 'UTC')->startOfDay();
        // Only today's queue: an arrival left open from another day has no place in it.
        if ($appointment->status === 'arrived' && $appointment->starts_at->between($day, $day->addDay())) {
            // The day's queue of this professional, in the order the reception sees it (time booked, then id).
            $ahead = Appointment::query()->where('unit_id', $appointment->unit_id)->where('professional_id', $appointment->professional_id)
                ->where('status', 'arrived')->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc())
                ->where(fn (Builder $query) => $query->where('starts_at', '<', $appointment->starts_at)
                    ->orWhere(fn (Builder $same) => $same->where('starts_at', $appointment->starts_at)->where('id', '<', $appointment->id)))
                ->count();
            // The professional's average visit today; without one yet, the length of the booked slot.
            $average = Visit::query()->where('unit_id', $appointment->unit_id)->where('professional_id', $appointment->professional_id)->whereNotNull('appointment_id')
                ->where('closed_at', '>=', $day->utc())->where('closed_at', '<', $day->addDay()->utc())->selectRaw('avg(extract(epoch from closed_at - opened_at)) / 60 as minutes')->value('minutes');
            $slot = (float) ($average ?? $appointment->starts_at->diffInMinutes($appointment->ends_at));
            $position = $ahead + 1;
            $wait = (int) ceil($ahead * $slot);
        }

        return [
            'kind' => 'appointment',
            'unit' => $unit->name,
            'steps' => ['Chegou', 'Aguardando', 'Em atendimento', 'Encerrado'],
            'current' => $current,
            'endedAt' => $ended === null ? null : Carbon::parse($ended),
            'position' => $position,
            'waitMinutes' => $wait,
            'level' => null,
        ];
    }

    /**
     * @return Journey
     */
    public static function episode(EmergencyEpisode $episode): array
    {
        $current = match (true) {
            $episode->ended_at !== null || $episode->status === 'admission_requested' => 4,
            $episode->status === 'in_care' => 3,
            $episode->triaged_at !== null => 2,
            default => 0,
        };

        // The last step says how it ended: discharge, an admission request, or an end without care.
        $last = match ($episode->status) {
            'admission_requested' => 'Internação solicitada',
            'evaded' => 'Encerrado',
            default => 'Alta',
        };

        $level = $episode->current_level === null ? null : TriageLevel::scale()->get($episode->current_level);
        $position = $wait = null;
        if ($episode->status === 'waiting' && $episode->current_level !== null) {
            // Only those as serious or more come first; the target time of the color is the ceiling, not a promise.
            $ahead = EmergencyEpisode::query()->where('unit_id', $episode->unit_id)->where('status', 'waiting')->whereNotNull('current_level')
                ->where(fn (Builder $query) => $query->where('current_level', '<', $episode->current_level)
                    ->orWhere(fn (Builder $same) => $same->where('current_level', $episode->current_level)
                        ->where(fn (Builder $earlier) => $earlier->where('arrived_at', '<', $episode->arrived_at)
                            ->orWhere(fn (Builder $tie) => $tie->where('arrived_at', $episode->arrived_at)->where('id', '<', $episode->id)))))
                ->count();
            $position = $ahead + 1;
            $wait = $level?->target_minutes;
        }

        return [
            'kind' => 'emergency',
            'unit' => (string) Unit::query()->whereKey($episode->unit_id)->value('name'),
            'steps' => ['Chegou', 'Classificação', 'Aguardando', 'Em atendimento', $last],
            'current' => $current,
            'endedAt' => $episode->ended_at,
            'position' => $position,
            'waitMinutes' => $wait,
            'level' => $level?->name,
        ];
    }

    /**
     * What the page gets: whether it ended, not when.
     *
     * @param  Journey  $journey
     * @return array<string, mixed>
     */
    public static function present(array $journey): array
    {
        return ['ended' => $journey['endedAt'] !== null] + array_diff_key($journey, ['endedAt' => true]);
    }
}
