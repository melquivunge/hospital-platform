<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Insurer;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Triage;
use App\Models\TriageLevel;
use App\Models\Unit;
use App\Models\Visit;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Emergency department (step 4.2): the reception registers the arrival (an existing patient or an
 * unidentified one), professionals qualified at the unit classify the risk and reclassify. The queue
 * shows the unclassified first, then by level and waiting time, flagging an overdue target. The
 * reception sees names and levels, never the complaint or the vital signs.
 */
class EmergencyController extends Controller
{
    public function index(Request $request): Response
    {
        $membership = UnitPolicy::currentMembership($request->user());
        $reception = AppointmentController::bookingUnitIds($membership);
        $triage = self::triageUnitIds($request);
        $units = Unit::query()->whereKey([...$reception, ...$triage])->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        Inertia::encryptHistory();
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $canTriage = in_array($unit->id, $triage, true);
        $scale = TriageLevel::scale();
        $patient = in_array($unit->id, $reception, true) && $request->filled('patient') ? Patient::query()->find($request->integer('patient')) : null;
        if ($patient?->merged_into_id !== null) {
            $patient = Patient::query()->find($patient->merged_into_id);
        }

        $professional = ClinicalAccess::professional($request->user());
        // The latest classification (complaint and vital signs) is loaded only for who classifies. Waiting
        // first (unclassified, then level and arrival), then in care, then waiting for a bed.
        $episodes = EmergencyEpisode::query()->where('unit_id', $unit->id)->whereIn('status', EmergencyEpisode::OPEN)
            ->with(['patient:id,name,social_name,unidentified,apparent_age,sex,description', 'visit:id,emergency_episode_id,professional_id', 'visit.professional:id,name'])
            ->withCount('triages')
            ->when($canTriage, fn ($query) => $query->with('latestTriage'))
            ->get()
            ->sortBy(fn (EmergencyEpisode $episode) => [array_search($episode->status, EmergencyEpisode::OPEN, true), $episode->current_level === null ? 0 : 1, $episode->current_level ?? 0, $episode->arrived_at->timestamp])
            ->values();

        return Inertia::render('emergency/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unit' => $unit->only('id', 'name'),
            'canArrive' => in_array($unit->id, $reception, true),
            'insurers' => in_array($unit->id, $reception, true) ? Insurer::query()->where('active', true)->orderBy('name')->get(['id', 'name']) : [],
            'canTriage' => $canTriage,
            'canCall' => in_array($unit->id, self::triageUnitIds($request, attending: true), true),
            'levels' => $scale->values()->map->only('level', 'name', 'color', 'target_minutes'),
            'patient' => $patient === null ? null : ['id' => $patient->id, 'name' => $patient->social_name ?? $patient->name],
            'episodes' => $episodes->map(function (EmergencyEpisode $episode) use ($scale, $canTriage, $unit, $professional) {
                $waiting = (int) $episode->arrived_at->diffInMinutes(now());
                $level = $episode->current_level === null ? null : $scale->get($episode->current_level);
                $latest = $canTriage ? $episode->latestTriage : null;

                return [
                    'id' => $episode->id,
                    'patient' => $episode->patient->social_name ?? $episode->patient->name ?? null,
                    'unidentified' => (bool) $episode->patient?->unidentified,
                    'identification' => $episode->patient?->unidentified ? trim(($episode->patient->apparent_age !== null ? "cerca de {$episode->patient->apparent_age} anos · " : '').($episode->patient->description ?? '')) : null,
                    'arrivedAt' => $episode->arrived_at->setTimezone($unit->timezone ?? 'UTC')->format('H:i'),
                    'waitingMinutes' => $waiting,
                    'level' => $episode->current_level,
                    // The target is the time to care: it only runs while waiting.
                    'overdue' => $episode->status === 'waiting' && $level !== null && $waiting > $level->target_minutes,
                    'status' => $episode->status,
                    'attendedBy' => $episode->visit?->professional?->name,
                    'myVisitId' => $professional !== null && $episode->visit?->professional_id === $professional->id && $episode->status === 'in_care' ? $episode->visit->id : null,
                    'classifications' => (int) $episode->triages_count,
                    'latest' => $latest !== null ? $latest->only('id', 'level', 'complaint', 'systolic', 'diastolic', 'heart_rate', 'respiratory_rate', 'temperature', 'spo2', 'pain', 'glucose') : null,
                ];
            }),
        ]);
    }

    public function arrive(Request $request, Unit $unit): RedirectResponse
    {
        abort_unless(in_array($unit->id, AppointmentController::bookingUnitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $unidentified = $request->boolean('unidentified');
        $data = $request->validate($unidentified ? [
            'sex' => ['required', Rule::in(Patient::SEXES)],
            'apparent_age' => ['nullable', 'integer', 'between:0,120'],
            'description' => ['required', 'string', 'max:500'],
        ] : ['patient_id' => ['required', 'integer']], [], ['sex' => 'sexo', 'apparent_age' => 'idade aparente', 'description' => 'descrição', 'patient_id' => 'paciente']);
        // Step 6.2a0: the modality of the stay, as on a consultation's arrival; an admission keeps it.
        $modality = AppointmentController::validateModality($request, now($unit->timezone ?? 'UTC')->toDateString());

        try {
            DB::transaction(function () use ($request, $unit, $unidentified, $data, $modality) {
                if ($unidentified) {
                    $patient = new Patient(['registered_unit_id' => $unit->id, 'name' => 'Não identificado', 'sex' => $data['sex']]);
                    $patient->forceFill(['unidentified' => true, 'apparent_age' => $data['apparent_age'] ?? null, 'description' => $data['description'], 'created_by_user_id' => $request->user()->id])->save();
                    AuditEvent::record('patient.created', $patient->tenant_id, $patient, ['unit_id' => $unit->id, 'status' => $patient->status, 'unidentified' => true]);
                } else {
                    // Shared lock: a merge in the middle cannot leave the episode on the duplicate.
                    $found = Patient::query()->sharedLock()->find((int) $data['patient_id']) ?? throw ValidationException::withMessages(['patient_id' => 'Paciente não encontrado.']);
                    $patient = $found->merged_into_id === null ? $found : Patient::query()->sharedLock()->findOrFail($found->merged_into_id);
                    // One open episode per person: registrations merged into this one count too.
                    if (EmergencyEpisode::query()->whereIn('patient_id', ClinicalAccess::recordIds($patient))->whereIn('status', EmergencyEpisode::OPEN)->exists()) {
                        throw ValidationException::withMessages(['patient_id' => 'Este paciente já está na urgência.']);
                    }
                }
                AppointmentController::lockInsurer($modality);
                $episode = EmergencyEpisode::query()->create(['unit_id' => $unit->id, 'patient_id' => $patient->id, 'arrived_at' => now(), 'arrived_by_user_id' => $request->user()->id, ...$modality]);
                // The card number stays in the clinical table, out of the audit trail.
                AuditEvent::record('emergency.arrived', $episode->tenant_id, $episode, array_filter(['unit_id' => $unit->id, 'patient_id' => $patient->id, 'modality' => $episode->modality, 'insurer_id' => $episode->insurer_id]));
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['patient_id' => 'Este paciente já está na urgência.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Chegada registrada. O paciente aguarda a classificação de risco.']);

        return to_route('emergency.index', ['unit' => $unit->id]);
    }

    public function triage(Request $request, EmergencyEpisode $episode): RedirectResponse
    {
        abort_unless(in_array($episode->unit_id, self::triageUnitIds($request), true), 403);
        $professional = ClinicalAccess::professional($request->user()) ?? abort(403);
        $rules = ['level' => ['required', 'integer', 'between:1,5'], 'complaint' => ['required', 'string', 'max:500']];
        foreach (Triage::VITALS as $field => [$min, $max]) {
            $rules[$field] = ['nullable', 'numeric', "between:{$min},{$max}"];
        }
        $data = $request->validate([...$rules, 'previous_id' => ['nullable', 'integer']], [], ['level' => 'nível de risco', 'complaint' => 'queixa principal']);
        // The classification the person saw: another one recorded meanwhile is not silently overridden.
        $seen = isset($data['previous_id']) ? (int) $data['previous_id'] : null;
        unset($data['previous_id']);

        DB::transaction(function () use ($episode, $data, $seen, $professional, $request) {
            $locked = EmergencyEpisode::query()->whereKey($episode->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'waiting') {
                throw ValidationException::withMessages(['level' => 'Este paciente não está mais aguardando na urgência.']);
            }
            $max = Triage::query()->where('episode_id', $locked->id)->max('id');
            $previous = $max === null ? null : (int) $max;
            if ($previous !== $seen) {
                throw ValidationException::withMessages(['level' => 'Outra classificação deste paciente foi registrada enquanto você preenchia. Recarregue a página e confira antes de reclassificar.']);
            }
            $triage = Triage::query()->create([...$data, 'episode_id' => $locked->id, 'previous_id' => $previous, 'professional_id' => $professional->id, 'user_id' => $request->user()->id, 'created_at' => now()]);
            $locked->forceFill(['current_level' => $triage->level, 'triaged_at' => $locked->triaged_at ?? now()])->save();
            AuditEvent::record('emergency.triaged', $locked->tenant_id, $locked, array_filter(['unit_id' => $locked->unit_id, 'reclassification' => $previous !== null ?: null]));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Classificação registrada.']);

        return back();
    }

    /**
     * Step 4.3: a professional of the unit calls a classified patient and opens the visit. The episode row
     * is locked: two calls end in one visit (the same professional lands on it, another one is told who
     * is attending). Notes, addenda, documents and the read trail are those of delivery 3.
     */
    public function call(Request $request, EmergencyEpisode $episode): RedirectResponse
    {
        // Step 4.9: calling opens the record, so only functions that attend patients call.
        abort_unless(in_array($episode->unit_id, self::triageUnitIds($request, attending: true), true), 403);
        $professional = ClinicalAccess::professional($request->user()) ?? abort(403);

        $visit = DB::transaction(function () use ($episode, $professional, $request) {
            $locked = EmergencyEpisode::query()->whereKey($episode->id)->lockForUpdate()->firstOrFail();
            $existing = Visit::query()->where('emergency_episode_id', $locked->id)->whereNull('closed_at')->first();
            if ($existing !== null) {
                if ($existing->professional_id !== $professional->id) {
                    throw ValidationException::withMessages(['call' => 'Este paciente já está em atendimento com '.Professional::query()->whereKey($existing->professional_id)->value('name').'.']);
                }

                return $existing;
            }
            if ($locked->status !== 'waiting') {
                throw ValidationException::withMessages(['call' => 'Este paciente não está mais aguardando na urgência.']);
            }
            if ($locked->current_level === null) {
                throw ValidationException::withMessages(['call' => 'Classifique o risco antes de chamar o paciente.']);
            }
            $visit = Visit::query()->create([
                'unit_id' => $locked->unit_id, 'emergency_episode_id' => $locked->id, 'professional_id' => $professional->id,
                'patient_id' => $locked->patient_id, 'opened_by_user_id' => $request->user()->id, 'opened_at' => now(),
            ]);
            $locked->forceFill(['status' => 'in_care'])->save();
            AuditEvent::record('visit.opened', $visit->tenant_id, $visit, ['unit_id' => $visit->unit_id, 'emergency_episode_id' => $locked->id]);

            return $visit;
        });

        return to_route('visits.show', $visit);
    }

    /**
     * Step 4.3: the patient left before being called; the time and the author are recorded. Once in care,
     * evasion is the outcome the attending professional records when closing the visit.
     */
    public function evade(Request $request, EmergencyEpisode $episode): RedirectResponse
    {
        $units = [...AppointmentController::bookingUnitIds(UnitPolicy::currentMembership($request->user())), ...self::triageUnitIds($request)];
        abort_unless(in_array($episode->unit_id, $units, true), 403);

        DB::transaction(function () use ($episode, $request) {
            $locked = EmergencyEpisode::query()->whereKey($episode->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'waiting') {
                throw ValidationException::withMessages(['call' => 'Só quem ainda aguarda pode ser registrado como evasão pela fila. Em atendimento, o desfecho é registrado ao encerrar.']);
            }
            $locked->forceFill(['status' => 'evaded', 'ended_at' => now(), 'ended_by_user_id' => $request->user()->id])->save();
            AuditEvent::record('emergency.ended', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id, 'outcome' => 'evaded']);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Evasão registrada.']);

        return back();
    }

    /**
     * Step 4.3: the outcome of an emergency visit, recorded when the attending professional closes it.
     * Called inside the visit's transaction, after the visit row is locked (visit → episode).
     *
     * @param  array{requested_sector_id: int, responsible_professional_id: int, isolation: bool}|null  $admission  required for an admission request (step 4.4a)
     */
    public static function end(Visit $visit, string $outcome, int $userId, ?array $admission = null): void
    {
        $episode = EmergencyEpisode::query()->whereKey($visit->emergency_episode_id)->lockForUpdate()->firstOrFail();
        if ($episode->status !== 'in_care') {
            throw ValidationException::withMessages(['visit' => 'O episódio de urgência deste atendimento já foi encerrado.']);
        }
        $episode->forceFill(['status' => $outcome, 'ended_at' => now(), 'ended_by_user_id' => $userId])->save();
        AuditEvent::record('emergency.ended', $episode->tenant_id, $episode, ['unit_id' => $episode->unit_id, 'outcome' => $outcome, 'visit_id' => $visit->id]);
        if ($outcome === 'admission_requested') {
            AdmissionController::request($episode, $admission ?? throw ValidationException::withMessages(['requested_sector_id' => 'Informe o setor e o médico responsável da internação.']), $userId);
        }
    }

    /**
     * Units where the signed-in professional classifies risk: approved qualifications valid today, with the
     * dates local to the unit (as in Eligibility).
     *
     * @return list<int>
     */
    public static function triageUnitIds(Request $request, bool $attending = false): array
    {
        $professional = ClinicalAccess::professional($request->user());
        if ($professional === null) {
            return [];
        }

        return array_values(Qualification::query()->where('professional_id', $professional->id)->where('status', QualificationStatus::Approved)
            ->when($attending, fn ($query) => $query->whereIn('job_function_id', JobFunction::query()->where('attends_patients', true)->select('id')))
            ->with('unit:id,timezone')->get()
            ->filter(function (Qualification $qualification) {
                $today = now($qualification->unit->timezone ?? 'UTC')->toDateString();

                return $qualification->valid_from->toDateString() <= $today
                    && ($qualification->valid_until === null || $qualification->valid_until->toDateString() >= $today);
            })
            ->map(fn (Qualification $qualification) => (int) $qualification->unit_id)->unique()->all());
    }

    /**
     * Who sees the emergency queue at all (menu).
     */
    public static function visible(Request $request, ?Membership $membership): bool
    {
        return AppointmentController::bookingUnitIds($membership) !== [] || self::triageUnitIds($request) !== [];
    }
}
