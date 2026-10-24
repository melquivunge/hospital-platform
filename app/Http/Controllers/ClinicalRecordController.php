<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Clinical\PatientIdentity;
use App\Models\AuditEvent;
use App\Models\EmergencyAccess;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use App\Tenancy\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Emergency access to a record and the access trail (step 3.6b). The grant is justified, read-only and
 * valid for 4 hours; the unit manager is notified and the auditor sees every read. The trail shows who,
 * when and why, never clinical content.
 */
class ClinicalRecordController extends Controller
{
    private const LOG_LIMIT = 200;

    /**
     * The record outside a visit: the open visit if there is one, then the active admission (step 4.4b),
     * the emergency view while a grant is valid, otherwise the request form.
     */
    public function show(Request $request, Patient $patient): Response|RedirectResponse
    {
        self::professional($request);
        if ($patient->merged_into_id !== null) {
            return to_route('clinical-record.show', $patient->merged_into_id);
        }
        if (($visit = ClinicalAccess::activeVisit($request->user(), $patient)) !== null) {
            return to_route('visits.show', $visit);
        }
        // Step 4.4b: the responsible doctor and the ward team read and write on the admission page.
        if (($admission = ClinicalAccess::activeAdmission($request->user(), $patient)) !== null) {
            return to_route('ward.show', $admission);
        }
        $grant = ClinicalAccess::activeEmergency($request->user(), $patient);
        if ($grant === null) {
            return to_route('clinical-record.emergency', $patient);
        }
        ClinicalAccess::recordRead($request->user(), $patient, $grant);
        Inertia::encryptHistory();
        $timezone = $grant->unit()->value('timezone') ?? 'UTC';

        return Inertia::render('clinical/record', [
            'patient' => VisitController::patientHeader($patient),
            'allergies' => PatientAllergyController::listing(ClinicalAccess::recordIds($patient), $timezone),
            'expiresAt' => $grant->expires_at->setTimezone($timezone)->format('d/m/Y H:i'),
            'record' => VisitController::signedRecord(ClinicalAccess::recordIds($patient), $timezone),
            'documents' => ClinicalDocumentController::listing(ClinicalAccess::recordIds($patient), $timezone, $request->user()->id),
            // Step 5.4: the released exam results of the record.
            'examResults' => ExamResultController::listing(ClinicalAccess::recordIds($patient), $timezone),
        ]);
    }

    public function create(Request $request, Patient $patient): Response|RedirectResponse
    {
        $professional = self::professional($request);
        if ($patient->merged_into_id !== null) {
            return to_route('clinical-record.emergency', $patient->merged_into_id);
        }

        return Inertia::render('clinical/emergency', [
            'patient' => ['id' => $patient->id, 'name' => $patient->social_name ?? $patient->name],
            'units' => self::units($professional)->map->only('id', 'name')->values(),
            'hours' => EmergencyAccess::HOURS,
        ]);
    }

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $professional = self::professional($request);
        abort_if($patient->merged_into_id !== null, 404);
        $data = $request->validate([
            'unit_id' => ['required', 'integer', Rule::in(self::units($professional)->modelKeys())],
            'justification' => ['required', 'string', 'min:20', 'max:2000'],
        ], [], ['unit_id' => 'unidade', 'justification' => 'justificativa']);

        // No duplicate grants (and no flood of notifications to the manager): an open visit or a valid grant
        // already opens the record.
        if (ClinicalAccess::activeVisit($request->user(), $patient) !== null || ClinicalAccess::activeAdmission($request->user(), $patient) !== null
            || ClinicalAccess::activeEmergency($request->user(), $patient) !== null) {
            return to_route('clinical-record.show', $patient);
        }
        DB::transaction(function () use ($request, $professional, $patient, $data) {
            $now = now();
            $grant = EmergencyAccess::query()->create([
                'user_id' => $request->user()->id, 'professional_id' => $professional->id, 'patient_id' => $patient->id, 'unit_id' => $data['unit_id'],
                'justification' => trim($data['justification']), 'granted_at' => $now, 'expires_at' => $now->copy()->addHours(EmergencyAccess::HOURS),
            ]);
            // The unit manager hears of it through the notification derived from this event (no patient data in it).
            AuditEvent::record('clinical.emergency_access', $grant->tenant_id, $grant, ['unit_id' => $grant->unit_id, 'patient_id' => $patient->id]);
        });
        Inertia::flash('toast', ['type' => 'warning', 'message' => 'Acesso de emergência liberado por '.EmergencyAccess::HOURS.' horas. A gestão da unidade foi avisada.']);

