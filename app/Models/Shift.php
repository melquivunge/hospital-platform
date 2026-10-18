<?php

namespace App\Models;

use App\Professionals\Eligibility;
use App\Scheduling\ApplicationStatus;
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
            ])->save();

            AuditEvent::record('shift.cancelled', $shift->tenant_id, $shift, ['from' => $from->value, 'reason' => $reason]);
            $shift->applications()->where('status', ApplicationStatus::Active)
                ->update(['status' => ApplicationStatus::NotSelected, 'decided_at' => now(), 'decided_by_user_id' => Auth::id()]);
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
     * Application to an open vacancy (V1-07). Eligibility is checked now and again on selection.
     */
    public function apply(Professional $professional): ShiftApplication
    {
        return self::writing(function () use ($professional) {
            self::lockProfessionals([$professional->id]);
            $shift = $this->lockedOpenVacancy();

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
     * replaced automatically. Nobody selects themselves.
     */
    public function selectCandidate(ShiftApplication $application, string $reason): void
    {
        self::writing(function () use ($application, $reason) {
            self::lockProfessionals([$application->professional_id]);
            $shift = $this->lockedOpenVacancy();
            $current = $shift->applications()->lockForUpdate()->whereKey($application->id)->first();

            if ($current?->status !== ApplicationStatus::Active) {
                throw ValidationException::withMessages(['application' => 'Esta candidatura não está mais ativa. Recarregue a página.']);
            }

            $membershipId = Professional::query()->whereKey($current->professional_id)->value('membership_id');

            if ($membershipId !== null && Membership::query()->whereKey($membershipId)->value('user_id') === Auth::id()) {
                throw ValidationException::withMessages(['application' => 'Você não pode escolher a si mesmo. Outro coordenador precisa decidir.']);
            }

            $eligibility = self::assertAssignable($shift->assignmentOf($current->professional_id));
            $shift->forceFill([
                'professional_id' => $current->professional_id,
                'eligibility_version' => $eligibility?->eligibilityVersion,
                'qualification_id' => $eligibility?->qualificationId,
            ])->save();

            $decision = ['decided_at' => now(), 'decided_by_user_id' => Auth::id()];
            $current->forceFill([...$decision, 'status' => ApplicationStatus::Selected, 'decision_reason' => $reason])->save();
            $shift->applications()->where('status', ApplicationStatus::Active)->update([...$decision, 'status' => ApplicationStatus::NotSelected]);

            AuditEvent::record('shift.assigned', $shift->tenant_id, $shift, [
                'professional_id' => $current->professional_id,
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

    private function lockedOpenVacancy(): self
    {
        $shift = $this->lockedIn(ShiftStatus::Published);

        if ($shift->professional_id !== null) {
            throw ValidationException::withMessages(['shift' => 'Esta vaga já foi preenchida.']);
        }

        return $shift;
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
