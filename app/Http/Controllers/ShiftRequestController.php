<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Professional;
use App\Models\Shift;
use App\Models\ShiftRequest;
use App\Policies\ShiftPolicy;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use App\Scheduling\RequestStatus;
use App\Scheduling\ShiftStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Solicitações" (step 7a, reference IMG_0918): direct transfers sent and received by the professional,
 * and transfers awaiting the coordinator of the shift's unit.
 */
class ShiftRequestController extends Controller
{
    private const WITH = ['shift.unit:id,name,timezone', 'shift.sector:id,name', 'shift.jobFunction:id,name', 'requester:id,name', 'recipient:id,name,council,council_number,council_uf'];

    public function index(Request $request): Response
    {
        [$professional, $managed] = self::actor($request);
        ShiftRequest::expireStarted();

        $mine = $professional === null ? collect() : ShiftRequest::query()
            ->where(fn (Builder $query) => $query->where('requester_professional_id', $professional->id)->orWhere('recipient_professional_id', $professional->id))
            ->with(self::WITH)
            ->latest('id')
            ->limit(100)
            ->get();

        $toDecide = $managed === [] ? collect() : ShiftRequest::query()
            ->where('status', RequestStatus::PendingCoordinator)
            ->when($managed !== null, fn (Builder $query) => $query->whereHas('shift', fn (Builder $shift) => $shift->whereIn('unit_id', $managed ?? [])))
            ->with(self::WITH)
            ->oldest('id')
            ->limit(100)
            ->get();

        return Inertia::render('requests/index', [
            'mine' => $mine->map(fn (ShiftRequest $item) => self::item($item, $professional, false))->values(),
            'toDecide' => $toDecide->map(fn (ShiftRequest $item) => self::item($item, $professional, true))->values(),
            'isCoordinator' => $managed !== [],
        ]);
    }

    public function createTransfer(Request $request, Shift $shift): Response
    {
        $professional = self::professional($request);
        abort_unless($shift->professional_id === $professional->id && $shift->status === ShiftStatus::Published && $shift->starts_at->isFuture(), 403);
        $shift->load(['unit:id,name,timezone', 'sector:id,name', 'jobFunction:id,name']);

        // Colleagues with access and an approved qualification here; full eligibility is checked on submit.
        $colleagues = Professional::query()
            ->whereIn('membership_id', Membership::query()->whereNull('revoked_at')->select('id'))
            ->whereKeyNot($professional->id)
            ->whereHas('qualifications', fn (Builder $query) => $query->where('unit_id', $shift->unit_id)->where('job_function_id', $shift->job_function_id)->where('status', QualificationStatus::Approved))
            ->orderBy('name')
            ->get(['id', 'name', 'council', 'council_number', 'council_uf'])
            ->map(fn (Professional $colleague) => ['id' => $colleague->id, 'name' => $colleague->name, 'registration' => self::registration($colleague)]);

        return Inertia::render('requests/transfer', ['shift' => self::shift($shift), 'colleagues' => $colleagues]);
    }

    public function storeTransfer(Request $request, Shift $shift): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($shift->professional_id === $professional->id, 403);
        $request->validate([
            'recipient_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ], attributes: ['recipient_id' => 'colega', 'note' => 'mensagem']);
        // Tenant-scoped: an id from another client simply does not exist here.
        $recipient = Professional::query()->find($request->integer('recipient_id'))
            ?? throw ValidationException::withMessages(['recipient_id' => 'Colega inválido.']);

        ShiftRequest::expireStarted();
        $shift->requestTransfer($professional, $recipient, $request->filled('note') ? $request->string('note')->trim()->value() : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transferência solicitada. Aguarde o aceite do colega e a aprovação da coordenação.']);

        return to_route('requests.index');
    }

    public function respond(Request $request, ShiftRequest $shiftRequest): RedirectResponse
    {
        $professional = self::professional($request);
        // Someone else's request is not revealed, only "not found".
        abort_unless($shiftRequest->recipient_professional_id === $professional->id, 404);
        $request->validate(['accept' => ['required', 'boolean']]);
        ShiftRequest::expireStarted();
        $accept = $request->boolean('accept');
        $shiftRequest->shift()->firstOrFail()->respondToTransfer($shiftRequest, $professional, $accept);
        Inertia::flash('toast', ['type' => 'success', 'message' => $accept ? 'Transferência aceita. Falta a aprovação da coordenação.' : 'Transferência recusada.']);

        return to_route('requests.index');
    }

    public function cancel(Request $request, ShiftRequest $shiftRequest): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($shiftRequest->requester_professional_id === $professional->id, 404);
        ShiftRequest::expireStarted();
        $shiftRequest->shift()->firstOrFail()->cancelTransfer($shiftRequest, $professional);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitação cancelada. O plantão continua com você.']);

        return to_route('requests.index');
    }

    public function decide(Request $request, ShiftRequest $shiftRequest): RedirectResponse
    {
        $shift = $shiftRequest->shift()->firstOrFail();
        Gate::authorize('update', $shift);
        $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:approve,0,false'],
        ], attributes: ['reason' => 'motivo da recusa']);