        return to_route('clinical-record.show', $patient);
    }

    /**
     * Management sees the emergency grants of its units (network admins: the network); the auditor sees
     * them all and every read of a record.
     */
    public function accessLog(Request $request): Response
    {
        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);
        $auditor = $membership->hasRole(Role::Auditor);
        $network = $auditor || $membership->hasRole(Role::NetworkAdmin);
        $managed = Professional::managedUnitIds($membership);
        abort_unless($network || $managed !== [], 403);
        Inertia::encryptHistory();
        $since = now()->subDays(30);
        $label = fn (?string $name, ?string $socialName, ?string $cpf) => trim(($socialName ?? $name).' '.(PatientIdentity::maskCpf($cpf) ?? ''));

        // ponytail: last 30 days, LOG_LIMIT rows each (the page says when it is cut); add period filters and paging when volumes need them.
        return Inertia::render('clinical/access-log', [
            'limit' => self::LOG_LIMIT,
            'emergencies' => EmergencyAccess::query()->where('granted_at', '>=', $since)->when(! $network, fn ($query) => $query->whereIn('unit_id', $managed))
                ->with(['professional:id,name', 'patient:id,name,social_name,cpf', 'unit:id,name,timezone'])->latest('granted_at')->limit(self::LOG_LIMIT)->get()
                ->map(fn (EmergencyAccess $grant) => [
                    'id' => $grant->id,
                    'grantedAt' => $grant->granted_at->setTimezone($grant->unit->timezone ?? 'UTC')->format('d/m/Y H:i'),
                    'expiresAt' => $grant->expires_at->setTimezone($grant->unit->timezone ?? 'UTC')->format('d/m/Y H:i'),
                    'professional' => $grant->professional?->name,
                    'patient' => $label($grant->patient?->name, $grant->patient?->social_name, $grant->patient?->cpf),
                    'unit' => $grant->unit?->name,
                    'justification' => $grant->justification,
                ]),
            'reads' => ! $auditor ? null : DB::table('clinical_reads')->where('clinical_reads.tenant_id', $membership->tenant_id)->where('clinical_reads.read_at', '>=', $since)
                ->join('users', 'users.id', '=', 'clinical_reads.user_id')->join('patients', 'patients.id', '=', 'clinical_reads.patient_id')
                ->orderByDesc('clinical_reads.read_at')->limit(self::LOG_LIMIT)
                ->get(['clinical_reads.id', 'clinical_reads.read_at', 'clinical_reads.reason', 'users.name as user', 'patients.name', 'patients.social_name', 'patients.cpf'])
                ->map(fn (object $read) => [
                    'id' => $read->id,
                    'readAt' => CarbonImmutable::parse($read->read_at)->toIso8601String(),
                    'reason' => $read->reason,
                    'user' => $read->user,
                    'patient' => $label($read->name, $read->social_name, $read->cpf),
                ]),
        ]);
    }

    private static function professional(Request $request): Professional
    {
        return ClinicalAccess::professional($request->user()) ?? abort(403);
    }

    /**
     * Units where the professional works: approved qualifications and the base unit.
     *
     * @return Collection<int, Unit>
     */
    private static function units(Professional $professional): Collection
    {
        $ids = Qualification::query()->where('professional_id', $professional->id)->where('status', QualificationStatus::Approved)->pluck('unit_id')->all();

        return Unit::query()->whereKey([...$ids, $professional->base_unit_id])->orderBy('name')->get(['id', 'name']);
    }
}
