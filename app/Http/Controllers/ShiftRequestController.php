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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Solicitações" (steps 7a/7b, reference IMG_0918): transfers and swaps sent and received by the
 * professional, and requests awaiting the coordinator of a unit involved.
 */
class ShiftRequestController extends Controller
{
    private const WITH = [
        'shift.unit:id,name,timezone', 'shift.sector:id,name', 'shift.jobFunction:id,name',
        'counterpart.unit:id,name,timezone', 'counterpart.sector:id,name', 'counterpart.jobFunction:id,name',
        'requester:id,name', 'recipient:id,name,council,council_number,council_uf',
    ];

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

        // Requests still waiting for a unit this member manages (a swap between units waits for both).
        $toDecide = $managed === [] ? collect() : ShiftRequest::query()
            ->where('status', RequestStatus::PendingCoordinator)
            ->when($managed !== null, fn (Builder $query) => $query->where(fn (Builder $turn) => $turn
                ->where(fn (Builder $origin) => $origin->whereNull('origin_approved_at')->whereHas('shift', fn (Builder $shift) => $shift->whereIn('unit_id', $managed ?? [])))
                ->orWhere(fn (Builder $counterpart) => $counterpart->whereNull('counterpart_approved_at')->whereHas('counterpart', fn (Builder $shift) => $shift->whereIn('unit_id', $managed ?? [])))))
            ->with(self::WITH)
            ->oldest('id')
            ->limit(100)
            ->get();

        return Inertia::render('requests/index', [
            'mine' => $mine->map(fn (ShiftRequest $item) => self::item($item, $professional, false, $managed))->values(),
            'toDecide' => $toDecide->map(fn (ShiftRequest $item) => self::item($item, $professional, true, $managed))->values(),
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
        Shift::respondToRequest($shiftRequest, $professional, $accept);
        $kind = $shiftRequest->isSwap() ? 'Troca' : 'Transferência';
        Inertia::flash('toast', ['type' => 'success', 'message' => $accept ? "{$kind} aceita. Falta a aprovação da coordenação." : "{$kind} recusada."]);

        return to_route('requests.index');
    }

    public function cancel(Request $request, ShiftRequest $shiftRequest): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($shiftRequest->requester_professional_id === $professional->id, 404);
        ShiftRequest::expireStarted();
        Shift::cancelRequest($shiftRequest, $professional);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Solicitação cancelada. O plantão continua com você.']);

        return to_route('requests.index');
    }