        ShiftRequest::expireStarted();
        $approve = $request->boolean('approve');
        $shift->decideTransfer($shiftRequest, $approve, $request->filled('reason') ? $request->string('reason')->trim()->value() : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => $approve ? 'Transferência aprovada. O plantão mudou de responsável.' : 'Transferência recusada.']);

        return to_route('requests.index');
    }

    /**
     * @return array{Professional|null, list<int>|null}
     */
    private static function actor(Request $request): array
    {
        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);
        $professional = Professional::query()->where('membership_id', $membership->id)->first();
        $managed = ShiftPolicy::managedUnitIds($membership);
        abort_if($professional === null && $managed === [], 403);

        return [$professional, $managed];
    }

    private static function professional(Request $request): Professional
    {
        $membership = UnitPolicy::currentMembership($request->user());
        $professional = $membership === null ? null : Professional::query()->where('membership_id', $membership->id)->first();
        abort_if($professional === null, 403);

        return $professional;
    }

    private static function registration(Professional $professional): ?string
    {
        return $professional->council === null ? null : "{$professional->council} {$professional->council_number}/{$professional->council_uf}";
    }

    /**
     * @return array<string, mixed>
     */
    private static function shift(Shift $shift): array
    {
        $timezone = $shift->unit->timezone ?? 'UTC';
        $start = $shift->starts_at->setTimezone($timezone);
        $end = $shift->ends_at->setTimezone($timezone);

        return [
            'id' => $shift->id,
            'unit' => $shift->unit->name,
            'date' => $start->toDateString(),
            'start' => $start->format('H:i'),
            'end' => $end->format('H:i'),
            'endDate' => $end->toDateString(),
            'sector' => $shift->sector?->name,
            'function' => $shift->jobFunction?->name,
            // User decision (17/10/2026): day shift starts 07:00–18:59 local, night otherwise.
            'period' => $start->hour >= 7 && $start->hour < 19 ? 'day' : 'night',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(ShiftRequest $item, ?Professional $me, bool $forDecision): array
    {
        $sent = $me !== null && $item->requester_professional_id === $me->id;
        $received = $me !== null && $item->recipient_professional_id === $me->id;
        $pending = in_array($item->status, RequestStatus::pending(), true);

        return [
            'id' => $item->id,
            'status' => $item->status->value,
            'statusLabel' => $item->status->label(),
            'requestedAt' => $item->created_at->setTimezone($item->shift->unit->timezone ?? 'UTC')->format('d/m/Y H:i'),
            'shift' => self::shift($item->shift),
            'requester' => $item->requester->name,
            'recipient' => $item->recipient->name,
            'recipientRegistration' => self::registration($item->recipient),
            'note' => $item->note,
            'decisionReason' => $item->decision_reason,
            'direction' => $sent ? 'sent' : ($received ? 'received' : null),
            'canRespond' => ! $forDecision && $received && $item->status === RequestStatus::PendingRecipient,
            'canCancel' => ! $forDecision && $sent && $pending,
            // Participants never decide (PRODUCT.md); the server checks it again.
            'canDecide' => $forDecision && ! $sent && ! $received,
        ];
    }
}
