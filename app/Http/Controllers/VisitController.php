<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\ClinicalNote;
use App\Models\EmergencyEpisode;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visits and clinical notes (step 3.6a). Only the professional of the visit acts, and only while it is
 * open; reading the record goes through ClinicalAccess and is logged. Note bodies never reach the audit
 * trail, notifications or URLs.
 */
class VisitController extends Controller
{
    private const BODY = ['required', 'string', 'max:20000'];

    public const SIGN_ATTEMPTS = 5;

    public const SIGN_LOCKOUT_SECONDS = 900;

    /**
     * Patients who arrived for me and visits I have open.
     */
    public function index(Request $request): Response
    {
        $professional = self::professional($request);
        Inertia::encryptHistory();

        return Inertia::render('visits/index', [
            'appointments' => Appointment::query()->where('professional_id', $professional->id)->whereIn('status', ['arrived', 'in_progress'])
                ->with(['patient:id,name,social_name', 'unit:id,name,timezone', 'visit:id,appointment_id'])->orderBy('starts_at')->get()
                ->map(fn (Appointment $appointment) => [
                    'id' => $appointment->id,
                    'start' => $appointment->starts_at->setTimezone($appointment->unit->timezone ?? 'UTC')->format('d/m H:i'),
                    'unit' => $appointment->unit?->name,
                    'patient' => $appointment->patient->social_name ?? $appointment->patient->name ?? null,
                    'status' => $appointment->status,
                    'walkIn' => $appointment->walk_in,
                    'visitId' => $appointment->visit?->id,
                ]),
            // Step 4.3: emergency visits in progress; new ones are called from the emergency queue.
            'emergencies' => Visit::query()->where('professional_id', $professional->id)->whereNotNull('emergency_episode_id')->whereNull('closed_at')
                ->with(['patient:id,name,social_name', 'unit:id,name,timezone'])->orderBy('opened_at')->get()
                ->map(fn (Visit $visit) => [
                    'id' => $visit->id,
                    'start' => $visit->opened_at->setTimezone($visit->unit->timezone ?? 'UTC')->format('d/m H:i'),
                    'unit' => $visit->unit?->name,
                    'patient' => $visit->patient->social_name ?? $visit->patient->name ?? null,
                ]),
        ]);
    }

    /**
     * Two clicks or two tabs never open two visits: the appointment row is locked and a second attempt
     * lands on the visit already open (delivery 3 contract).
     */
    public function open(Request $request, Appointment $appointment): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($appointment->professional_id === $professional->id, 403);

        $visit = DB::transaction(function () use ($appointment, $request) {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $existing = Visit::query()->where('appointment_id', $locked->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($locked->status !== 'arrived') {
                throw ValidationException::withMessages(['visit' => 'O atendimento começa depois que a recepção confirma a chegada.']);
            }
            $visit = Visit::query()->create([
                'unit_id' => $locked->unit_id, 'appointment_id' => $locked->id, 'professional_id' => $locked->professional_id,
                'patient_id' => $locked->patient_id, 'opened_by_user_id' => $request->user()->id, 'opened_at' => now(),
            ]);
            $locked->forceFill(['status' => 'in_progress'])->save();
            AuditEvent::record('visit.opened', $visit->tenant_id, $visit, ['unit_id' => $visit->unit_id, 'appointment_id' => $locked->id]);

            return $visit;
        });

        return to_route('visits.show', $visit);
    }

    public function show(Request $request, Visit $visit): Response|RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($visit->professional_id === $professional->id, 403);
        if ($visit->closed_at !== null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Este atendimento foi encerrado. O prontuário fica disponível só durante um atendimento ativo.']);

            return to_route('visits.index');
        }
        $patient = $visit->patient()->firstOrFail();
        $ids = ClinicalAccess::recordIds($patient);
        ClinicalAccess::recordRead($request->user(), $patient, $visit);
        Inertia::encryptHistory();
        $timezone = $visit->unit()->value('timezone') ?? 'UTC';

