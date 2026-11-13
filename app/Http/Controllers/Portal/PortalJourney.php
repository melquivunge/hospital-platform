<?php

namespace App\Http\Controllers\Portal;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\ExamOrderItem;
use App\Models\ExamResult;
use App\Models\Sector;
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
 * @phpstan-type Journey array{kind: string, unit: string, steps: list<string>, current: int, endedAt: CarbonInterface|null, position: int|null, waitMinutes: int|null, level: string|null, detail: string|null}
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
            'detail' => null,
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
            'unit' => self::unitName($episode->unit_id),
            'steps' => ['Chegou', 'Classificação', 'Aguardando', 'Em atendimento', $last],
            'current' => $current,
            'endedAt' => $episode->ended_at,
            'position' => $position,
            'waitMinutes' => $wait,
            'level' => $level?->name,
            'detail' => null,
        ];
    }

    /**
     * Inpatient stay (contract: admitted, sector and bed → discharge; no position).
     *
     * @return Journey
     */
    public static function admission(Admission $admission): array
    {
        $bed = $admission->bed_id === null ? null : Bed::query()->with('sector:id,name')->find($admission->bed_id);
        $detail = $bed === null
            ? Sector::query()->whereKey($admission->requested_sector_id)->value('name')
            : "{$bed->sector?->name} · quarto {$bed->room}, leito {$bed->code}";

        return [
            'kind' => 'admission',
            'unit' => self::unitName($admission->unit_id),
            'steps' => ['Aguardando leito', 'Internado', match ($admission->discharge_type) {
                'transfer_out' => 'Transferido',
                'death', 'evasion' => 'Encerrado',
                default => 'Alta',
            }],
            'current' => match ($admission->status) {
                'requested' => 0,
                'discharged' => 2,
                default => 1,
            },
            'endedAt' => $admission->discharged_at,
            'position' => null,
            'waitMinutes' => null,
            'level' => null,
            'detail' => is_string($detail) ? $detail : null,
        ];
    }

    /**
     * One exam of an order (contract: ordered → done → in analysis → result released; no position). Ends when its
     * first result is released.
     *
     * @return Journey
     */
    public static function exam(ExamOrderItem $item, int $unitId, bool $named = true): array
    {
        // The first release (a rectification does not restart the clock); loaded with the list when it can be.
        $released = $item->getAttribute('results_min_released_at') ?? ExamResult::query()->where('exam_order_item_id', $item->id)->min('released_at');

        return [
            'kind' => 'exam',
            'unit' => self::unitName($unitId),
            'steps' => ['Pedido', $item->exam_type === 'imaging' ? 'Realizado' : 'Coletado', 'Em análise', 'Resultado liberado'],
            'current' => match (true) {
                $released !== null => 3,
                $item->collected_at !== null => 2,
                default => 0,
            },
            'endedAt' => $released === null ? null : Carbon::parse($released),
            'position' => null,
            'waitMinutes' => null,
            'level' => null,
            // A guardian sees which kind of exam, not which exam, until the result is released (it is in the record then).
            'detail' => $named || $released !== null ? $item->exam_name : match ($item->exam_type) {
                'imaging' => 'Exame de imagem',
                default => 'Exame de laboratório',
            },
        ];
    }

    private static function unitName(int $id): string
    {
        // Once per request: every card of the page and every 20 s refresh names the same few units.
        return once(fn () => (string) Unit::query()->whereKey($id)->value('name'));
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
