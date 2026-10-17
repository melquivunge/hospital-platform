<?php

namespace App\Http\Controllers;

use App\Models\JobFunction;
use App\Models\Professional;
use App\Models\Shift;
use App\Models\Unit;
use App\Policies\ShiftPolicy;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use App\Scheduling\ShiftStatus;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Schedule of a unit (step 4): day/week/month views, drafts, publication and cancellation. Times are typed and
 * shown in the unit's timezone and stored in UTC.
 */
class ShiftController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Shift::class);

        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);
        $readable = ShiftPolicy::readableUnitIds($membership);

        $units = Unit::query()
            ->when($readable !== null, fn (Builder $query) => $query->whereIn('id', $readable ?? []))
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'uf', 'timezone']);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();

        if ($unit === null) {
            return Inertia::render('shifts/index', ['units' => [], 'unit' => null, 'view' => 'week', 'from' => null, 'to' => null, 'shifts' => [], 'canManage' => false]);
        }

        $timezone = $unit->timezone ?? 'UTC';
        $view = in_array($request->string('view')->value(), ['day', 'week', 'month'], true) ? $request->string('view')->value() : 'week';
        [$from, $to] = self::range($view, $request->string('date')->value(), $timezone);

        // Overlap, not start: a shift crossing midnight stays visible on the day it ends (V1-05).
        $shifts = Shift::query()
            ->where('unit_id', $unit->id)
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->with(['sector:id,name', 'jobFunction:id,name', 'professional:id,name'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Shift $shift) => self::summary($shift, $timezone));

        return Inertia::render('shifts/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unit' => [...$unit->only('id', 'name', 'timezone'), 'complete' => $unit->isComplete()],
            'view' => $view,
            'from' => $from->toDateString(),
            'to' => $to->subDay()->toDateString(),
            'shifts' => $shifts,
            'canManage' => Gate::allows('manage', [Shift::class, $unit]),
        ]);
    }

    public function create(Unit $unit): Response
    {
        Gate::authorize('manage', [Shift::class, $unit]);

        return Inertia::render('shifts/form', ['shift' => null, ...self::formOptions($unit)]);
    }

    public function store(Request $request, Unit $unit): RedirectResponse
    {
        Gate::authorize('manage', [Shift::class, $unit]);

        $shift = Shift::createDraft($unit, self::validated($request, $unit));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rascunho criado. Publique para confirmar o plantão.']);

        return to_route('shifts.show', $shift);
    }

    public function show(Shift $shift): Response
    {
        Gate::authorize('view', $shift);

        $shift->load(['unit', 'sector:id,name', 'jobFunction:id,name', 'professional:id,name']);
        $unit = $shift->unit;
        $canManage = Gate::allows('update', $shift);

        return Inertia::render('shifts/show', [
            'shift' => [
                ...self::summary($shift, $unit->timezone ?? 'UTC'),
                'unit' => $unit->only('id', 'name', 'timezone'),
                'cancelReason' => $shift->cancel_reason,
            ],
            'canEdit' => $canManage && $shift->status === ShiftStatus::Draft,
            'canPublish' => $canManage && $shift->status === ShiftStatus::Draft,
            'canCancel' => $canManage && ($shift->status === ShiftStatus::Draft || ($shift->status === ShiftStatus::Published && $shift->starts_at->isFuture())),
        ]);
    }

    public function edit(Shift $shift): Response
    {
        Gate::authorize('update', $shift);
        abort_unless($shift->status === ShiftStatus::Draft, 403);

        $unit = $shift->unit()->firstOrFail();
        $timezone = $unit->timezone ?? 'UTC';

        return Inertia::render('shifts/form', [
            'shift' => [
                'id' => $shift->id,
                'sector_id' => $shift->sector_id,
                'job_function_id' => $shift->job_function_id,
                'professional_id' => $shift->professional_id,
                'starts_at' => $shift->starts_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'ends_at' => $shift->ends_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
            ],
            ...self::formOptions($unit),
        ]);
    }

    public function update(Request $request, Shift $shift): RedirectResponse
    {
        Gate::authorize('update', $shift);

        $shift->updateDraft(self::validated($request, $shift->unit()->firstOrFail()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rascunho atualizado.']);

        return to_route('shifts.show', $shift);
    }

    public function publish(Shift $shift): RedirectResponse
    {
        Gate::authorize('update', $shift);

        $shift->publish();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plantão publicado.']);

        return to_route('shifts.show', $shift);
    }

    public function cancel(Request $request, Shift $shift): RedirectResponse
    {
        Gate::authorize('update', $shift);

        $reason = $request->string('reason')->trim()->value();
        $request->validate(['reason' => ['required', 'string', 'max:1000']], attributes: ['reason' => 'motivo']);
        $shift->cancel($reason);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Plantão cancelado.']);

        return to_route('shifts.show', $shift);
    }

    /**
     * Local [from, to) of the day, week (Monday) or month containing $date (default: today) in the unit's timezone.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private static function range(string $view, string $date, string $timezone): array
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)
            : null;
        $day = ($day ?: CarbonImmutable::now($timezone))->startOfDay();

        return match ($view) {
            'day' => [$day, $day->addDay()],
            'month' => [$day->startOfMonth(), $day->startOfMonth()->addMonth()],
            default => [$day->startOfWeek(CarbonImmutable::MONDAY), $day->startOfWeek(CarbonImmutable::MONDAY)->addWeek()],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(Shift $shift, string $timezone): array
    {
        $start = $shift->starts_at->setTimezone($timezone);
        $end = $shift->ends_at->setTimezone($timezone);

        return [
            'id' => $shift->id,
            'date' => $start->toDateString(),
            'start' => $start->format('H:i'),
            'end' => $end->format('H:i'),
            'endDate' => $end->toDateString(),
            'sector' => $shift->sector?->name,
            'function' => $shift->jobFunction?->name,
            'professional' => $shift->professional?->name,
            'status' => $shift->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function formOptions(Unit $unit): array
    {
        // Candidates: anyone with a non-revoked qualification here; full eligibility is checked on save.
        $professionals = Professional::query()
            ->whereHas('qualifications', fn (Builder $query) => $query->where('unit_id', $unit->id)->where('status', '<>', QualificationStatus::Revoked))
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'unit' => [...$unit->only('id', 'name', 'timezone'), 'complete' => $unit->isComplete()],
            'sectors' => $unit->sectors()->orderBy('name')->get(['id', 'name']),
            'functions' => JobFunction::query()->orderBy('name')->get(['id', 'name']),
            'professionals' => $professionals,
        ];
    }

    /**
     * @return array{sector_id: int, job_function_id: int, professional_id: int|null, starts_at: CarbonImmutable, ends_at: CarbonImmutable}
     */
    private static function validated(Request $request, Unit $unit): array
    {
        if (! $unit->isComplete()) {
            throw ValidationException::withMessages(['starts_at' => 'Informe UF e fuso horário da unidade antes de montar a escala.']);
        }

        // Tenant-scoped lookups: ids of another client or unit simply do not exist here.
        $exists = fn (Builder $query) => function (string $attribute, mixed $value, Closure $fail) use ($query) {
            if (! (clone $query)->whereKey($value)->exists()) {
                $fail('Opção inválida.');
            }
        };

        $request->validate([
            'sector_id' => ['bail', 'required', 'integer', $exists($unit->sectors()->getQuery())],
            'job_function_id' => ['bail', 'required', 'integer', $exists(JobFunction::query())],
            'professional_id' => ['bail', 'nullable', 'integer', $exists(Professional::query())],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
        ], attributes: ['sector_id' => 'setor', 'job_function_id' => 'função', 'professional_id' => 'profissional', 'starts_at' => 'início', 'ends_at' => 'fim']);

        $timezone = (string) $unit->timezone;

        return [
            'sector_id' => $request->integer('sector_id'),
            'job_function_id' => $request->integer('job_function_id'),
            'professional_id' => $request->filled('professional_id') ? $request->integer('professional_id') : null,
            'starts_at' => self::local('starts_at', $request->string('starts_at')->value(), $timezone),
            'ends_at' => self::local('ends_at', $request->string('ends_at')->value(), $timezone),
        ];
    }

    /**
     * A local time that does not exist in the timezone (DST gap) would silently shift: reject it.
     */
    private static function local(string $field, string $value, string $timezone): CarbonImmutable
    {
        $local = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);

        if ($local === null || $local->format('Y-m-d\TH:i') !== $value) {
            throw ValidationException::withMessages([$field => 'Data ou horário inexistente no fuso da unidade.']);
        }

        return $local->utc();
    }
}