    public function decide(Request $request, ShiftRequest $shiftRequest): RedirectResponse
    {
        $membership = UnitPolicy::currentMembership($request->user());
        $managed = $membership === null ? [] : ShiftPolicy::managedUnitIds($membership);
        $unitIds = Shift::query()->whereKey(array_filter([$shiftRequest->shift_id, $shiftRequest->counterpart_shift_id]))->pluck('unit_id')->map(fn ($id) => (int) $id);
        // Coordinators of either unit of the request (or network admins) decide.
        abort_unless($managed === null || $unitIds->intersect($managed)->isNotEmpty(), 403);
        $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:approve,0,false'],
        ], attributes: ['reason' => 'motivo da recusa']);

        ShiftRequest::expireStarted();
        $approve = $request->boolean('approve');
        Shift::decideRequest($shiftRequest, $approve, $request->filled('reason') ? $request->string('reason')->trim()->value() : null, $managed);
        $fresh = $shiftRequest->fresh();
        $message = match (true) {
            ! $approve => ($shiftRequest->isSwap() ? 'Troca' : 'Transferência').' recusada.',
            $fresh?->status === RequestStatus::Completed => $shiftRequest->isSwap() ? 'Troca aprovada. Os dois plantões mudaram de responsável.' : 'Transferência aprovada. O plantão mudou de responsável.',
            default => 'Aprovação registrada. Falta a aprovação da outra unidade.',
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('requests.index');
    }

    /**
     * Swap (step 7b): colleagues' future shifts that both sides could plausibly take (approved
     * qualifications on each side); full eligibility and UF are checked on submit. No values are shown.
     */
    public function createSwap(Request $request, Shift $shift): Response
    {
        $professional = self::professional($request);
        abort_unless($shift->professional_id === $professional->id && $shift->status === ShiftStatus::Published && $shift->starts_at->isFuture(), 403);
        $shift->load(['unit:id,name,uf,timezone', 'sector:id,name', 'jobFunction:id,name']);
        $approved = QualificationStatus::Approved->value;

        $options = Shift::query()
            ->where('status', ShiftStatus::Published)
            ->where('starts_at', '>', now())
            ->whereNull('announced_at')
            ->where('professional_id', '<>', $professional->id)
            // Swaps compare the UFs of both shifts: other UFs could never be accepted.
            ->whereHas('unit', fn (Builder $unit) => $unit->where('uf', $shift->unit->uf))
            ->whereIn('professional_id', Professional::query()->whereIn('membership_id', Membership::query()->whereNull('revoked_at')->select('id'))->select('id'))
            ->whereExists(fn (QueryBuilder $query) => $query->from('qualifications')
                ->whereColumn('qualifications.unit_id', 'shifts.unit_id')
                ->whereColumn('qualifications.job_function_id', 'shifts.job_function_id')
                ->where('qualifications.professional_id', $professional->id)
                ->where('qualifications.status', $approved))
            ->whereExists(fn (QueryBuilder $query) => $query->from('qualifications')
                ->whereColumn('qualifications.professional_id', 'shifts.professional_id')
                ->where('qualifications.unit_id', $shift->unit_id)
                ->where('qualifications.job_function_id', $shift->job_function_id)
                ->where('qualifications.status', $approved))
            ->with(['unit:id,name,timezone', 'sector:id,name', 'jobFunction:id,name', 'professional:id,name'])
            ->orderBy('starts_at')
            ->limit(100)
            ->get()
            ->map(fn (Shift $option) => [...self::shift($option), 'colleague' => $option->professional?->name]);

        return Inertia::render('requests/swap', ['shift' => self::shift($shift), 'options' => $options]);
    }

    public function storeSwap(Request $request, Shift $shift): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($shift->professional_id === $professional->id, 403);
        $request->validate([
            'counterpart_shift_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ], attributes: ['counterpart_shift_id' => 'plantão do colega', 'note' => 'mensagem']);
        // Tenant-scoped: an id from another client simply does not exist here.
        $counterpart = Shift::query()->find($request->integer('counterpart_shift_id'))
            ?? throw ValidationException::withMessages(['counterpart_shift_id' => 'Plantão inválido.']);

        ShiftRequest::expireStarted();
        $shift->requestSwap($professional, $counterpart, $request->filled('note') ? $request->string('note')->trim()->value() : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Troca solicitada. Aguarde o aceite do colega e a aprovação da coordenação.']);

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
     * @param  list<int>|null  $managed
     * @return array<string, mixed>
     */
    private static function item(ShiftRequest $item, ?Professional $me, bool $forDecision, ?array $managed): array
    {
        $other = $item->counterpart;
        $manages = fn (Shift $shift) => $managed === null || in_array($shift->unit_id, $managed, true);
        $approvals = $other === null || $other->unit_id === $item->shift->unit_id
            ? [['unit' => $item->shift->unit->name, 'approved' => $item->origin_approved_at !== null]]
            : [
                ['unit' => $item->shift->unit->name, 'approved' => $item->origin_approved_at !== null],
                ['unit' => $other->unit->name, 'approved' => $item->counterpart_approved_at !== null],
            ];
        $myTurn = ($item->origin_approved_at === null && $manages($item->shift))
            || ($other !== null && $item->counterpart_approved_at === null && $manages($other));
        $sent = $me !== null && $item->requester_professional_id === $me->id;
        $received = $me !== null && $item->recipient_professional_id === $me->id;
        $pending = in_array($item->status, RequestStatus::pending(), true);

        return [
            'id' => $item->id,
            'type' => $item->type,
            'counterpart' => $other === null ? null : self::shift($other),
            'approvals' => $approvals,
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
            'canDecide' => $forDecision && ! $sent && ! $received && $myTurn,
        ];
    }
}
