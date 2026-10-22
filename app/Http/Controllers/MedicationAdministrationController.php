<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\MedicationAdministration;
use App\Models\Prescription;
use App\Models\PrescriptionDispensing;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionReview;
use App\Models\Unit;
use App\Models\User;
use App\Professionals\QualificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Administration by the ward team (step 4b.4, ADR-0005). Only the team of the bed's sector of an admitted patient
 * records, and only for the active prescription (the newest signed, within its 24 hours), an item not suspended
 * and not refused by the pharmacy. Each scheduled dose is recorded once: administered (real time), refused by
 * the patient or not done, with a reason; an "if needed" item is administered respecting its minimum interval.
 * An item not dispensed is administered only as an exception with a reason. A controlled or high-alert
 * medication needs a second person of the same team, who confirms with their own password. Insert-only.
 */
class MedicationAdministrationController extends Controller
{
    /** How far back a late record may say it happened. */
    private const BACKDATE_HOURS = 12;

    public function dose(Request $request, int $dose): RedirectResponse
    {
        $row = DB::table('prescription_doses')->where('id', $dose)->first() ?? abort(404);

        return $this->record($request, PrescriptionItem::query()->findOrFail((int) data_get($row, 'prescription_item_id')), $row);
    }

    public function prn(Request $request, PrescriptionItem $item): RedirectResponse
    {
        abort_unless($item->prn, 404);

        return $this->record($request, $item, null);
    }

    /**
     * Whether the user is on the team of the bed's sector of this admitted patient (ADR-0004).
     */
    public static function onWardTeam(User $user, Admission $admission): bool
    {
        $professional = ClinicalAccess::professional($user);
        $sectorId = $admission->bed_id === null ? null : DB::table('beds')->where('id', $admission->bed_id)->value('sector_id');

        return $professional !== null && $admission->status === 'admitted' && $sectorId !== null
            && in_array((int) $sectorId, ClinicalAccess::wardSectorIds($professional, $admission->unit_id), true);
    }

    /**
     * The colleagues of the bed's sector team who may be the second person, the user apart.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function witnesses(Admission $admission, User $user): array
    {
        $sectorId = DB::table('beds')->where('id', $admission->bed_id)->value('sector_id');
        $today = now(Unit::query()->whereKey($admission->unit_id)->value('timezone') ?? 'UTC')->toDateString();

        // Same rule as ClinicalAccess::wardSectorIds, read the other way round.
        return array_values(DB::table('qualification_sector')
            ->join('qualifications', 'qualifications.id', '=', 'qualification_sector.qualification_id')
            ->join('professionals', 'professionals.id', '=', 'qualifications.professional_id')
            ->join('memberships', 'memberships.id', '=', 'professionals.membership_id')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('qualification_sector.sector_id', $sectorId)->where('qualifications.unit_id', $admission->unit_id)
            ->where('qualifications.status', QualificationStatus::Approved->value)
            ->whereDate('qualifications.valid_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('qualifications.valid_until')->orWhereDate('qualifications.valid_until', '>=', $today))
            ->whereNull('memberships.revoked_at')->whereNull('users.deactivated_at')->where('users.id', '<>', $user->id)
            ->distinct()->orderBy('users.name')->get(['users.id', 'users.name'])
            ->map(fn (object $row) => ['id' => (int) data_get($row, 'id'), 'name' => (string) data_get($row, 'name')])->all());
    }

    /**
     * Step 4b.4, for the "Internados" list: per admitted patient, the doses of the active prescription that are
     * late and the time of the next one. Counts and a time only, as the list logs no read.
     *
     * @param  Collection<int, Admission>  $admissions  with the unit loaded
     * @return array<int, array{late: int, next: string|null}>
     */
    public static function counts(Collection $admissions): array
    {
        $admitted = $admissions->where('status', 'admitted')->keyBy('id');
        // A newer signed prescription is always valid longer: the newest still valid is the active one.
        $active = Prescription::query()->whereIn('admission_id', $admitted->keys())->where('status', 'signed')->where('valid_until', '>', now())
            ->orderByDesc('signed_at')->orderByDesc('id')->get(['id', 'admission_id', 'unit_id', 'valid_until'])
            ->unique('admission_id')->filter(fn (Prescription $prescription) => ($prescription->valid_until?->isFuture() ?? false)
                // Step 4b.6: a prescription of another unit (before a transfer) is not this unit's to administer.
                && $prescription->unit_id === $admitted[$prescription->admission_id]->unit_id)->keyBy('id');
        $items = PrescriptionItem::query()->whereIn('prescription_id', $active->keys())->whereNull('suspended_at')
            ->whereDoesntHave('review', fn ($review) => $review->where('decision', 'refused'))->pluck('prescription_id', 'id');
        $late = now()->subMinutes(MedicationAdministration::LATE_MINUTES);
        $result = [];
        foreach (DB::table('prescription_doses')->whereIn('prescription_item_id', $items->keys())
            ->whereNotExists(fn ($record) => $record->from('medication_administrations')->whereColumn('medication_administrations.prescription_dose_id', 'prescription_doses.id'))
            ->orderBy('scheduled_at')->get(['prescription_item_id', 'scheduled_at']) as $dose) {
            $admission = $admitted->get($active->get($items->get((int) data_get($dose, 'prescription_item_id')))?->admission_id);
            $at = CarbonImmutable::parse((string) data_get($dose, 'scheduled_at'));
            $row = $result[$admission->id] ?? ['late' => 0, 'next' => null];
            if ($at->lt($late)) {
                $row['late']++;
            } else {
                $row['next'] ??= $at->setTimezone($admission->unit->timezone ?? 'UTC')->format('H:i');
            }
            $result[$admission->id] = $row;
        }

        return $result;
    }

