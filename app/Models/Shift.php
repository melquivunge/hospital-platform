<?php

namespace App\Models;

use App\Governance\PolicyKey;
use App\Professionals\Eligibility;
use App\Scheduling\ApplicationStatus;
use App\Scheduling\RequestStatus;
use App\Scheduling\ShiftStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shift of a unit (V1-04). Every write locks the professionals involved (id order) and then the shift,
 * revalidates eligibility inside the transaction, and leaves overlaps to the shifts_no_overlap constraint.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $sector_id
 * @property int $job_function_id
 * @property int|null $professional_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property ShiftStatus $status
 * @property int|null $eligibility_version
 * @property int|null $qualification_id
 * @property Carbon|null $published_at
 * @property int|null $published_by_user_id
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 * @property int $created_by_user_id
 * @property int|null $value_cents
 * @property Carbon|null $announced_at
 */
#[Fillable(['unit_id', 'sector_id', 'job_function_id', 'professional_id', 'starts_at', 'ends_at', 'value_cents', 'status', 'created_by_user_id'])]
class Shift extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'published_at' => 'datetime',
            'announced_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'unit_id' => 'integer',
            'sector_id' => 'integer',
            'job_function_id' => 'integer',
            'professional_id' => 'integer',
            'value_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * @return BelongsTo<JobFunction, $this>
     */
    public function jobFunction(): BelongsTo
    {
        return $this->belongsTo(JobFunction::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @param  array{sector_id: int, job_function_id: int, professional_id: int|null, starts_at: CarbonInterface, ends_at: CarbonInterface, value_cents?: int|null}  $data
     */
    public static function createDraft(Unit $unit, array $data): self
    {
        return self::writing(function () use ($unit, $data) {
            self::lockProfessionals([$data['professional_id']]);
            self::assertAssignable($data);

            $shift = self::create([...$data, 'unit_id' => $unit->id, 'status' => ShiftStatus::Draft, 'created_by_user_id' => Auth::id()]);
            AuditEvent::record('shift.created', $shift->tenant_id, $shift, ['unit_id' => $unit->id, 'professional_id' => $shift->professional_id]);

            return $shift;
        });
    }

    /**
     * @param  array{sector_id: int, job_function_id: int, professional_id: int|null, starts_at: CarbonInterface, ends_at: CarbonInterface, value_cents?: int|null}  $data
     */
    public function updateDraft(array $data): void
    {
        self::writing(function () use ($data) {
            $shift = $this->lockedWithProfessionals(ShiftStatus::Draft, $data['professional_id']);
            self::assertAssignable($data);

            $shift->fill($data);
            $changed = array_keys($shift->getDirty());
            $shift->save();

            if ($changed !== []) {
                AuditEvent::record('shift.updated', $shift->tenant_id, $shift, ['fields' => $changed, 'professional_id' => $shift->professional_id]);
            }
        });
    }

    /**
     * Revalidates everything at publication time: qualifications may have changed since the draft.
     */
    public function publish(): void
    {
        self::writing(function () {
            $shift = $this->lockedWithProfessionals(ShiftStatus::Draft);
            $unit = $shift->unit()->firstOrFail();

            if (! $unit->isComplete()) {
                throw ValidationException::withMessages(['shift' => 'Informe UF e fuso horário da unidade antes de publicar.']);
            }

            $eligibility = self::assertAssignable([
                'sector_id' => $shift->sector_id,
                'job_function_id' => $shift->job_function_id,
                'professional_id' => $shift->professional_id,
                'starts_at' => $shift->starts_at,
                'ends_at' => $shift->ends_at,
            ]);

            $shift->forceFill([
                'status' => ShiftStatus::Published,
                'published_at' => now(),
                'published_by_user_id' => Auth::id(),
                'eligibility_version' => $eligibility?->eligibilityVersion,
                'qualification_id' => $eligibility?->qualificationId,
            ])->save();

            AuditEvent::record('shift.published', $shift->tenant_id, $shift, array_filter([
                'professional_id' => $shift->professional_id,
                'eligibility_version' => $shift->eligibility_version,
                'qualification_id' => $shift->qualification_id,
            ], fn ($value) => $value !== null));
        });
    }

    /**
     * Published shifts can only be cancelled before they start (presence handling comes in step 8).
     */
    public function cancel(string $reason): void
    {
        self::writing(function () use ($reason) {
            $shift = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($shift->status === ShiftStatus::Cancelled) {
                throw ValidationException::withMessages(['reason' => 'Este plantão já foi cancelado.']);
            }

            if ($shift->status === ShiftStatus::Published && $shift->starts_at <= now()) {
                throw ValidationException::withMessages(['reason' => 'O plantão já começou e não pode ser cancelado.']);
            }

            if ($shift->hasCheckIn()) {
                throw ValidationException::withMessages(['reason' => 'A entrada já foi registrada: o plantão não pode ser cancelado.']);
            }

            $from = $shift->status;
            $shift->forceFill([
                'status' => ShiftStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => Auth::id(),
                'cancel_reason' => $reason,
                'announced_at' => null,
            ])->save();

            AuditEvent::record('shift.cancelled', $shift->tenant_id, $shift, ['from' => $from->value, 'reason' => $reason]);
            $shift->applications()->where('status', ApplicationStatus::Active)
                ->update(['status' => ApplicationStatus::NotSelected, 'decided_at' => now(), 'decided_by_user_id' => Auth::id()]);
            ShiftRequest::pendingFor($shift->id)
                ->update(['status' => RequestStatus::Cancelled, 'decided_at' => now(), 'decided_by_user_id' => Auth::id(), 'decision_reason' => 'Plantão cancelado.']);
        });
    }

    /**
     * @return HasMany<ShiftRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(ShiftRequest::class);
    }

    /**
     * @return HasMany<ShiftRequest, $this>
     */
    public function counterpartRequests(): HasMany
    {
        return $this->hasMany(ShiftRequest::class, 'counterpart_shift_id');
    }

    /**
     * Direct transfer (step 7a): the responsible asks a colleague to take the shift. Nothing changes until
     * the colleague accepts and the unit's coordinator approves. The recipient's eligibility is checked
     * now, on acceptance and again on approval (base-unit UF, as for vacancies).
     */
    public function requestTransfer(Professional $requester, Professional $recipient, ?string $note): ShiftRequest
    {
        return self::writing(function () use ($requester, $recipient, $note) {
            self::lockProfessionals([$requester->id, $recipient->id]);
            $shift = $this->lockedIn(ShiftStatus::Published);

            if ($shift->professional_id !== $requester->id) {
                throw ValidationException::withMessages(['shift' => 'Só o responsável pode transferir este plantão.']);
            }

            if ($recipient->id === $requester->id) {
                throw ValidationException::withMessages(['recipient_id' => 'Escolha outro profissional.']);
            }

            if ($shift->announced_at !== null) {
                throw ValidationException::withMessages(['shift' => 'Cancele o anúncio antes de transferir o plantão.']);
            }

            if ($shift->hasCheckIn()) {
                throw ValidationException::withMessages(['shift' => 'A entrada já foi registrada: o plantão não pode ser transferido.']);
            }

            if (ShiftRequest::pendingFor($shift->id)->exists()) {
                throw ValidationException::withMessages(['shift' => 'Já existe uma solicitação pendente para este plantão.']);
            }

            self::assertActiveAccess($recipient, 'recipient_id');
            self::assertAssignable($shift->assignmentOf($recipient->id));
            $request = $shift->requests()->create([
                'type' => 'transfer',
                'requester_professional_id' => $requester->id,
                'recipient_professional_id' => $recipient->id,
                'status' => RequestStatus::PendingRecipient,
                'note' => $note,
                'requested_by_user_id' => Auth::id(),
            ]);
            AuditEvent::record('shift.transfer_requested', $shift->tenant_id, $shift, ['request_id' => $request->id, 'from' => $requester->id, 'to' => $recipient->id]);

            return $request;
        });
    }

    /**
     * Swap (step 7b): the requester offers this shift for a colleague's shift. Each one must be eligible
     * for the shift they receive, compared with the UF of the shift they leave (PRODUCT.md).
     */
    public function requestSwap(Professional $requester, self $counterpart, ?string $note): ShiftRequest
    {
        return self::writing(function () use ($requester, $counterpart, $note) {
            $recipientId = self::query()->whereKey($counterpart->id)->value('professional_id');

            // One message for every invalid choice: ids of drafts, vacancies or own shifts are not revealed.
            $invalid = fn () => ValidationException::withMessages(['counterpart_shift_id' => 'Escolha um plantão publicado de um colega.']);

            if ($recipientId === null || (int) $recipientId === $requester->id || $counterpart->id === $this->id) {
                throw $invalid();
            }

            $recipientId = (int) $recipientId;
            self::lockProfessionals([$requester->id, $recipientId]);
            [$shift, $other] = self::lockShifts($this->id, $counterpart->id);

            if ($shift->status !== ShiftStatus::Published || $shift->professional_id !== $requester->id) {
                throw ValidationException::withMessages(['shift' => 'Só o responsável pode trocar este plantão.']);
            }

            if ($other === null || $other->status !== ShiftStatus::Published || $other->professional_id !== $recipientId) {
                throw $invalid();
            }

            if ($shift->announced_at !== null || $other->announced_at !== null) {
                throw ValidationException::withMessages(['shift' => 'Plantões anunciados para substituição não entram em troca.']);
            }

            if ($shift->hasCheckIn() || $other->hasCheckIn()) {
                throw ValidationException::withMessages(['shift' => 'A entrada já foi registrada em um dos plantões: a troca não é possível.']);
            }

            if (ShiftRequest::pendingFor($shift->id)->exists() || ShiftRequest::pendingFor($other->id)->exists()) {
                throw ValidationException::withMessages(['shift' => 'Já existe uma solicitação pendente para um dos plantões.']);
            }

            $recipient = Professional::query()->findOrFail($recipientId);
            self::assertActiveAccess($recipient, 'counterpart_shift_id');
            self::assertRequestEligible($shift, $other, $requester->id, $recipientId);

            $request = $shift->requests()->create([
                'type' => 'swap',
                'counterpart_shift_id' => $other->id,
                'requester_professional_id' => $requester->id,
                'recipient_professional_id' => $recipientId,
                'status' => RequestStatus::PendingRecipient,
                'note' => $note,
                'requested_by_user_id' => Auth::id(),
            ]);
            AuditEvent::record('shift.swap_requested', $shift->tenant_id, $shift, ['request_id' => $request->id, 'from' => $requester->id, 'to' => $recipientId, 'counterpart_shift_id' => $other->id]);

            return $request;
        });
    }

    public static function respondToRequest(ShiftRequest $request, Professional $recipient, bool $accept): void
    {
        self::writing(function () use ($request, $recipient, $accept) {
            [$shift, $other, $current] = self::lockRequest($request);

            if ($current->recipient_professional_id !== $recipient->id || $current->status !== RequestStatus::PendingRecipient) {
                throw ValidationException::withMessages(['request' => 'Esta solicitação não está mais aguardando sua resposta.']);
            }

            if ($accept) {
                self::assertRequestStillValid($shift, $other, $current);
                self::assertRequestEligible($shift, $other, $current->requester_professional_id, $current->recipient_professional_id);
            }

            $current->forceFill(['status' => $accept ? RequestStatus::PendingCoordinator : RequestStatus::Refused, 'recipient_decided_at' => now()])->save();
            AuditEvent::record("shift.{$current->type}_".($accept ? 'accepted' : 'refused'), $shift->tenant_id, $shift, ['request_id' => $current->id]);
        });
    }

    public static function cancelRequest(ShiftRequest $request, Professional $requester): void
    {
        self::writing(function () use ($request, $requester) {
            [$shift, , $current] = self::lockRequest($request);

            if ($current->requester_professional_id !== $requester->id || ! in_array($current->status, RequestStatus::pending(), true)) {
                throw ValidationException::withMessages(['request' => 'Esta solicitação não pode mais ser cancelada.']);
            }

            $current->forceFill(['status' => RequestStatus::Cancelled, 'decided_at' => now(), 'decided_by_user_id' => Auth::id()])->save();
            AuditEvent::record("shift.{$current->type}_cancelled", $shift->tenant_id, $shift, ['request_id' => $current->id]);
        });
    }

    /**
     * Coordinator's decision for the units they manage ($managedUnits null = every unit). A transfer, or a
     * swap within one unit, needs one approval; a swap between units needs both (PRODUCT.md, "Entre
     * unidades"), and someone managing both records both. Any refusal ends the request. The final approval
     * re-checks everything and applies both sides or none. Participants never decide.
     *
     * @param  list<int>|null  $managedUnits
     */
    public static function decideRequest(ShiftRequest $request, bool $approve, ?string $reason, ?array $managedUnits): void
    {
        self::writing(function () use ($request, $approve, $reason, $managedUnits) {
            [$shift, $other, $current] = self::lockRequest($request);
            $kind = $current->isSwap() ? 'troca' : 'transferência';

            if ($current->status !== RequestStatus::PendingCoordinator) {
                throw ValidationException::withMessages(['request' => 'Esta solicitação não está mais aguardando a coordenação.']);
            }

            if (self::belongsToActor($current->requester_professional_id) || self::belongsToActor($current->recipient_professional_id)) {
                throw ValidationException::withMessages(['request' => "Você participa desta {$kind} e não pode decidir. Outro coordenador precisa decidir."]);
            }

            $manages = fn (self $unitShift) => $managedUnits === null || in_array($unitShift->unit_id, $managedUnits, true);
            $sameUnit = $other !== null && $other->unit_id === $shift->unit_id;
            $originTurn = $current->origin_approved_at === null && $manages($shift);
            $counterpartTurn = $other !== null && $current->counterpart_approved_at === null && $manages($other);

            if (! $originTurn && ! $counterpartTurn) {
                throw ValidationException::withMessages(['request' => 'Esta solicitação não aguarda decisão da sua unidade.']);
            }

            if (! $approve) {
                $current->forceFill(['decided_at' => now(), 'decided_by_user_id' => Auth::id(), 'decision_reason' => $reason, 'status' => RequestStatus::Refused])->save();
                AuditEvent::record("shift.{$current->type}_rejected", $shift->tenant_id, $shift, ['request_id' => $current->id, 'reason' => $reason]);

                return;
            }

            // Never record an approval on a stale request.
            self::assertRequestStillValid($shift, $other, $current);
            [$recipientEligibility, $requesterEligibility] = self::assertRequestEligible($shift, $other, $current->requester_professional_id, $current->recipient_professional_id);

            // One approval covers both sides of a swap inside the same unit.
            if ($originTurn || $sameUnit) {
                $current->forceFill(['origin_approved_by_user_id' => Auth::id(), 'origin_approved_at' => now()]);
            }

            if ($counterpartTurn || $sameUnit) {
                $current->forceFill(['counterpart_approved_by_user_id' => Auth::id(), 'counterpart_approved_at' => now()]);
            }

            if ($current->origin_approved_at === null || ($other !== null && $current->counterpart_approved_at === null)) {
                $current->save();
                AuditEvent::record('shift.swap_approved_by_unit', $shift->tenant_id, $shift, ['request_id' => $current->id, 'reason' => $reason]);

                return;
            }

            if ($other === null) {
                $shift->forceFill([
                    'professional_id' => $current->recipient_professional_id,
                    'eligibility_version' => $recipientEligibility?->eligibilityVersion,
                    'qualification_id' => $recipientEligibility?->qualificationId,
                ])->save();
            } else {
                // Free one side first: the overlap constraint is checked per row, and both people may
                // temporarily appear on overlapping shifts while the swap is half applied.
                $shift->forceFill(['professional_id' => null])->save();
                $other->forceFill([
                    'professional_id' => $current->requester_professional_id,
                    'eligibility_version' => $requesterEligibility?->eligibilityVersion,
                    'qualification_id' => $requesterEligibility?->qualificationId,
                ])->save();
                $shift->forceFill([
                    'professional_id' => $current->recipient_professional_id,
                    'eligibility_version' => $recipientEligibility?->eligibilityVersion,
                    'qualification_id' => $recipientEligibility?->qualificationId,
                ])->save();
                $other->applications()->where('status', ApplicationStatus::Selected)->update(['status' => ApplicationStatus::Replaced]);
            }

            $current->forceFill(['decided_at' => now(), 'decided_by_user_id' => Auth::id(), 'decision_reason' => $reason, 'status' => RequestStatus::Completed])->save();
            // A winner from an earlier vacancy round no longer holds the shift.
            $shift->applications()->where('status', ApplicationStatus::Selected)->update(['status' => ApplicationStatus::Replaced]);

            AuditEvent::record($other === null ? 'shift.transferred' : 'shift.swapped', $shift->tenant_id, $shift, array_filter([
                'request_id' => $current->id,
                'from' => $current->requester_professional_id,
                'to' => $current->recipient_professional_id,
                'counterpart_shift_id' => $other?->id,
                'reason' => $reason,
                'eligibility_version' => $shift->eligibility_version,
            ], fn ($value) => $value !== null));
        });
    }

    /**
     * Locks both parties, then the request's shift(s) in id order, then the request row: the order every
     * request write uses (parties and shifts of a request never change).
     *
     * @return array{self, self|null, ShiftRequest}
     */
    private static function lockRequest(ShiftRequest $request): array
    {
        self::lockProfessionals([$request->requester_professional_id, $request->recipient_professional_id]);
        [$shift, $other] = self::lockShifts($request->shift_id, $request->counterpart_shift_id);
        $current = ShiftRequest::query()->lockForUpdate()->whereKey($request->id)->firstOrFail();

        return [$shift, $other, $current];
    }

    /**
     * @return array{self, self|null}
     */
    private static function lockShifts(int $id, ?int $otherId): array
    {
        $shifts = self::query()->whereKey(array_filter([$id, $otherId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$shifts[$id] ?? throw (new ModelNotFoundException)->setModel(self::class), $otherId === null ? null : $shifts[$otherId] ?? null];
    }

    /**
     * Shifts must still be published, held by the same people and not announced.
     */
    private static function assertRequestStillValid(self $shift, ?self $other, ShiftRequest $request): void
    {
        $holds = fn (?self $candidate, int $professionalId) => $candidate !== null
            && $candidate->status === ShiftStatus::Published
            && $candidate->professional_id === $professionalId
            && $candidate->announced_at === null
            && ! $candidate->hasCheckIn();

        if (! $holds($shift, $request->requester_professional_id) || ($request->isSwap() && ! $holds($other, $request->recipient_professional_id))) {
            throw ValidationException::withMessages(['request' => 'Os plantões mudaram desde a solicitação. Recuse e peça uma nova solicitação.']);
        }
    }

    /**
     * Recipient on the requester's shift and, for a swap, requester on the colleague's shift. In a swap
     * each side is compared with the UF of the other shift; a transfer uses the recipient's base UF.
     *
     * @return array{Eligibility|null, Eligibility|null}
     */
    private static function assertRequestEligible(self $shift, ?self $other, int $requesterId, int $recipientId): array
    {
        if ($other === null) {
            $eligibility = self::assertAssignable($shift->assignmentOf($recipientId));
            self::assertNoConflict($recipientId, $shift, []);

            return [$eligibility, null];
        }

        $uf = fn (self $unitShift) => (string) Unit::query()->whereKey($unitShift->unit_id)->value('uf');
        $eligibilities = [
            self::assertAssignable($shift->assignmentOf($recipientId), $uf($other)),
            self::assertAssignable($other->assignmentOf($requesterId), $uf($shift)),
        ];
        // Resulting schedule: each one leaves the shift they give up (PRODUCT.md, conflito de horário).
        self::assertNoConflict($recipientId, $shift, [$other->id]);
        self::assertNoConflict($requesterId, $other, [$shift->id]);

        return $eligibilities;
    }

    /**
     * Early feedback on overlaps in the resulting schedule ([start, end), as shifts_no_overlap); the
     * constraint still guarantees it when the change is applied.
     *
     * @param  list<int>  $leaving
     */
    private static function assertNoConflict(int $professionalId, self $incoming, array $leaving): void
    {
        $conflict = self::query()
            ->where('professional_id', $professionalId)
            ->where('status', '<>', ShiftStatus::Cancelled)
            ->whereKeyNot([$incoming->id, ...$leaving])
            ->where('starts_at', '<', $incoming->ends_at)
            ->where('ends_at', '>', $incoming->starts_at)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages(['professional_id' => 'O profissional já tem outro plantão nesse horário.']);
        }
    }

    private static function assertActiveAccess(Professional $professional, string $field): void
    {
        if ($professional->membership_id === null || ! Membership::query()->whereKey($professional->membership_id)->whereNull('revoked_at')->exists()) {
            throw ValidationException::withMessages([$field => 'Este profissional ainda não tem acesso à plataforma para aceitar.']);
        }
    }

    /**
     * Presence by location (step 8a, PRODUCT.md "Presença com limite geográfico"). The server decides:
     * the reading must be recent, accurate enough (unit policy) and inside a point's radius counting the
     * accuracy (distance + accuracy <= radius), within the configured window. Rejected attempts are kept
     * with their reason; repeating the same idempotency key returns the first result. The policy
     * versions and the point used are recorded with every decision.
     *
     * @param  array{latitude: float, longitude: float, accuracy: float, measured_at: CarbonImmutable}  $reading
     */
    public function recordAttendance(Professional $professional, string $kind, array $reading, string $idempotencyKey): AttendanceEvent
    {
        return self::writing(function () use ($professional, $kind, $reading, $idempotencyKey) {
            self::lockProfessionals([$professional->id]);
            $shift = self::query()->lockForUpdate()->findOrFail($this->id);

            // The professional lock serializes attempts of the same person; the unique key backs it.
            $repeated = AttendanceEvent::query()->where('user_id', Auth::id())->where('idempotency_key', $idempotencyKey)->first();

            if ($repeated !== null) {
                if ($repeated->shift_id !== $shift->id || $repeated->kind !== $kind) {
                    throw ValidationException::withMessages(['attendance' => 'Tentativa repetida com outro conteúdo. Recarregue a página.']);
                }

                return $repeated;
            }

            if ($shift->status !== ShiftStatus::Published || $shift->professional_id !== $professional->id) {
                throw ValidationException::withMessages(['attendance' => 'Este plantão não está com você.']);
            }

            $accepted = fn (string $eventKind) => $shift->attendanceEvents()->where('kind', $eventKind)->where('result', 'accepted')->exists();

            if ($kind === 'check_in' && $accepted('check_in')) {
                throw ValidationException::withMessages(['attendance' => 'A entrada já foi registrada.']);
            }

            if ($kind === 'check_out' && ! $accepted('check_in')) {
                throw ValidationException::withMessages(['attendance' => 'Registre a entrada antes da saída.']);
            }

            if ($kind === 'check_out' && $accepted('check_out')) {
                throw ValidationException::withMessages(['attendance' => 'A saída já foi registrada.']);
            }

            if ($kind === 'check_out' && $shift->occurrences()->where('kind', 'check_out')->where('status', 'granted')->exists()) {
                throw ValidationException::withMessages(['attendance' => 'A saída já foi encerrada pela coordenação.']);
            }

            $now = CarbonImmutable::now();
            $decision = self::attendanceDecision($shift, $kind, $reading, $now);

            $event = $shift->attendanceEvents()->create([
                'professional_id' => $professional->id,
                'user_id' => Auth::id(),
                'kind' => $kind,
                'result' => $decision['reason'] === null ? 'accepted' : 'rejected',
                'reason' => $decision['reason'],
                'latitude' => $reading['latitude'],
                'longitude' => $reading['longitude'],
                'accuracy_m' => $reading['accuracy'],
                'measured_at' => $reading['measured_at'],
                'received_at' => $now,
                'point_id' => $decision['point']?->id,
                'distance_m' => $decision['distance'],
                'policy_version_ids' => $decision['policies'],
                'idempotency_key' => $idempotencyKey,
            ]);

            // Presence started: no more replacement, transfer or swap (PRODUCT.md, limite temporal).
            if ($kind === 'check_in' && $event->accepted()) {
                ShiftRequest::pendingFor($shift->id)
                    ->update(['status' => RequestStatus::Expired, 'decided_at' => $now, 'decision_reason' => 'Entrada registrada.']);

                if ($shift->announced_at !== null) {
                    $shift->forceFill(['announced_at' => null])->save();
                    $shift->applications()->where('status', ApplicationStatus::Active)
                        ->update(['status' => ApplicationStatus::NotSelected, 'decided_at' => $now]);
                }
            }

            return $event;
        });
    }

    /**
     * The professional reports a presence problem for their own shift (step 8b). The attempt stays as
     * it was; nothing is confirmed until a coordinator decides.
     */
    public function reportOccurrence(Professional $professional, string $kind, string $description): AttendanceOccurrence
    {
        return self::writing(function () use ($professional, $kind, $description) {
            self::lockProfessionals([$professional->id]);
            $shift = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($shift->status !== ShiftStatus::Published || $shift->professional_id !== $professional->id) {
                throw ValidationException::withMessages(['occurrence' => 'Este plantão não está com você.']);
            }

            if ($shift->occurrences()->where('kind', $kind)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['occurrence' => 'Já existe uma ocorrência aberta deste tipo para o plantão.']);
            }

            $occurrence = $shift->occurrences()->create([
                'professional_id' => $professional->id,
                'kind' => $kind,
                'description' => $description,
                'reported_by_user_id' => Auth::id(),
                'status' => 'open',
            ]);
            AuditEvent::record('attendance.occurrence_reported', $shift->tenant_id, $shift, ['occurrence_id' => $occurrence->id, 'kind' => $kind]);

            return $occurrence;
        });
    }

    /**
     * Coordinator's decision (PRODUCT.md, "Falhas e ajuste administrativo"): reason always; a granted exit
     * closes the presence at the informed time (between the entry and now) without fabricating a
     * geographic exit. A granted entry never creates a check-in. The professional never decides.
     */
    public static function decideOccurrence(AttendanceOccurrence $occurrence, bool $grant, string $reason, ?CarbonImmutable $informedAt): void
    {
        self::writing(function () use ($occurrence, $grant, $reason, $informedAt) {
            self::lockProfessionals([$occurrence->professional_id]);
            $shift = self::query()->lockForUpdate()->findOrFail($occurrence->shift_id);
            $current = $shift->occurrences()->lockForUpdate()->whereKey($occurrence->id)->firstOrFail();

            if ($current->status !== 'open') {
                throw ValidationException::withMessages(['occurrence' => 'Esta ocorrência já foi decidida.']);
            }

            if (self::belongsToActor($current->professional_id)) {
                throw ValidationException::withMessages(['occurrence' => 'Você não pode decidir a própria ocorrência. Outro coordenador precisa decidir.']);
            }

            if ($shift->status !== ShiftStatus::Published || $shift->professional_id !== $current->professional_id) {
                throw ValidationException::withMessages(['occurrence' => 'O plantão mudou desde a ocorrência. Indefira e peça uma nova.']);
            }

            $exit = $grant && $current->kind === 'check_out';

            if ($exit) {
                $entry = $shift->attendanceEvents()->where('kind', 'check_in')->where('result', 'accepted')->value('received_at');

                if ($entry === null) {
                    throw ValidationException::withMessages(['occurrence' => 'Sem entrada registrada não há presença para encerrar.']);
                }

                if ($shift->attendanceEvents()->where('kind', 'check_out')->where('result', 'accepted')->exists()) {
                    throw ValidationException::withMessages(['occurrence' => 'A saída já foi registrada por localização.']);
                }

                if ($informedAt === null || $informedAt < CarbonImmutable::parse($entry) || $informedAt > now()) {
                    throw ValidationException::withMessages(['informed_at' => 'Informe um horário de saída entre a entrada e agora.']);
                }
            }

            $current->forceFill([
                'status' => $grant ? 'granted' : 'denied',
                'informed_at' => $exit ? $informedAt : null,
                'decided_by_user_id' => Auth::id(),
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();
            AuditEvent::record('attendance.occurrence_'.($grant ? 'granted' : 'denied'), $shift->tenant_id, $shift, array_filter([
                'occurrence_id' => $current->id,
                'kind' => $current->kind,
                'reason' => $reason,
                'informed_at' => $current->informed_at?->toIso8601String(),
            ], fn ($value) => $value !== null));
        });
    }

    /**
     * @return HasMany<AttendanceOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(AttendanceOccurrence::class);
    }

    /**
     * @return HasMany<AttendanceEvent, $this>
     */
    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function hasCheckIn(): bool
    {
        return $this->attendanceEvents()->where('kind', 'check_in')->where('result', 'accepted')->exists();
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy: float, measured_at: CarbonImmutable}  $reading
     * @return array{reason: string|null, point: AttendancePoint|null, distance: float|null, policies: array<string, array{int, int|null}>}
     */
    private static function attendanceDecision(self $shift, string $kind, array $reading, CarbonImmutable $now): array
    {
        $unit = $shift->unit()->firstOrFail();
        $policies = [];
        $values = [];

        foreach ([PolicyKey::AttendanceMaxAccuracy, PolicyKey::AttendanceCheckInLead, PolicyKey::AttendanceCheckOutGrace] as $key) {
            $effective = PolicyVersion::effective($key, $unit, $now);

            if ($effective !== null) {
                $values[$key->value] = $effective->value;
                $policies[$key->value] = [$effective->network->id, $effective->local?->id];
            }
        }

        $points = AttendancePoint::query()->where('unit_id', $unit->id)->whereNull('deactivated_at')->get();
        $result = fn (?string $reason, ?AttendancePoint $point = null, ?float $distance = null) => ['reason' => $reason, 'point' => $point, 'distance' => $distance, 'policies' => $policies];

        $maxAccuracy = $values[PolicyKey::AttendanceMaxAccuracy->value] ?? null;
        $lead = $values[PolicyKey::AttendanceCheckInLead->value] ?? null;
        $grace = $values[PolicyKey::AttendanceCheckOutGrace->value] ?? null;

        if ($points->isEmpty() || $maxAccuracy === null || $lead === null || $grace === null) {
            return $result('not_configured');
        }

        $opensAt = $kind === 'check_in' ? $shift->starts_at->subMinutes($lead) : $shift->starts_at;
        $closesAt = $kind === 'check_in' ? $shift->ends_at : $shift->ends_at->addMinutes($grace);

        if ($now < $opensAt || $now > $closesAt) {
            return $result('outside_window');
        }

        // ponytail: technical anti-replay tolerance (2 min old, 1 min ahead for clock skew), not a product rule.
        if ($reading['measured_at'] < $now->subMinutes(2) || $reading['measured_at'] > $now->addMinute()) {
            return $result('stale_measurement');
        }

        if ($reading['accuracy'] > $maxAccuracy) {
            return $result('low_accuracy');
        }

        // The point whose radius best fits the reading; accepted only if distance + accuracy fits.
        $best = $points
            ->map(fn (AttendancePoint $point) => [$point, $point->distanceTo($reading['latitude'], $reading['longitude'])])
            ->sortBy(fn (array $candidate) => $candidate[1] + $reading['accuracy'] - $candidate[0]->radius_m)
            ->first();
        [$point, $distance] = $best;

        return $distance + $reading['accuracy'] <= $point->radius_m
            ? $result(null, $point, round($distance, 1))
            : $result('outside_radius', $point, round($distance, 1));
    }

    /**
     * @return HasMany<ShiftApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(ShiftApplication::class);
    }

    /**
     * Application to an open or announced vacancy (V1-07). Eligibility is checked now and again on selection.
     */
    public function apply(Professional $professional): ShiftApplication
    {
        return self::writing(function () use ($professional) {
            self::lockProfessionals([$professional->id]);
            $shift = $this->lockedVacancy();

            if ($shift->professional_id === $professional->id) {
                throw ValidationException::withMessages(['application' => 'Este plantão já é seu.']);
            }

            // The professional lock serializes this check for the same person; the partial index backs it.
            if ($shift->applications()->where('professional_id', $professional->id)->where('status', ApplicationStatus::Active)->exists()) {
                throw ValidationException::withMessages(['application' => 'Você já se candidatou a esta vaga.']);
            }

            $eligibility = self::assertAssignable($shift->assignmentOf($professional->id));
            $application = $shift->applications()->create([
                'professional_id' => $professional->id,
                'status' => ApplicationStatus::Active,
                'eligibility_version' => $eligibility?->eligibilityVersion,
            ]);
            AuditEvent::record('shift.application_created', $shift->tenant_id, $shift, ['application_id' => $application->id, 'professional_id' => $professional->id]);

            return $application;
        });
    }

    public function withdraw(Professional $professional): void
    {
        self::writing(function () use ($professional) {
            self::lockProfessionals([$professional->id]);
            $shift = self::query()->lockForUpdate()->findOrFail($this->id);
            $application = $shift->applications()->where('professional_id', $professional->id)->where('status', ApplicationStatus::Active)->first()
                ?? throw ValidationException::withMessages(['application' => 'Você não tem candidatura ativa nesta vaga.']);

            $application->forceFill(['status' => ApplicationStatus::Withdrawn, 'withdrawn_at' => now()])->save();
            AuditEvent::record('shift.application_withdrawn', $shift->tenant_id, $shift, ['application_id' => $application->id, 'professional_id' => $professional->id]);
        });
    }

    /**
     * Justified choice among candidates (PRODUCT.md, "Plantões: seleção e responsabilidade"). A second
     * concurrent choice finds the vacancy filled; a candidate who withdrew or lost eligibility is never
     * replaced automatically. Nobody selects themselves. On an announced shift (step 6c) the responsible is
     * locked too and only changes here; the announcer cannot decide their own replacement.
     */
    public function selectCandidate(ShiftApplication $application, string $reason): void
    {
        self::writing(function () use ($application, $reason) {
            $responsible = self::query()->whereKey($this->id)->value('professional_id');
            $responsible = $responsible === null ? null : (int) $responsible;
            self::lockProfessionals([$responsible, $application->professional_id]);
            $shift = $this->lockedVacancy();

            // Someone else was assigned between the read above and the lock: the vacancy is gone.
            if ($shift->professional_id !== $responsible) {
                throw ValidationException::withMessages(['shift' => 'Esta vaga já foi preenchida.']);
            }

            $current = $shift->applications()->lockForUpdate()->whereKey($application->id)->first();

            if ($current?->status !== ApplicationStatus::Active) {
                throw ValidationException::withMessages(['application' => 'Esta candidatura não está mais ativa. Recarregue a página.']);
            }

            if (self::belongsToActor($current->professional_id)) {
                throw ValidationException::withMessages(['application' => 'Você não pode escolher a si mesmo. Outro coordenador precisa decidir.']);
            }

            if ($responsible !== null && self::belongsToActor($responsible)) {
                throw ValidationException::withMessages(['application' => 'Você anunciou este plantão e não pode escolher o substituto. Outro coordenador precisa decidir.']);
            }

            $eligibility = self::assertAssignable($shift->assignmentOf($current->professional_id));
            $shift->forceFill([
                'professional_id' => $current->professional_id,
                'eligibility_version' => $eligibility?->eligibilityVersion,
                'qualification_id' => $eligibility?->qualificationId,
                'announced_at' => null,
            ])->save();

            $decision = ['decided_at' => now(), 'decided_by_user_id' => Auth::id()];
            // The previous winner (if the responsible came from an earlier selection) leaves the single "selected" slot.
            $shift->applications()->where('status', ApplicationStatus::Selected)->update(['status' => ApplicationStatus::Replaced]);
            $current->forceFill([...$decision, 'status' => ApplicationStatus::Selected, 'decision_reason' => $reason])->save();
            $shift->applications()->where('status', ApplicationStatus::Active)->update([...$decision, 'status' => ApplicationStatus::NotSelected]);

            AuditEvent::record('shift.assigned', $shift->tenant_id, $shift, [
                'professional_id' => $current->professional_id,
                'replaced_professional_id' => $responsible,
                'application_id' => $current->id,
                'reason' => $reason,
                'eligibility_version' => $shift->eligibility_version,
                'qualification_id' => $shift->qualification_id,
            ]);
        });
    }

    /**
     * @return array{sector_id: int, job_function_id: int, professional_id: int, starts_at: CarbonInterface, ends_at: CarbonInterface}
     */
    private function assignmentOf(int $professionalId): array
    {
        return [
            'sector_id' => $this->sector_id,
            'job_function_id' => $this->job_function_id,
            'professional_id' => $professionalId,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
        ];
    }

    /**
     * The responsible announces their own published shift for replacement (step 6c). They stay responsible
     * until a coordinator selects a candidate.
     */
    public function announce(Professional $professional): void
    {
        self::writing(function () use ($professional) {
            self::lockProfessionals([$professional->id]);
            $shift = $this->lockedIn(ShiftStatus::Published);

            if ($shift->professional_id !== $professional->id) {
                throw ValidationException::withMessages(['shift' => 'Só o responsável pode anunciar este plantão.']);
            }

            if ($shift->starts_at <= now()) {
                throw ValidationException::withMessages(['shift' => 'O plantão já começou e não pode ser anunciado.']);
            }

            if ($shift->announced_at !== null) {
                throw ValidationException::withMessages(['shift' => 'Este plantão já está anunciado.']);
            }

            if ($shift->hasCheckIn()) {
                throw ValidationException::withMessages(['shift' => 'A entrada já foi registrada: o plantão não pode ser anunciado.']);
            }

            if (ShiftRequest::pendingFor($shift->id)->exists()) {
                throw ValidationException::withMessages(['shift' => 'Há uma solicitação pendente para este plantão.']);
            }

            $shift->forceFill(['announced_at' => now()])->save();
            AuditEvent::record('shift.announced', $shift->tenant_id, $shift, ['professional_id' => $professional->id]);
        });
    }

    /**
     * Withdrawing the announcement closes the pending applications; the responsible never changed.
     */
    public function cancelAnnouncement(Professional $professional): void
    {
        self::writing(function () use ($professional) {
            self::lockProfessionals([$professional->id]);
            $shift = $this->lockedIn(ShiftStatus::Published);

            if ($shift->professional_id !== $professional->id || $shift->announced_at === null) {
                throw ValidationException::withMessages(['shift' => 'Este plantão não está anunciado por você.']);
            }

            $shift->forceFill(['announced_at' => null])->save();
            $shift->applications()->where('status', ApplicationStatus::Active)
                ->update(['status' => ApplicationStatus::NotSelected, 'decided_at' => now(), 'decided_by_user_id' => Auth::id()]);
            AuditEvent::record('shift.announcement_cancelled', $shift->tenant_id, $shift, ['professional_id' => $professional->id]);
        });
    }

    /**
     * Published and either without a professional or announced by its responsible.
     */
    private function lockedVacancy(): self
    {
        $shift = $this->lockedIn(ShiftStatus::Published);

        if ($shift->professional_id !== null && $shift->announced_at === null) {
            throw ValidationException::withMessages(['shift' => 'Esta vaga já foi preenchida.']);
        }

        return $shift;
    }

    private static function belongsToActor(int $professionalId): bool
    {
        $membershipId = Professional::query()->whereKey($professionalId)->value('membership_id');

        return $membershipId !== null && Membership::query()->whereKey($membershipId)->value('user_id') === Auth::id();
    }

    /**
     * @param  array{sector_id: int, job_function_id: int, professional_id: int|null, starts_at: CarbonInterface, ends_at: CarbonInterface, value_cents?: int|null}  $data
     */
    private static function assertAssignable(array $data, ?string $referenceUf = null): ?Eligibility
    {
        if ($data['starts_at'] >= $data['ends_at']) {
            throw ValidationException::withMessages(['ends_at' => 'O fim precisa ser posterior ao início.']);
        }

        // Conservative default: no scheduling or assignment after the start (PRODUCT.md, limite temporal).
        if ($data['starts_at'] <= now()) {
            throw ValidationException::withMessages(['starts_at' => 'O plantão já começou: não é possível montar ou publicar.']);
        }

        if ($data['professional_id'] === null) {
            return null;
        }

        $eligibility = Eligibility::check(
            Professional::query()->whereKey($data['professional_id'])->firstOrFail(),
            Sector::query()->whereKey($data['sector_id'])->firstOrFail(),
            JobFunction::query()->whereKey($data['job_function_id'])->firstOrFail(),
            $data['starts_at'],
            $data['ends_at'],
            $referenceUf,
        );

        if (! $eligibility->eligible()) {
            throw ValidationException::withMessages([
                'professional_id' => array_map(fn ($reason) => $reason->message(), $eligibility->reasons),
            ]);
        }

        return $eligibility;
    }

    /**
     * Locks professionals in id order so two writers never wait on each other in opposite order.
     *
     * @param  list<int|null>  $ids
     */
    private static function lockProfessionals(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));
        sort($ids);

        if ($ids !== []) {
            Professional::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /**
     * Locks the shift's current professional (and $also) before the shift, then checks that a concurrent
     * edit did not swap the professional in between: otherwise the new one would be decided unlocked.
     */
    private function lockedWithProfessionals(ShiftStatus $expected, ?int $also = null): self
    {
        $current = self::query()->whereKey($this->id)->value('professional_id');
        self::lockProfessionals([$current === null ? null : (int) $current, $also]);
        $shift = $this->lockedIn($expected);

        if ($shift->professional_id !== ($current === null ? null : (int) $current)) {
            throw ValidationException::withMessages(['shift' => 'O plantão mudou enquanto você editava. Recarregue a página.']);
        }

        return $shift;
    }

    private function lockedIn(ShiftStatus $expected): self
    {
        $shift = self::query()->lockForUpdate()->whereKey($this->id)->firstOrFail();

        if ($shift->status !== $expected) {
            throw ValidationException::withMessages(['shift' => 'O plantão mudou de situação. Recarregue a página.']);
        }

        return $shift;
    }

    /**
     * Runs a write transaction and turns the overlap constraint into a field error.
     *
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    private static function writing(\Closure $write): mixed
    {
        try {
            // Retries only on deadlocks/serialization failures; other errors propagate at once.
            return DB::transaction($write, 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23P01') {
                throw ValidationException::withMessages(['professional_id' => 'O profissional já tem outro plantão nesse horário.']);
            }

            throw $exception;
        }
    }
}
