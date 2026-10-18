<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\ScheduleBlock;
use App\Models\ScheduleRule;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outpatient schedule grid (step 3.4a): unit managers and network admins set the weekly consultation
 * windows of the unit's qualified professionals and block periods without consultations.
 */
class ScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $units = self::manageable($request)->orderBy('name')->get(['id', 'tenant_id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';

        return Inertia::render('schedules/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unit' => $unit->only('id', 'name', 'timezone'),
            'professionals' => self::professionals($unit)->map->only('id', 'name')->values(),
            'rules' => ScheduleRule::query()->where('unit_id', $unit->id)->whereNull('deactivated_at')->with('professional:id,name')
                ->orderBy('professional_id')->orderBy('weekday')->orderBy('start_minute')->get()
                ->map(fn (ScheduleRule $rule) => [
                    ...$rule->only('id', 'professional_id', 'weekday', 'start_minute', 'end_minute', 'slot_minutes'),
                    'professional' => $rule->professional?->name,
                ]),
            'blocks' => ScheduleBlock::query()->where('unit_id', $unit->id)->whereNull('cancelled_at')->where('ends_at', '>', now())
                ->with('professional:id,name')->orderBy('starts_at')->get()
                ->map(fn (ScheduleBlock $block) => [
                    'id' => $block->id,
                    'professional' => $block->professional?->name,
                    'startsAt' => $block->starts_at->setTimezone($timezone)->format('d/m/Y H:i'),
                    'endsAt' => $block->ends_at->setTimezone($timezone)->format('d/m/Y H:i'),
                    'reason' => $block->reason,
                ]),
        ]);
    }

    public function storeRule(Request $request, Unit $unit): RedirectResponse
    {
        self::authorizeUnit($request, $unit);
        $data = $request->validate([
            'professional_id' => ['required', 'integer', Rule::in(self::professionals($unit)->pluck('id')->all())],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i', 'after:start'],
            'slot_minutes' => ['required', 'integer', 'between:5,240'],
        ], ['professional_id.in' => 'Escolha um profissional com habilitação aprovada nesta unidade.'], ['start' => 'início', 'end' => 'fim', 'slot_minutes' => 'duração da consulta', 'weekday' => 'dia da semana']);
        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);

        try {
            DB::transaction(function () use ($unit, $data, $minutes) {
                $rule = ScheduleRule::query()->create([
                    'unit_id' => $unit->id, 'professional_id' => $data['professional_id'], 'weekday' => $data['weekday'],
                    'start_minute' => $minutes($data['start']), 'end_minute' => $minutes($data['end']), 'slot_minutes' => $data['slot_minutes'],
                    'created_by_user_id' => Auth::id(), 'created_at' => now(),
                ]);
                AuditEvent::record('schedule.rule_created', $unit->tenant_id, $rule, ['unit_id' => $unit->id, 'professional_id' => $rule->professional_id]);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23P01') {
                throw $exception;
            }
            throw ValidationException::withMessages(['start' => 'Este horário se sobrepõe a outra faixa do profissional neste dia (em qualquer unidade).']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Horário adicionado.']);

        return to_route('schedules.index', ['unit' => $unit->id]);
    }

    public function deactivateRule(Request $request, Unit $unit, ScheduleRule $rule): RedirectResponse
    {
        self::authorizeUnit($request, $unit);
        abort_if($rule->unit_id !== $unit->id || $rule->deactivated_at !== null, 404);

        DB::transaction(function () use ($unit, $rule) {
            $rule->forceFill(['deactivated_at' => now(), 'deactivated_by_user_id' => Auth::id()])->save();
            AuditEvent::record('schedule.rule_deactivated', $unit->tenant_id, $rule, ['unit_id' => $unit->id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Horário removido da grade.']);

        return to_route('schedules.index', ['unit' => $unit->id]);
    }

    public function storeBlock(Request $request, Unit $unit): RedirectResponse
    {
        self::authorizeUnit($request, $unit);
        $data = $request->validate([
            'professional_id' => ['required', 'integer', Rule::in(self::professionals($unit)->pluck('id')->all())],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:255'],
        ], ['professional_id.in' => 'Escolha um profissional com habilitação aprovada nesta unidade.'], ['starts_at' => 'início', 'ends_at' => 'fim', 'reason' => 'motivo']);
        $local = fn (string $value) => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $unit->timezone ?? 'UTC')?->utc();

        DB::transaction(function () use ($unit, $data, $local) {
            $block = ScheduleBlock::query()->create([
                'unit_id' => $unit->id, 'professional_id' => $data['professional_id'], 'starts_at' => $local($data['starts_at']), 'ends_at' => $local($data['ends_at']),
                'reason' => $data['reason'], 'created_by_user_id' => Auth::id(), 'created_at' => now(),
            ]);
            AuditEvent::record('schedule.block_created', $unit->tenant_id, $block, ['unit_id' => $unit->id, 'professional_id' => $block->professional_id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bloqueio registrado.']);

        return to_route('schedules.index', ['unit' => $unit->id]);
    }

    public function cancelBlock(Request $request, Unit $unit, ScheduleBlock $block): RedirectResponse
    {
        self::authorizeUnit($request, $unit);
        abort_if($block->unit_id !== $unit->id || $block->cancelled_at !== null, 404);

        DB::transaction(function () use ($unit, $block) {
            $block->forceFill(['cancelled_at' => now(), 'cancelled_by_user_id' => Auth::id()])->save();
            AuditEvent::record('schedule.block_cancelled', $unit->tenant_id, $block, ['unit_id' => $unit->id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bloqueio cancelado.']);

        return to_route('schedules.index', ['unit' => $unit->id]);
    }

    /**
     * @return Builder<Unit>
     */
    private static function manageable(Request $request): Builder
    {
        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        return Professional::manageableUnits($membership);
    }

    private static function authorizeUnit(Request $request, Unit $unit): void
    {
        abort_unless(self::manageable($request)->whereKey($unit->id)->exists(), 403);
    }

    /**
     * Professionals with an approved qualification at the unit.
     *
     * @return Collection<int, Professional>
     */
    private static function professionals(Unit $unit): Collection
    {
        return Professional::query()->whereIn('id', Qualification::query()->where('unit_id', $unit->id)->where('status', QualificationStatus::Approved)->select('professional_id'))
            ->orderBy('name')->get(['id', 'name']);
    }
}
