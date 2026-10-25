<?php

namespace App\Clinical;

use App\Http\Controllers\SurgeryScheduleController;
use App\Models\Admission;
use App\Models\EmergencyAccess;
use App\Models\ExamOrder;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Professional;
use App\Models\RoleAssignment;
use App\Models\Surgery;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use App\Tenancy\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single place that decides who reads a patient's record (ADR-0003): a professional with an open visit
 * of the patient in any unit of the network, the responsible doctor or the ward team of an active admission
 * (ADR-0004, step 4.4b), the exam team while an order of its type is open in its unit (ADR-0008, step 5.3),
 * or a valid emergency grant (read-only, step 3.6b). Managers, admins
 * and the reception never read it for their role. Every read is written to the insert-only trail.
 */
final class ClinicalAccess
{
    /**
     * The professional behind the signed-in account, if the account is linked to one.
     */
    public static function professional(User $user): ?Professional
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership === null || $membership->revoked_at !== null ? null : Professional::query()->where('membership_id', $membership->id)->first();
    }

    /**
     * The patient and every registration merged into the same primary: one record (step 3.3).
     *
     * @return list<int>
     */
    public static function recordIds(Patient $patient): array
    {
        $primary = $patient->merged_into_id ?? $patient->id;

        return array_values([$primary, ...array_map('intval', Patient::query()->where('merged_into_id', $primary)->pluck('id')->all())]);
    }

    /**
     * The open visit that lets this user read the patient's record, if any.
     */
    public static function activeVisit(User $user, Patient $patient): ?Visit
    {
        $professional = self::professional($user);

        return $professional === null ? null : Visit::query()->where('professional_id', $professional->id)->whereNull('closed_at')
            ->whereIn('patient_id', self::recordIds($patient))->orderBy('id')->first();
    }

    /**
     * A valid emergency grant of this user for the patient (step 3.6b): read-only, 4 hours.
     */
    public static function activeEmergency(User $user, Patient $patient): ?EmergencyAccess
    {
        return EmergencyAccess::query()->where('user_id', $user->id)->whereIn('patient_id', self::recordIds($patient))
            ->where('expires_at', '>', now())->latest('expires_at')->first();
    }

    /**
     * Step 4.4b (ADR-0004): the active admission that lets this user read and write the patient's record.
     * The responsible doctor, from the request on; the team of the bed's sector (approved qualification
     * valid today for that sector) while the patient is admitted there. Evaluated at every read.
     */
    public static function activeAdmission(User $user, Patient $patient): ?Admission
    {
        $professional = self::professional($user);
        if ($professional === null) {
            return null;
        }
        $admissions = Admission::query()->whereIn('patient_id', self::recordIds($patient))->whereIn('status', Admission::ACTIVE)
            ->with('bed:id,sector_id')->orderBy('id')->get();

        return $admissions->first(fn (Admission $admission) => $admission->responsible_professional_id === $professional->id
            || ($admission->status === 'admitted' && in_array($admission->bed?->sector_id, self::wardSectorIds($professional, $admission->unit_id), true)));
    }

    /**
     * Sectors of a unit where the professional is on the ward team: approved qualifications valid today,
     * dates local to the unit (as in Eligibility).
     *
     * @return list<int>
     */
    public static function wardSectorIds(Professional $professional, int $unitId): array
    {
        $today = now(Unit::query()->whereKey($unitId)->value('timezone') ?? 'UTC')->toDateString();

        return array_values(array_unique(array_map('intval', DB::table('qualification_sector')
            ->join('qualifications', 'qualifications.id', '=', 'qualification_sector.qualification_id')
            ->where('qualifications.professional_id', $professional->id)->where('qualifications.unit_id', $unitId)
            ->where('qualifications.status', QualificationStatus::Approved->value)
            ->whereDate('qualifications.valid_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('qualifications.valid_until')->orWhereDate('qualifications.valid_until', '>=', $today))
            ->pluck('qualification_sector.sector_id')->all())));
    }

    /**
     * ADR-0008: the units where the membership holds the Laboratory or the Imaging role, by exam type.
     *
     * @return array<string, list<int>>
     */
    public static function examScopes(?Membership $membership): array
    {
        if ($membership === null || $membership->revoked_at !== null) {
            return [];
        }
        $scopes = [];
        foreach ($membership->roleAssignments as $assignment) {
            /** @var RoleAssignment $assignment */
            $type = match ($assignment->role) {
                Role::Laboratory => 'laboratory',
                Role::Imaging => 'imaging',
                default => null,
            };
            if ($type !== null && $assignment->unit_id !== null) {
                $scopes[$type][] = (int) $assignment->unit_id;
            }
        }

        return array_map(fn (array $units) => array_values(array_unique($units)), $scopes);
    }

    /**
     * ADR-0008: the orders still open with an item of a type the user executes, in a unit where they execute it.
     * Open means requested, not cancelled, and an item of that type still without a released result (step 5.4).
     *
     * @return Builder<ExamOrder>
     */
    public static function openExamOrders(User $user): Builder
    {
        $scopes = self::examScopes(UnitPolicy::currentMembership($user));

        return ExamOrder::query()->where('status', 'requested')->where(function (Builder $query) use ($scopes) {
            $query->whereRaw('false');
            foreach ($scopes as $type => $units) {
                $query->orWhere(fn (Builder $scope) => $scope->whereIn('unit_id', $units)->whereHas('items', fn (Builder $items) => $items->where('exam_type', $type)->whereDoesntHave('results')));
            }
        });
    }

    /**
     * The open order that lets this exam team member read the patient's record, if any (ADR-0008).
     */
    public static function activeExamOrder(User $user, Patient $patient): ?ExamOrder
    {
        return self::openExamOrders($user)->whereIn('patient_id', self::recordIds($patient))->orderBy('id')->first();
    }

    /**
     * ADR-0009: the surgeries still open (requested, scheduled or in progress) whose record the user reads: as the Surgical center
     * of the unit, or as a member of the active team qualified in the unit. A request still unscheduled counts only
     * until REQUESTED_ACCESS_DAYS after its wished date.
     *
     * @return Builder<Surgery>
     */
    public static function openSurgeries(User $user): Builder
    {
        $membership = UnitPolicy::currentMembership($user);
        $units = SurgeryScheduleController::unitIds($membership);
        $professional = self::professional($user);

        $today = now()->toDateString();

        return Surgery::query()
            // A request nobody schedules does not open the record for ever (security review 5b.3b).
            ->where(fn (Builder $open) => $open->whereIn('status', Surgery::ACTIVE)
                ->orWhere(fn (Builder $requested) => $requested->where('status', 'requested')->where('desired_date', '>=', now()->subDays(Surgery::REQUESTED_ACCESS_DAYS)->toDateString())))
            ->where(function (Builder $query) use ($units, $professional, $today) {
                $query->whereIn('unit_id', $units);
                if ($professional !== null) {
                    // On the active team and still qualified in the surgery's unit.
                    $query->orWhere(fn (Builder $team) => $team->whereHas('team', fn (Builder $members) => $members->where('professional_id', $professional->id))
                        ->whereExists(fn ($qualification) => $qualification->selectRaw('1')->from('qualifications')
                            ->where('qualifications.professional_id', $professional->id)->whereColumn('qualifications.unit_id', 'surgeries.unit_id')
                            ->where('qualifications.status', QualificationStatus::Approved->value)->whereDate('qualifications.valid_from', '<=', $today)
                            ->where(fn ($valid) => $valid->whereNull('qualifications.valid_until')->orWhereDate('qualifications.valid_until', '>=', $today))));
                }
            });
    }

    /**
     * The open surgery that lets this user read the patient's record, if any (ADR-0009).
     */
    public static function activeSurgery(User $user, Patient $patient): ?Surgery
    {
        return self::openSurgeries($user)->whereIn('patient_id', self::recordIds($patient))->orderBy('id')->first();
    }

    public static function recordRead(User $user, Patient $patient, Visit|EmergencyAccess|Admission|Prescription|ExamOrder|Surgery $basis): void
    {
        DB::table('clinical_reads')->insert([
            'tenant_id' => $patient->tenant_id, 'user_id' => $user->id, 'patient_id' => $patient->merged_into_id ?? $patient->id,
            'reason' => match (true) {
                $basis instanceof Visit => 'visit',
                $basis instanceof Admission => 'admission',
                // ADR-0005: the pharmacy reads one prescription.
                $basis instanceof Prescription => 'pharmacy',
                // ADR-0008: the exam team, while the order is open.
                $basis instanceof ExamOrder => 'exam',
                // ADR-0009: the surgical center and the scheduled team, while the surgery is open.
                $basis instanceof Surgery => 'surgery',
                default => 'emergency',
            },
            'visit_id' => $basis instanceof Visit ? $basis->id : null,
            'emergency_access_id' => $basis instanceof EmergencyAccess ? $basis->id : null,
            'admission_id' => $basis instanceof Admission ? $basis->id : null,
            'prescription_id' => $basis instanceof Prescription ? $basis->id : null,
            'exam_order_id' => $basis instanceof ExamOrder ? $basis->id : null,
            'surgery_id' => $basis instanceof Surgery ? $basis->id : null,
            'read_at' => now(),
        ]);
    }
}
