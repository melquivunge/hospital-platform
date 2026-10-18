<?php

namespace App\Models;

use App\Professionals\Eligibility;
use App\Scheduling\ApplicationStatus;
use App\Scheduling\RequestStatus;
use App\Scheduling\ShiftStatus;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
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
            $shift->requests()->whereIn('status', RequestStatus::pending())
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

            if ($shift->requests()->whereIn('status', RequestStatus::pending())->exists()) {
                throw ValidationException::withMessages(['shift' => 'Já existe uma solicitação pendente para este plantão.']);
            }

            if ($recipient->membership_id === null || ! Membership::query()->whereKey($recipient->membership_id)->whereNull('revoked_at')->exists()) {
                throw ValidationException::withMessages(['recipient_id' => 'Este profissional ainda não tem acesso à plataforma para aceitar.']);
            }

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

    public function respondToTransfer(ShiftRequest $request, Professional $recipient, bool $accept): void
    {
        self::writing(function () use ($request, $recipient, $accept) {
            self::lockProfessionals([$recipient->id]);
            $shift = self::query()->lockForUpdate()->findOrFail($this->id);
            $current = $shift->requests()->lockForUpdate()->whereKey($request->id)->firstOrFail();

            if ($current->recipient_professional_id !== $recipient->id || $current->status !== RequestStatus::PendingRecipient) {
                throw ValidationException::withMessages(['request' => 'Esta solicitação não está mais aguardando sua resposta.']);
            }

            if ($accept) {
                self::assertAssignable($shift->assignmentOf($recipient->id));
            }

            $current->forceFill(['status' => $accept ? RequestStatus::PendingCoordinator : RequestStatus::Refused, 'recipient_decided_at' => now()])->save();
            AuditEvent::record($accept ? 'shift.transfer_accepted' : 'shift.transfer_refused', $shift->tenant_id, $shift, ['request_id' => $current->id]);
        });
    }

    public function cancelTransfer(ShiftRequest $request, Professional $requester): void
    {
        self::writing(function () use ($request, $requester) {
            self::lockProfessionals([$requester->id]);
            $shift = self::query()->lockForUpdate()->findOrFail($this->id);
            $current = $shift->requests()->lockForUpdate()->whereKey($request->id)->firstOrFail();

            if ($current->requester_professional_id !== $requester->id || ! in_array($current->status, RequestStatus::pending(), true)) {
                throw ValidationException::withMessages(['request' => 'Esta solicitação não pode mais ser cancelada.']);
            }

            $current->forceFill(['status' => RequestStatus::Cancelled, 'decided_at' => now(), 'decided_by_user_id' => Auth::id()])->save();
            AuditEvent::record('shift.transfer_cancelled', $shift->tenant_id, $shift, ['request_id' => $current->id]);
        });
    }

    /**
     * Coordinator's decision. Approval re-checks that the shift still belongs to the requester, the
     * recipient's eligibility and conflicts, then moves the shift. Participants never decide.
     */
    public function decideTransfer(ShiftRequest $request, bool $approve, ?string $reason): void
    {
        self::writing(function () use ($request, $approve, $reason) {
            self::lockProfessionals([$request->requester_professional_id, $request->recipient_professional_id]);
            $shift = self::query()->lockForUpdate()->findOrFail($this->id);
            $current = $shift->requests()->lockForUpdate()->whereKey($request->id)->firstOrFail();

            if ($current->status !== RequestStatus::PendingCoordinator) {
                throw ValidationException::withMessages(['request' => 'Esta solicitação não está mais aguardando a coordenação.']);
            }

            if (self::belongsToActor($current->requester_professional_id) || self::belongsToActor($current->recipient_professional_id)) {
                throw ValidationException::withMessages(['request' => 'Você participa desta transferência e não pode decidir. Outro coordenador precisa decidir.']);
            }

            $decision = ['decided_at' => now(), 'decided_by_user_id' => Auth::id(), 'decision_reason' => $reason];

            if (! $approve) {
                $current->forceFill([...$decision, 'status' => RequestStatus::Refused])->save();
                AuditEvent::record('shift.transfer_rejected', $shift->tenant_id, $shift, ['request_id' => $current->id, 'reason' => $reason]);

                return;
            }

            if ($shift->status !== ShiftStatus::Published || $shift->professional_id !== $current->requester_professional_id || $shift->announced_at !== null) {
                throw ValidationException::withMessages(['request' => 'O plantão mudou desde a solicitação. Recuse e peça uma nova solicitação.']);
            }

            $eligibility = self::assertAssignable($shift->assignmentOf($current->recipient_professional_id));
            $shift->forceFill([
                'professional_id' => $current->recipient_professional_id,
                'eligibility_version' => $eligibility?->eligibilityVersion,
                'qualification_id' => $eligibility?->qualificationId,
            ])->save();
            $current->forceFill([...$decision, 'status' => RequestStatus::Completed])->save();
            // A winner from an earlier vacancy round no longer holds the shift.
            $shift->applications()->where('status', ApplicationStatus::Selected)->update(['status' => ApplicationStatus::Replaced]);

            AuditEvent::record('shift.transferred', $shift->tenant_id, $shift, array_filter([
                'request_id' => $current->id,
                'from' => $current->requester_professional_id,
                'to' => $current->recipient_professional_id,
                'reason' => $reason,
                'eligibility_version' => $shift->eligibility_version,
            ], fn ($value) => $value !== null));
        });
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

            if ($shift->requests()->whereIn('status', RequestStatus::pending())->exists()) {
                throw ValidationException::withMessages(['shift' => 'Há uma transferência pendente para este plantão.']);
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
    private static function assertAssignable(array $data): ?Eligibility
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
