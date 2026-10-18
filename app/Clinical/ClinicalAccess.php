<?php

namespace App\Clinical;

use App\Models\Patient;
use App\Models\Professional;
use App\Models\User;
use App\Models\Visit;
use App\Policies\UnitPolicy;
use Illuminate\Support\Facades\DB;

/**
 * The single place that decides who reads a patient's record (ADR-0003): a professional with an open visit
 * of the patient in any unit of the network. Emergency access arrives in step 3.6b. Managers, admins and the
 * reception never read it for their role. Every read is written to the insert-only trail.
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

    public static function recordRead(User $user, Patient $patient, Visit $visit): void
    {
        DB::table('clinical_reads')->insert([
            'tenant_id' => $patient->tenant_id, 'user_id' => $user->id, 'patient_id' => $patient->merged_into_id ?? $patient->id,
            'reason' => 'visit', 'visit_id' => $visit->id, 'read_at' => now(),
        ]);
    }
}
