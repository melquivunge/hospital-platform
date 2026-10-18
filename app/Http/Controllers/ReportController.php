<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Shift;
use App\Models\Unit;
use App\Policies\ShiftPolicy;
use App\Policies\UnitPolicy;
use App\Scheduling\RequestStatus;
use App\Scheduling\ShiftStatus;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Operational reports (step 9b, V1-11): same readers and units as the schedule (user decision,
 * 18/10/2026). Every figure counts the unit's shifts that START in the period, in the unit's time
 * zone, so each group adds up to its total and the exports list exactly the counted rows. No
 * coordinates ever leave in a report.
 */
class ReportController extends Controller
{
    private const MAX_DAYS = 92;

    public function index(Request $request): Response
    {
        $scope = $this->scope($request);
        if ($scope === null) {
            return Inertia::render('reports/index', ['units' => [], 'unit' => null, 'from' => null, 'to' => null, 'coverage' => null, 'requests' => null, 'attendance' => null, 'hours' => []]);
        }
        [$units, $unit, $from, $to] = $scope;
        $published = fn () => self::shifts($unit, $from, $to)->where('status', ShiftStatus::Published);
        $startedIds = fn () => self::started($unit, $from, $to)->select('id');

        $coverage = self::shifts($unit, $from, $to)->toBase()->selectRaw(<<<'SQL'
            count(*) filter (where status = 'draft') as drafts,
            count(*) filter (where status = 'cancelled') as cancelled,
            count(*) filter (where status = 'published') as published,
            count(*) filter (where status = 'published' and professional_id is not null and announced_at is null) as filled,
            count(*) filter (where status = 'published' and announced_at is not null) as announced,
            count(*) filter (where status = 'published' and professional_id is null) as open
            SQL)->first();

        $requests = DB::table('shift_requests')->where('tenant_id', $unit->tenant_id)
            ->whereIn('shift_id', self::shifts($unit, $from, $to)->select('id'))
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $request = fn (RequestStatus $status) => (int) ($requests[$status->value] ?? 0);

        $accepted = fn (string $kind) => DB::table('attendance_events')->whereIn('shift_id', $startedIds())
            ->where('kind', $kind)->where('result', 'accepted')->count();
        $occurrences = fn () => DB::table('attendance_occurrences')->whereIn('shift_id', $startedIds());
        $started = self::started($unit, $from, $to)->count();
        $checkedIn = $accepted('check_in');

        $hours = $published()->whereNotNull('professional_id')->toBase()
            ->join('professionals', 'professionals.id', '=', 'shifts.professional_id')
            ->selectRaw('professionals.id, professionals.name, count(*) as shifts, sum(extract(epoch from (shifts.ends_at - shifts.starts_at))) / 3600 as hours')
            ->groupBy('professionals.id', 'professionals.name')
            ->orderByDesc('hours')->orderBy('professionals.name')
            ->get()->map(fn (object $row) => ['id' => (int) $row->id, 'name' => $row->name, 'shifts' => (int) $row->shifts, 'hours' => round((float) $row->hours, 1)]);

        return Inertia::render('reports/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unit' => $unit->only('id', 'name'),
            'from' => $from->toDateString(),
            'to' => $to->subDay()->toDateString(),
            'coverage' => array_map('intval', (array) $coverage),
            'requests' => [
                'pending' => $request(RequestStatus::PendingRecipient) + $request(RequestStatus::PendingCoordinator),
                'completed' => $request(RequestStatus::Completed),
                'refused' => $request(RequestStatus::Refused),
                'cancelled' => $request(RequestStatus::Cancelled),
                'expired' => $request(RequestStatus::Expired),
            ],
            'attendance' => [
                'started' => $started,
                'checkedIn' => $checkedIn,
                'noEntry' => $started - $checkedIn,
                'checkedOut' => $accepted('check_out'),
                'adjustedExit' => $occurrences()->where('kind', 'check_out')->where('status', 'granted')->count(),
                'openOccurrences' => $occurrences()->where('status', 'open')->count(),
            ],
            'hours' => $hours,
        ]);
    }

    public function schedule(Request $request, string $format): SymfonyResponse
    {
        [, $unit, $from, $to] = $this->scope($request) ?? abort(404);
        $timezone = $unit->timezone ?? 'UTC';
        $shifts = self::shifts($unit, $from, $to)->with(['sector:id,name', 'jobFunction:id,name', 'professional:id,name'])
            ->orderBy('starts_at')->orderBy('id')->get();

        return $this->export($format, $unit, 'escala', 'Escala', $from, $to, ['Data', 'Início', 'Fim', 'Setor', 'Função', 'Profissional', 'Situação', 'Tipo'],
            $shifts->map(fn (Shift $shift) => [
                ...self::when($shift, $timezone), $shift->sector->name, $shift->jobFunction->name, $shift->professional->name ?? '',
                match ($shift->status) {
                    ShiftStatus::Draft => 'Rascunho',
                    ShiftStatus::Published => 'Publicado',
                    ShiftStatus::Cancelled => 'Cancelado',
                },
                $shift->status !== ShiftStatus::Published ? '' : match (true) {
                    $shift->professional_id === null => 'Vaga aberta',
                    $shift->announced_at !== null => 'Anunciado para substituição',
                    default => 'Preenchido',
                },
            ])->values()->all());
    }

    public function attendance(Request $request, string $format): SymfonyResponse
    {
        [, $unit, $from, $to] = $this->scope($request) ?? abort(404);
        $timezone = $unit->timezone ?? 'UTC';
        $shifts = self::started($unit, $from, $to)->with(['sector:id,name', 'jobFunction:id,name', 'professional:id,name'])
            ->orderBy('starts_at')->orderBy('id')->get();
        $ids = $shifts->pluck('id');
        // Times only: coordinates, accuracy and distance stay out of general reports (V1-11).
        $events = DB::table('attendance_events')->whereIn('shift_id', $ids)->where('result', 'accepted')
            ->get(['shift_id', 'kind', 'received_at'])->groupBy('shift_id');
        $occurrences = DB::table('attendance_occurrences')->whereIn('shift_id', $ids)
            ->get(['shift_id', 'kind', 'status', 'informed_at'])->groupBy('shift_id');
        $time = fn (?string $at) => $at === null ? '' : CarbonImmutable::parse($at)->setTimezone($timezone)->format('d/m/Y H:i');

        return $this->export($format, $unit, 'presenca', 'Presença', $from, $to, ['Data', 'Início', 'Fim', 'Setor', 'Função', 'Profissional', 'Entrada', 'Saída', 'Saída ajustada', 'Ocorrências abertas'],
            $shifts->map(function (Shift $shift) use ($events, $occurrences, $time, $timezone) {
                $own = collect($events->get($shift->id, []));
                $reported = collect($occurrences->get($shift->id, []));
                $adjusted = $reported->first(fn (\stdClass $occurrence) => $occurrence->kind === 'check_out' && $occurrence->status === 'granted');

                return [
                    ...self::when($shift, $timezone), $shift->sector->name, $shift->jobFunction->name, $shift->professional->name ?? '',
                    $time($own->firstWhere('kind', 'check_in')?->received_at),
                    $time($own->firstWhere('kind', 'check_out')->received_at ?? $adjusted?->informed_at),
                    $adjusted === null ? 'Não' : 'Sim',
                    (string) $reported->where('status', 'open')->count(),
                ];
            })->values()->all());
    }

    /**
     * @return Builder<Shift>
     */
    private static function shifts(Unit $unit, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Shift::query()->where('unit_id', $unit->id)->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $to->utc());
    }

    /**
     * Published shifts with someone responsible that have already begun: the base of the presence figures.
     *
     * @return Builder<Shift>
     */
    private static function started(Unit $unit, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return self::shifts($unit, $from, $to)->where('status', ShiftStatus::Published)
            ->whereNotNull('professional_id')->where('starts_at', '<=', now());
    }

    /**
     * @return array{Collection<int, Unit>, Unit, CarbonImmutable, CarbonImmutable}|null
     */
    private function scope(Request $request): ?array
    {
        Gate::authorize('viewAny', Shift::class);
        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);
        $readable = ShiftPolicy::readableUnitIds($membership);

        $units = Unit::query()
            ->when($readable !== null, fn (Builder $query) => $query->whereIn('id', $readable ?? []))
            ->orderBy('name')->get(['id', 'tenant_id', 'name', 'timezone']);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        if ($unit === null) {
            return null;
        }

        // Invalid or too long periods fall back to the current month: the page always shows real figures.
        $timezone = $unit->timezone ?? 'UTC';
        $date = function (string $key) use ($request, $timezone): ?CarbonImmutable {
            $value = $request->string($key)->value();

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? (CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) ?: null) : null;
        };
        $from = $date('from');
        $to = $date('to')?->addDay();
        if ($from === null || $to === null || $to <= $from || $from->diffInDays($to) > self::MAX_DAYS) {
            $from = CarbonImmutable::now($timezone)->startOfMonth();
            $to = $from->addMonth();
        }

        return [$units, $unit, $from, $to];
    }

    /**
     * @return array{string, string, string}
     */
    private static function when(Shift $shift, string $timezone): array
    {
        $start = $shift->starts_at->setTimezone($timezone);
        $end = $shift->ends_at->setTimezone($timezone);

        return [$start->format('d/m/Y'), $start->format('H:i'), $end->format($end->isSameDay($start) ? 'H:i' : 'd/m/Y H:i')];
    }

    /**
     * Generated for the requester only, never stored, and audited. CSV: `;` and a BOM for spreadsheets
     * in pt-BR, with cells a spreadsheet would run as a formula neutralized (PRODUCT.md). PDF: the same
     * rows, escaped by Blade, rendered without remote resources or embedded PHP.
     *
     * @param  list<string>  $header
     * @param  array<array<string>>  $rows
     */
    private function export(string $format, Unit $unit, string $report, string $title, CarbonImmutable $from, CarbonImmutable $to, array $header, array $rows): SymfonyResponse
    {
        $last = $to->subDay();
        AuditEvent::record('report.exported', $unit->tenant_id, $unit, ['report' => $report, 'format' => $format, 'from' => $from->toDateString(), 'to' => $last->toDateString(), 'rows' => count($rows)]);
        $file = "{$report}-{$from->toDateString()}-a-{$last->toDateString()}.{$format}";

        if ($format === 'pdf') {
            // ponytail: dompdf renders in memory; a 92-day period of a large unit (thousands of rows) is
            // the ceiling — move to a queued export with a private download if that ever gets slow.
            $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
            $pdf->loadHtml(view('reports.table', [
                'network' => $unit->tenant->name, 'unit' => $unit->name, 'title' => $title, 'header' => $header, 'rows' => $rows,
                'period' => $from->format('d/m/Y').' a '.$last->format('d/m/Y'),
                'generated' => CarbonImmutable::now($unit->timezone ?? 'UTC')->format('d/m/Y H:i'),
            ])->render());
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();
            $pdf->getCanvas()->page_text(770, 570, 'Página {PAGE_NUM} de {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.4, 0.4, 0.4]);

            return response((string) $pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$file}\"",
                'Cache-Control' => 'no-store',
            ]);
        }

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}");
            foreach ([$header, ...$rows] as $row) {
                fputcsv($out, array_map(fn (string $cell) => preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'".$cell : $cell, $row), ';', '"', '');
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
