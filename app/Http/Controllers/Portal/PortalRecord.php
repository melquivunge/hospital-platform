<?php

namespace App\Http\Controllers\Portal;

use App\Clinical\ClinicalAccess;
use App\Models\Appointment;
use App\Models\ExamResult;
use App\Models\ExamResultValue;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;

/**
 * Step 7a.3 (ADR-0012): what the portal shows of one patient's record — signed results (the current version of
 * each exam) and the coming appointments. A merged record shows the kept one with what was merged into it.
 */
final class PortalRecord
{
    private const RESULTS = 30;

    private const APPOINTMENTS = 10;

    /**
     * The records the account reads: its own (after a merge, the kept one) and the minors linked now.
     *
     * @return list<int>
     */
    public static function readable(PatientAccount $account): array
    {
        return [$account->effectivePatientId(), ...PatientAccountLink::minorsOf($account)];
    }

    /**
     * The kept record and every record merged into it. One level is enough: Patient::merge refuses to merge into a
     * merged record or to merge one that others were merged into, so there are no chains.
     *
     * @return list<int>
     */
    public static function recordIds(int $keptId): array
    {
        return array_values(Patient::query()->whereKey($keptId)->orWhere('merged_into_id', $keptId)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * @return array{results: list<array<string, mixed>>, appointments: list<array<string, mixed>>}
     */
    public static function content(PatientAccount $account, int $keptId): array
    {
        $ids = self::recordIds($keptId);

        // The current version of each exam: a rectified result is replaced by its rectification.
        $results = ExamResult::query()->whereIn('patient_id', $ids)
            ->whereRaw('exam_results.version = (SELECT max(r.version) FROM exam_results r WHERE r.exam_order_item_id = exam_results.exam_order_item_id)')
            ->join('exam_order_items', 'exam_order_items.id', '=', 'exam_results.exam_order_item_id')
            ->with('values')
            ->orderByDesc('exam_results.released_at')->limit(self::RESULTS)
            ->get(['exam_results.*', 'exam_order_items.exam_name']);

        $appointments = Appointment::query()->whereIn('appointments.patient_id', $ids)->where('appointments.status', 'scheduled')->where('appointments.starts_at', '>=', now())
            ->join('units', 'units.id', '=', 'appointments.unit_id')->join('professionals', 'professionals.id', '=', 'appointments.professional_id')
            ->orderBy('appointments.starts_at')->limit(self::APPOINTMENTS)
            ->get(['appointments.id', 'appointments.starts_at', 'units.name as unit', 'units.timezone', 'professionals.name as professional']);

        // Recorded once the content is in hand: the trail shows what was really shown.
        ClinicalAccess::recordPortalRead($account, $keptId);

        return [
            'results' => array_values($results->map(fn (ExamResult $result) => [
                'id' => $result->id,
                'exam' => (string) $result->getAttribute('exam_name'),
                'releasedAt' => $result->released_at->toIso8601String(),
                'rectified' => $result->version > 1,
                'critical' => $result->critical,
                'report' => $result->report,
                'hasPdf' => $result->attachment_path !== null,
                'values' => $result->values->map(fn (ExamResultValue $value) => [
                    'name' => $value->analyte_name,
                    'value' => self::number($value->value_numeric) ?? $value->value_text,
                    'unit' => $value->unit,
                    'reference' => $value->reference_text ?? match (true) {
                        $value->reference_min !== null && $value->reference_max !== null => self::number($value->reference_min).' a '.self::number($value->reference_max),
                        $value->reference_min !== null => 'a partir de '.self::number($value->reference_min),
                        $value->reference_max !== null => 'até '.self::number($value->reference_max),
                        default => null,
                    },
                    'flag' => $value->flag,
                ])->values()->all(),
            ])->all()),
            'appointments' => array_values($appointments->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'startsAt' => $appointment->starts_at->toIso8601String(),
                'timezone' => $appointment->getAttribute('timezone') ?? config('app.timezone'),
                'unit' => $appointment->getAttribute('unit'),
                'professional' => $appointment->getAttribute('professional'),
            ])->all()),
        ];
    }

    /**
     * "12.5000" as the patient reads it: "12,5".
     */
    private static function number(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace('.', ',', str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value);
    }
}
