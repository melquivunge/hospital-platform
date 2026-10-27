<?php

namespace App\Http\Controllers;

use App\Models\BillingLot;
use App\Models\Insurer;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.5b1 (ADR-0010): the TISS lots of a unit, for its Billing role (or the network admin): what is ready to go
 * (closed insurance accounts outside any active lot, by insurer, competence and guide type; numbers only), the lots
 * generated, and their cancellation with a reason.
 */
class BillingLotController extends Controller
{
    /** ponytail: newest first and capped; paginate when a unit's lots outgrow it. */
    private const LIST_LIMIT = 100;

    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';
        $ready = DB::query()->fromSub(BillingLot::ready($unit->id, $timezone), 'ready')
            ->select('insurer_id', 'competence', 'guide_type')->selectRaw('count(*) AS accounts, sum(total_cents) AS total_cents')
            ->groupBy('insurer_id', 'competence', 'guide_type')->orderBy('competence')->orderBy('insurer_id')->orderBy('guide_type')->get();
        $lots = BillingLot::query()->where('unit_id', $unit->id)->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();
        $insurers = Insurer::query()->whereKey($ready->pluck('insurer_id')->merge($lots->pluck('insurer_id'))->unique()->all())->pluck('name', 'id');
        $users = User::query()->whereKey($lots->pluck('created_by_user_id')->merge($lots->pluck('cancelled_by_user_id'))->filter()->unique()->all())->pluck('name', 'id');

        return Inertia::render('billing-lots/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'ready' => $ready->map(fn ($group) => [
                'insurerId' => (int) $group->insurer_id,
                'insurer' => $insurers->get($group->insurer_id),
                'competence' => substr((string) $group->competence, 0, 7),
                'guideType' => $group->guide_type,
                'accounts' => (int) $group->accounts,
                'totalCents' => (int) $group->total_cents,
            ])->values(),
            'lots' => $lots->map(fn (BillingLot $lot) => [
                'id' => $lot->id,
                'insurer' => $insurers->get($lot->insurer_id),
                'competence' => $lot->competence->format('m/Y'),
                'guideType' => $lot->guide_type,
                'guideCount' => $lot->guide_count,
                'totalCents' => $lot->total_cents,
                'status' => $lot->status,
                'createdAt' => $lot->created_at->setTimezone($timezone)->format('d/m/Y H:i'),
                'createdBy' => $users->get($lot->created_by_user_id),
                'cancelledAt' => $lot->cancelled_at?->setTimezone($timezone)->format('d/m/Y H:i'),
                'cancelledBy' => $lot->cancelled_by_user_id === null ? null : $users->get($lot->cancelled_by_user_id),
                'cancelReason' => $lot->cancel_reason,
            ])->values(),
            'guideTypes' => BillingLot::GUIDE_TYPES,
            'maxGuides' => BillingLot::MAX_GUIDES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $unitIds = BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user()));
        abort_if($unitIds === [], 403);
        $data = $request->validateWithBag('lot', [
            'unit_id' => ['required', 'integer', Rule::in($unitIds)],
            // The tenant scope keeps another network's insurer out.
            'insurer_id' => ['bail', 'required', 'integer', fn (string $attribute, mixed $value, \Closure $fail) => Insurer::query()->whereKey($value)->exists() || $fail('Convênio inválido.')],
            'competence' => ['required', 'date_format:Y-m'],
            'guide_type' => ['required', Rule::in(array_keys(BillingLot::GUIDE_TYPES))],
        ], [], ['insurer_id' => 'convênio', 'competence' => 'competência', 'guide_type' => 'tipo de guia']);

        try {
            $lot = BillingLot::generate(Unit::query()->findOrFail($data['unit_id']), (int) $data['insurer_id'],
                CarbonImmutable::createFromFormat('!Y-m', $data['competence']), $data['guide_type'], $request->user());
        } catch (UniqueConstraintViolationException) {
            // Another lot took these accounts at the same time (one active lot per account).
            throw ValidationException::withMessages(['lot' => 'Outro lote acabou de levar estas contas. Atualize a página e confira.'])->errorBag('lot');
        }
        if ($lot === null) {
            throw ValidationException::withMessages(['lot' => 'Não há contas prontas para este lote.'])->errorBag('lot');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Lote {$lot->id} gerado com {$lot->guide_count} guia(s)."]);

        return to_route('billing-lots.index', ['unit' => $lot->unit_id]);
    }

    public function cancel(Request $request, BillingLot $lot): RedirectResponse
    {
        abort_unless(in_array($lot->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $bag = "cancel-{$lot->id}";
        $data = $request->validateWithBag($bag, ['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        if (! $lot->cancel(trim((string) $data['reason']), $request->user())) {
            throw ValidationException::withMessages(['reason' => 'Este lote já foi cancelado.'])->errorBag($bag);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Lote {$lot->id} cancelado; as contas voltaram para as prontas."]);

        return to_route('billing-lots.index', ['unit' => $lot->unit_id]);
    }
}
