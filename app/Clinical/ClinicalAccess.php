<?php

namespace App\Clinical;

use App\Models\Admission;
use App\Models\EmergencyAccess;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use Illuminate\Support\Facades\DB;

/**
 * The single place that decides who reads a patient's record (ADR-0003): a professional with an open visit
 * of the patient in any unit of the network, the responsible doctor or the ward team of an active admission
 * (ADR-0004, step 4.4b), or a valid emergency grant (read-only, step 3.6b). Managers, admins
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

    public static function recordRead(User $user, Patient $patient, Visit|EmergencyAccess|Admission $basis): void
    {
        DB::table('clinical_reads')->insert([
            'tenant_id' => $patient->tenant_id, 'user_id' => $user->id, 'patient_id' => $patient->merged_into_id ?? $patient->id,
            'reason' => match (true) {
                $basis instanceof Visit => 'visit',
                $basis instanceof Admission => 'admission',
                default => 'emergency',
            },
            'visit_id' => $basis instanceof Visit ? $basis->id : null,
            'emergency_access_id' => $basis instanceof EmergencyAccess ? $basis->id : null,
            'admission_id' => $basis instanceof Admission ? $basis->id : null,
            'read_at' => now(),
        ]);
    }
}
