<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Professional;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Patient allergies (step 4b.1c). Recorded and inactivated by whoever writes on the record (the open visit
 * or the admission, ADR-0004); read wherever the record is read. The audit keeps ids only, never the substance.
 */
class PatientAllergyController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $professional = self::writer($request, $patient);
        $data = $request->validate([
            'substance' => ['required', 'string', 'max:255'],
            'severity' => ['required', Rule::in(array_keys(PatientAllergy::SEVERITIES))],
            'reaction' => ['nullable', 'string', 'max:255'],
        ], [], ['substance' => 'substância', 'severity' => 'gravidade', 'reaction' => 'reação']);
        $substance = Medication::clean($data['substance']);
        if ($substance === '') {
            throw ValidationException::withMessages(['substance' => 'Informe a substância.']);
        }

        $key = Medication::matchKey($substance);
        // Across a merged record too: the index only sees one registration.
        // ponytail: checked outside a lock; two simultaneous records on two merged registrations can both pass, both stay visible.
        if (PatientAllergy::query()->whereIn('patient_id', ClinicalAccess::recordIds($patient))->where('substance_key', $key)->whereNull('inactivated_at')->exists()) {
            throw ValidationException::withMessages(['substance' => 'Esta alergia já está registrada.']);
        }
        try {
            // In its own transaction: a duplicate rolls back only this insert.
            $allergy = DB::transaction(fn () => PatientAllergy::query()->create([
                'patient_id' => $patient->merged_into_id ?? $patient->id, 'substance' => $substance, 'substance_key' => $key,
                'severity' => $data['severity'], 'reaction' => isset($data['reaction']) ? (Medication::clean($data['reaction']) ?: null) : null,
                'recorded_by_user_id' => $request->user()->id, 'professional_id' => $professional->id, 'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['substance' => 'Esta alergia já está registrada.']);
        }
        AuditEvent::record('patient_allergy.recorded', $allergy->tenant_id, $allergy);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alergia registrada.']);

        return back();
    }

    public function inactivate(Request $request, PatientAllergy $allergy): RedirectResponse
    {
        $patient = Patient::query()->findOrFail($allergy->patient_id);
        self::writer($request, $patient);
        // Step 4b.6: an allergy turns prescription alerts off when inactivated: only who recorded it or a prescriber.
        abort_unless(self::mayInactivate($request->user(), $patient, $allergy->recorded_by_user_id), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motivo']);

        DB::transaction(function () use ($allergy, $request, $data) {
            $locked = PatientAllergy::query()->whereKey($allergy->id)->lockForUpdate()->firstOrFail();
            if ($locked->inactivated_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Esta alergia já foi inativada.']);
            }
            $locked->forceFill(['inactivated_at' => now(), 'inactivated_by_user_id' => $request->user()->id, 'inactivation_reason' => $data['reason']])->save();
            AuditEvent::record('patient_allergy.inactivated', $locked->tenant_id, $locked);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Alergia inativada. O registro fica no histórico.']);

        return back();
    }

    /**
     * The allergies of a record, active first. Callers must have checked ClinicalAccess.
     *
     * @param  list<int>  $patientIds
     * @return list<array<string, mixed>>
     */
    public static function listing(array $patientIds, string $timezone): array
    {
        $when = fn (?CarbonInterface $at) => $at?->setTimezone($timezone)->format('d/m/Y H:i');

        return array_values(PatientAllergy::query()->whereIn('patient_id', $patientIds)->with('professional:id,name')
            ->orderByRaw('inactivated_at IS NOT NULL')->latest('created_at')->latest('id')->get()
            ->map(fn (PatientAllergy $allergy) => [
                'id' => $allergy->id,
                'substance' => $allergy->substance,
                'severity' => PatientAllergy::SEVERITIES[$allergy->severity],
                'reaction' => $allergy->reaction,
                'recordedAt' => $when($allergy->created_at),
                'author' => $allergy->professional?->name,
                'recordedBy' => $allergy->recorded_by_user_id,
                'inactivatedAt' => $when($allergy->inactivated_at),
                'inactivationReason' => $allergy->inactivation_reason,
            ])->all());
    }

    /**
     * What the record form needs: the patient and the catalog's active ingredients to pick from.
     *
     * @return array{patientId: int, userId: int, inactivateAny: bool, substances: list<string>, severities: array<string, string>}
     */
    public static function form(Patient $patient, User $user): array
    {
        return [
            'patientId' => $patient->merged_into_id ?? $patient->id,
            'userId' => $user->id,
            'inactivateAny' => self::mayInactivate($user, $patient, null),
            // ponytail: the whole catalog in a <datalist>; search on the server if it grows past a few thousand.
            'substances' => array_values(Medication::query()->where('active', true)->distinct()->orderBy('active_ingredient')->pluck('active_ingredient')->all()),
            'severities' => PatientAllergy::SEVERITIES,
        ];
    }

    /**
     * Who recorded the allergy, or a prescriber in the unit of the user's open visit or admission of the patient.
     */
    private static function mayInactivate(User $user, Patient $patient, ?int $recordedBy): bool
    {
        $basis = ClinicalAccess::activeVisit($user, $patient) ?? ClinicalAccess::activeAdmission($user, $patient);

        return $recordedBy === $user->id || ($basis !== null && PrescriptionController::prescriber($user, $basis->unit_id) !== null);
    }

    private static function writer(Request $request, Patient $patient): Professional
    {
        $professional = ClinicalAccess::professional($request->user()) ?? abort(403);
        abort_if(ClinicalAccess::activeVisit($request->user(), $patient) === null && ClinicalAccess::activeAdmission($request->user(), $patient) === null, 403);

        return $professional;
    }
}