    private function record(Request $request, PrescriptionItem $item, ?object $dose): RedirectResponse
    {
        $prescription = Prescription::query()->findOrFail($item->prescription_id);
        // Emergency visits have no ward team: their administration is not recorded here.
        abort_if($prescription->admission_id === null, 403);
        $admission = Admission::query()->findOrFail($prescription->admission_id);
        // Access first (checked again under the lock): no validation answers for a prescription that is not this team's.
        abort_unless(self::onWardTeam($request->user(), $admission) && PrescriptionController::active($prescription)?->id === $prescription->id, 403);
        $bag = $dose === null ? "prn{$item->id}" : 'dose'.data_get($dose, 'id');
        $data = $request->validateWithBag($bag, [
            'outcome' => $dose === null ? ['prohibited'] : ['required', Rule::in(array_keys(MedicationAdministration::OUTCOMES))],
            'occurred_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'reason' => ['nullable', 'string', 'max:255'],
            'witness_user_id' => ['nullable', 'integer'],
            'witness_password' => ['nullable', 'string'],
        ], [], ['outcome' => 'registro', 'occurred_at' => 'horário', 'reason' => 'motivo', 'witness_user_id' => 'segunda pessoa', 'witness_password' => 'senha da segunda pessoa']);
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message])->errorBag($bag);
        $outcome = $data['outcome'] ?? 'administered';
        $timezone = Unit::query()->whereKey($admission->unit_id)->value('timezone') ?? 'UTC';
        $now = CarbonImmutable::now();
        // Only an administration says when it happened; refusals and omissions are recorded as of now.
        $at = $outcome === 'administered' && ($data['occurred_at'] ?? null) !== null
            ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['occurred_at'], $timezone)?->utc() : $now;
        if ($at === null || $at->gt($now) || $at->lt($now->subHours(self::BACKDATE_HOURS))) {
            $fail('occurred_at', 'Informe um horário das últimas '.self::BACKDATE_HOURS.' horas, até agora.');
        }
        if ($outcome !== 'administered' && trim((string) ($data['reason'] ?? '')) === '') {
            $fail('reason', 'Escreva o motivo.');
        }

        // Before the locks: the second person's password (bcrypt is slow).
        $witness = null;
        if ($outcome === 'administered' && $item->needsDoubleCheck()) {
            $witness = $this->witness($request, $admission, $data, $item, $fail);
        }

        try {
            DB::transaction(function () use ($request, $item, $dose, $prescription, $admission, $data, $outcome, $at, $now, $witness, $fail) {
                // Lock order of the suspension and the discharge: the admission, then the item.
                $locked = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
                abort_unless(self::onWardTeam($request->user(), $locked) && PrescriptionController::active($prescription)?->id === $prescription->id, 403);
                // The second person, again under the lock: still on this team (a revoked or moved colleague is not).
                if ($witness !== null && ! in_array($witness->id, array_column(self::witnesses($locked, $request->user()), 'id'), true)) {
                    $fail('witness_user_id', 'A segunda pessoa não está mais na equipe do setor. Escolha outra.');
                }
                $lockedItem = PrescriptionItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                $refusal = match (true) {
                    $lockedItem->suspended_at !== null => 'Este item foi suspenso pelo médico.',
                    PrescriptionReview::query()->where('prescription_item_id', $lockedItem->id)->value('decision') === 'refused' => 'A farmácia recusou este item: fale com o médico.',
                    $at->lt($lockedItem->starts_at) => 'O horário informado é anterior ao início do item.',
                    default => null,
                };
                if ($refusal !== null) {
                    $fail('outcome', $refusal);
                }
                if ($dose !== null) {
                    $scheduled = CarbonImmutable::parse((string) data_get($dose, 'scheduled_at'));
                    if ($scheduled->gt($now->addMinutes(MedicationAdministration::EARLY_MINUTES))) {
                        $fail('outcome', 'Este horário ainda não chegou.');
                    }
                    // An administration happens around its time, never long before it.
                    if ($at->lt($scheduled->subMinutes(MedicationAdministration::EARLY_MINUTES))) {
                        $fail('occurred_at', 'O horário informado é muito anterior ao previsto.');
                    }
                    if (MedicationAdministration::query()->where('prescription_dose_id', data_get($dose, 'id'))->exists()) {
                        $fail('outcome', 'Este horário já foi registrado.');
                    }
                } else {
                    // "If needed": no two administrations closer than the item's minimum interval.
                    $hours = (int) $lockedItem->frequency_hours ?: intdiv(24, max(1, $lockedItem->dosesPerDay()));
                    if (MedicationAdministration::query()->where('prescription_item_id', $lockedItem->id)
                        ->where('occurred_at', '>', $at->subHours($hours))->where('occurred_at', '<', $at->addHours($hours))->exists()) {
                        $fail('occurred_at', "O intervalo mínimo deste item é de {$hours} horas.");
                    }
                }
                // Dispensed and not all of it returned: otherwise an exception.
                $exception = $outcome === 'administered' && ! PrescriptionDispensing::query()->where('prescription_item_id', $lockedItem->id)
                    ->where(fn ($kept) => $kept->whereNull('returned_quantity')->orWhereColumn('returned_quantity', '<', 'quantity'))->exists();
                if ($exception && trim((string) ($data['reason'] ?? '')) === '') {
                    $fail('reason', 'Item não dispensado pela farmácia: escreva o motivo da exceção.');
                }
                $record = MedicationAdministration::query()->create([
                    'prescription_item_id' => $lockedItem->id, 'prescription_dose_id' => $dose === null ? null : (int) data_get($dose, 'id'),
                    'outcome' => $outcome, 'occurred_at' => $at, 'reason' => $outcome === 'administered' && ! $exception ? null : trim((string) $data['reason']),
                    'exception' => $exception, 'witness_user_id' => $witness?->id, 'recorded_by_user_id' => $request->user()->id, 'created_at' => $now,
                ]);
                // Ids and flags only: the reason may be clinical.
                AuditEvent::record('medication.recorded', $record->tenant_id, $record, array_filter([
                    'outcome' => $outcome, 'prescription_item_id' => $lockedItem->id, 'prescription_dose_id' => $record->prescription_dose_id,
                    'prescription_id' => $prescription->id, 'admission_id' => $admission->id, 'exception' => $exception ?: null, 'witness_user_id' => $witness?->id,
                ], fn ($value) => $value !== null));
            });
        } catch (UniqueConstraintViolationException) {
            $fail('outcome', 'Este horário já foi registrado.');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => MedicationAdministration::OUTCOMES[$outcome].': registrado.']);

        return back();
    }

    /**
     * The second person: a colleague of the same team, other than the user, with their own password. Attempts are
     * limited per colleague, and a wrong password is audited against the item.
     *
     * @param  array<string, mixed>  $data
     */
    private function witness(Request $request, Admission $admission, array $data, PrescriptionItem $item, callable $fail): User
    {
        $id = (int) ($data['witness_user_id'] ?? 0);
        if ($id === 0 || ! in_array($id, array_column(self::witnesses($admission, $request->user()), 'id'), true)) {
            $fail('witness_user_id', 'Controlado ou alto alerta: escolha a segunda pessoa da equipe do setor.');
        }
        $witness = User::query()->findOrFail($id);
        // Per recorder and witness: a colleague cannot lock the witness out for everyone.
        $attempts = 'clinical-witness:'.$witness->id.':'.$request->user()->id;
        // And per witness, wider: one account cannot guess every colleague's password in parallel for long.
        $perWitness = 'clinical-witness:'.$witness->id;
        if (RateLimiter::tooManyAttempts($attempts, VisitController::SIGN_ATTEMPTS) || RateLimiter::tooManyAttempts($perWitness, VisitController::SIGN_ATTEMPTS * 3)) {
            $fail('witness_password', 'Muitas tentativas com a senha errada. Tente de novo em '.ceil(max(RateLimiter::availableIn($attempts), RateLimiter::availableIn($perWitness)) / 60).' minutos.');
        }
        if (! Hash::check((string) ($data['witness_password'] ?? ''), $witness->getAuthPassword())) {
            RateLimiter::hit($attempts, VisitController::SIGN_LOCKOUT_SECONDS);
            RateLimiter::hit($perWitness, VisitController::SIGN_LOCKOUT_SECONDS);
            AuditEvent::record('medication.witness_failed', $item->tenant_id, $item, ['witness_user_id' => $witness->id]);
            $fail('witness_password', 'Senha da segunda pessoa incorreta.');
        }
        RateLimiter::clear($attempts);

        return $witness;
    }
}