        return Inertia::render('visits/show', [
            'visit' => ['id' => $visit->id, 'openedAt' => $visit->opened_at->setTimezone($timezone)->format('d/m/Y H:i'), 'emergency' => $visit->emergency_episode_id !== null],
            // Step 4.4a: choices for an admission request when closing an emergency visit.
            'admissionOptions' => $visit->emergency_episode_id === null ? null : [...AdmissionController::requestOptions($visit->unit_id), 'me' => $professional->id],
            'patient' => self::patientHeader($patient),
            'allergies' => PatientAllergyController::listing($ids, $timezone),
            'allergyForm' => PatientAllergyController::form($patient, $request->user()),
            // Step 4b.2a: prescribing is for the emergency visit only.
            'prescriptions' => $visit->emergency_episode_id === null ? null : PrescriptionController::panel($request->user(), null, $visit, $timezone),
            'record' => self::signedRecord($ids, $timezone),
            'documents' => ClinicalDocumentController::listing($ids, $timezone, $request->user()->id),
            // Step 5.2: the link to the exam orders of this visit.
            'canOrderExams' => ExamOrderController::orderer($request->user(), $visit->unit_id) !== null,
            // Drafts are seen only by their author.
            'drafts' => self::drafts($visit, $ids, $request->user()->id)->orderBy('id')->get(['id', 'body', 'addendum_of_id'])
                ->map(fn (ClinicalNote $note) => ['id' => $note->id, 'body' => $note->body, 'addendumOf' => $note->addendum_of_id]),
        ]);
    }

    public function close(Request $request, Visit $visit): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($visit->professional_id === $professional->id, 403);
        // Step 4.3: an emergency visit ends with an outcome.
        $outcome = $visit->emergency_episode_id === null ? null : (string) $request->validate(
            ['outcome' => ['required', Rule::in(EmergencyEpisode::OUTCOMES)]],
            ['outcome.required' => 'Escolha o desfecho do atendimento de urgência.'],
            ['outcome' => 'desfecho'],
        )['outcome'];

        // Step 4.4a: an admission request names the sector and the responsible doctor.
        $admission = null;
        if ($outcome === 'admission_requested') {
            $data = $request->validate([
                'requested_sector_id' => ['required', 'integer'],
                'responsible_professional_id' => ['required', 'integer'],
                'isolation' => ['boolean'],
            ], [], ['requested_sector_id' => 'setor de internação', 'responsible_professional_id' => 'médico responsável']);
            $admission = ['requested_sector_id' => (int) $data['requested_sector_id'], 'responsible_professional_id' => (int) $data['responsible_professional_id'], 'isolation' => (bool) ($data['isolation'] ?? false)];
        }

        DB::transaction(function () use ($visit, $request, $outcome, $admission) {
            $locked = Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();
            if ($locked->closed_at !== null) {
                throw ValidationException::withMessages(['visit' => 'Este atendimento já foi encerrado.']);
            }
            $patient = $locked->patient()->firstOrFail();
            if (self::drafts($locked, ClinicalAccess::recordIds($patient), $request->user()->id)->exists()) {
                throw ValidationException::withMessages(['visit' => 'Assine os rascunhos antes de encerrar o atendimento.']);
            }
            $locked->forceFill(['closed_at' => now()])->save();
            if ($locked->appointment_id !== null) {
                Appointment::query()->whereKey($locked->appointment_id)->update(['status' => 'completed']);
            }
            AuditEvent::record('visit.closed', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id]);
            if ($outcome !== null) {
                EmergencyController::end($locked, $outcome, $request->user()->id, $admission);
            }
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Atendimento encerrado.']);

        return $outcome === null ? to_route('visits.index') : to_route('emergency.index', ['unit' => $visit->unit_id]);
    }

    /**
     * A new draft: a note of this visit, or an addendum to a signed note of the patient's record.
     */
    public function storeNote(Request $request, Visit $visit): RedirectResponse
    {
        $professional = self::professional($request);
        abort_unless($visit->professional_id === $professional->id && $visit->closed_at === null, 403);
        $data = $request->validate(['body' => self::BODY, 'addendum_of_id' => ['nullable', 'integer']], [], ['body' => 'texto']);
        $patient = $visit->patient()->firstOrFail();

        $original = self::addendumOf($data['addendum_of_id'] ?? null, $patient);
        DB::transaction(function () use ($data, $visit, $request, $professional, $original) {
            // Locked like close(): a draft never lands on a visit closed in the meantime.
            if (Visit::query()->whereKey($visit->id)->lockForUpdate()->value('closed_at') !== null) {
                throw ValidationException::withMessages(['visit' => 'Este atendimento já foi encerrado.']);
            }
            if ($original === null && ClinicalNote::query()->where('visit_id', $visit->id)->where('author_user_id', $request->user()->id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['body' => 'Já existe um rascunho da evolução deste atendimento. Continue nele.']);
            }
            $note = ClinicalNote::query()->create([
                'patient_id' => $original->patient_id ?? $visit->patient_id, 'visit_id' => $original === null ? $visit->id : null,
                'addendum_of_id' => $original?->id, 'author_user_id' => $request->user()->id, 'author_professional_id' => $professional->id, 'body' => $data['body'],
            ]);
            AuditEvent::record('clinical_note.created', $note->tenant_id, $note, array_filter(['visit_id' => $visit->id, 'addendum_of' => $original?->id]));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rascunho salvo.']);

        return back();
    }

    public function updateNote(Request $request, ClinicalNote $note): RedirectResponse
    {
        self::ownDraft($request, $note);
        $data = $request->validate(['body' => self::BODY], [], ['body' => 'texto']);

        DB::transaction(function () use ($note, $data) {
            $locked = self::lockDraft($note);
            $locked->forceFill(['body' => $data['body']])->save();
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rascunho salvo.']);

        return back();
    }

    /**
     * Signing saves the text shown and makes it final, confirmed with the password (no ICP-Brasil: known
     * limit of delivery 3).
     */
    public function signNote(Request $request, ClinicalNote $note): RedirectResponse
    {
        self::ownDraft($request, $note);
        $data = $request->validate(['body' => self::BODY, 'password' => ['required', 'string']], [], ['body' => 'texto', 'password' => 'senha']);
        self::confirmPassword($request, $data['password'], 'clinical_note.sign_failed', $note, 'Senha incorreta. A evolução não foi assinada.');

        DB::transaction(function () use ($note, $data) {
            $locked = self::lockDraft($note);
            $locked->forceFill(['body' => $data['body'], 'status' => 'signed', 'signed_at' => now()])->save();
            AuditEvent::record('clinical_note.signed', $locked->tenant_id, $locked, array_filter(['visit_id' => $locked->visit_id, 'addendum_of' => $locked->addendum_of_id]));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Evolução assinada.']);

        return back();
    }

    /**
     * The signature's password check (notes, and the discharge summary of step 4.7). Only wrong passwords
     * count, so a busy professional signing many notes is never blocked.
     */
    public static function confirmPassword(Request $request, string $password, string $failedEvent, Model $subject, string $failedMessage): void
    {
        $attempts = 'clinical-sign:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($attempts, self::SIGN_ATTEMPTS)) {
            throw ValidationException::withMessages(['password' => 'Muitas tentativas com a senha errada. Tente de novo em '.ceil(RateLimiter::availableIn($attempts) / 60).' minutos.']);
        }
        if (! Hash::check($password, $request->user()->getAuthPassword())) {
            RateLimiter::hit($attempts, self::SIGN_LOCKOUT_SECONDS);
            AuditEvent::record($failedEvent, $subject->getAttribute('tenant_id'), $subject);
            throw ValidationException::withMessages(['password' => $failedMessage]);
        }
        RateLimiter::clear($attempts);
    }

    /**
     * The signed note of the patient's record that an addendum corrects, if one was named.
     */
    public static function addendumOf(mixed $id, Patient $patient): ?ClinicalNote
    {
        return $id === null ? null : ClinicalNote::query()->whereKey((int) $id)->whereIn('patient_id', ClinicalAccess::recordIds($patient))
            ->whereNull('addendum_of_id')->where('status', 'signed')->first()
            ?? throw ValidationException::withMessages(['body' => 'O adendo corrige uma evolução assinada deste paciente.']);
    }

    /**
     * Identification only, for the header of a record page.
     *
     * @return array<string, mixed>
     */
    public static function patientHeader(Patient $patient): array
    {
        return [
            'name' => $patient->social_name ?? $patient->name,
            'civilName' => $patient->social_name !== null ? $patient->name : null,
            'birthDate' => $patient->birth_date?->toDateString(),
            'sex' => $patient->sex,
        ];
    }

    /**
     * The signed notes of a record, newest first, each with its signed addenda. Callers must have checked
     * ClinicalAccess and logged the read.
     *
     * @param  list<int>  $patientIds
     * @return list<array<string, mixed>>
     */
    public static function signedRecord(array $patientIds, string $timezone): array
    {
        $when = fn (?CarbonInterface $at) => $at?->setTimezone($timezone)->format('d/m/Y H:i');
        $signed = fn (ClinicalNote $note) => ['id' => $note->id, 'body' => $note->body, 'signedAt' => $when($note->signed_at), 'author' => $note->author?->name];

        return array_values(ClinicalNote::query()->whereIn('patient_id', $patientIds)->whereNull('addendum_of_id')->where('status', 'signed')
            ->with(['author:id,name', 'visit.unit:id,name', 'admission.unit:id,name', 'addenda' => fn ($query) => $query->where('status', 'signed')->with('author:id,name')->orderBy('signed_at')])
            ->orderByDesc('signed_at')->get()
            ->map(fn (ClinicalNote $note) => [...$signed($note), 'unit' => implode(' · ', array_filter([$note->visit?->unit->name ?? $note->admission?->unit->name,
                $note->admission?->discharge_summary_id === $note->id ? 'Sumário de alta' : null])) ?: null, 'addenda' => $note->addenda->map($signed)->values()->all()])
            ->all());
    }

    /**
     * The author's drafts that belong to this visit: its note, and addenda to the patient's record (an
     * addendum has no visit of its own).
     *
     * @param  list<int>  $patientIds
     * @return Builder<ClinicalNote>
     */
    private static function drafts(Visit $visit, array $patientIds, int $userId): Builder
    {
        return ClinicalNote::query()->where('author_user_id', $userId)->where('status', 'draft')
            ->where(fn (Builder $query) => $query->where('visit_id', $visit->id)->orWhere(fn (Builder $addenda) => $addenda->whereNotNull('addendum_of_id')->whereIn('patient_id', $patientIds)));
    }

    private static function professional(Request $request): Professional
    {
        return ClinicalAccess::professional($request->user()) ?? abort(403);
    }

    /**
     * The author edits or signs a draft only while reading the record is allowed: the note of a visit only
     * while that visit is open, the note of an admission while the author is on it (step 4.4b), an addendum
     * while the author has an open visit or an active admission of the patient.
     */
    private static function ownDraft(Request $request, ClinicalNote $note): void
    {
        abort_unless($note->author_user_id === $request->user()->id && $note->status === 'draft', 403);
        $patient = Patient::query()->findOrFail($note->patient_id);
        $open = match (true) {
            $note->visit_id !== null => Visit::query()->whereKey($note->visit_id)->whereNull('closed_at')->exists(),
            // Step 4.4b: an admission note only while the author still has that admission as a basis.
            $note->admission_id !== null => ClinicalAccess::activeAdmission($request->user(), $patient)?->id === $note->admission_id,
            default => ClinicalAccess::activeVisit($request->user(), $patient) !== null || ClinicalAccess::activeAdmission($request->user(), $patient) !== null,
        };
        abort_unless($open, 403);
    }

    private static function lockDraft(ClinicalNote $note): ClinicalNote
    {
        $locked = ClinicalNote::query()->whereKey($note->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'draft') {
            throw ValidationException::withMessages(['body' => 'Esta evolução já foi assinada. Para corrigir, registre um adendo.']);
        }

        return $locked;
    }
}
