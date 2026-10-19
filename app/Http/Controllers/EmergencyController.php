<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Qualification;
use App\Models\Triage;
use App\Models\TriageLevel;
use App\Models\Unit;
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

        // The latest classification (complaint and vital signs) is loaded only for who classifies.
        $episodes = EmergencyEpisode::query()->where('unit_id', $unit->id)->where('status', 'waiting')
            ->with('patient:id,name,social_name,unidentified,apparent_age,sex,description')->withCount('triages')
            ->when($canTriage, fn ($query) => $query->with('latestTriage'))
            ->get()
            ->sortBy(fn (EmergencyEpisode $episode) => [$episode->current_level === null ? 0 : 1, $episode->current_level ?? 0, $episode->arrived_at->timestamp])
            ->values();

        return Inertia::render('emergency/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unit' => $unit->only('id', 'name'),
            'canArrive' => in_array($unit->id, $reception, true),
            'canTriage' => $canTriage,
            'levels' => $scale->values()->map->only('level', 'name', 'color', 'target_minutes'),
            'patient' => $patient === null ? null : ['id' => $patient->id, 'name' => $patient->social_name ?? $patient->name],
            'episodes' => $episodes->map(function (EmergencyEpisode $episode) use ($scale, $canTriage, $unit) {
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
                    'overdue' => $level !== null && $waiting > $level->target_minutes,
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

        try {
            DB::transaction(function () use ($request, $unit, $unidentified, $data) {
                if ($unidentified) {
                    $patient = new Patient(['registered_unit_id' => $unit->id, 'name' => 'Não identificado', 'sex' => $data['sex']]);
                    $patient->forceFill(['unidentified' => true, 'apparent_age' => $data['apparent_age'] ?? null, 'description' => $data['description'], 'created_by_user_id' => $request->user()->id])->save();
                    AuditEvent::record('patient.created', $patient->tenant_id, $patient, ['unit_id' => $unit->id, 'status' => $patient->status, 'unidentified' => true]);
                } else {
                    // Shared lock: a merge in the middle cannot leave the episode on the duplicate.
                    $found = Patient::query()->sharedLock()->find((int) $data['patient_id']) ?? throw ValidationException::withMessages(['patient_id' => 'Paciente não encontrado.']);
                    $patient = $found->merged_into_id === null ? $found : Patient::query()->sharedLock()->findOrFail($found->merged_into_id);
                    // One open episode per person: registrations merged into this one count too.
                    if (EmergencyEpisode::query()->whereIn('patient_id', ClinicalAccess::recordIds($patient))->where('status', '<>', 'closed')->exists()) {
                        throw ValidationException::withMessages(['patient_id' => 'Este paciente já está na urgência.']);
                    }
                }
                $episode = EmergencyEpisode::query()->create(['unit_id' => $unit->id, 'patient_id' => $patient->id, 'arrived_at' => now(), 'arrived_by_user_id' => $request->user()->id]);
                AuditEvent::record('emergency.arrived', $episode->tenant_id, $episode, ['unit_id' => $unit->id, 'patient_id' => $patient->id]);
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
     * Units where the signed-in professional classifies risk: approved qualifications valid today, with the
     * dates local to the unit (as in Eligibility).
     *
     * @return list<int>
     */
    public static function triageUnitIds(Request $request): array
    {
        $professional = ClinicalAccess::professional($request->user());
        if ($professional === null) {
            return [];
        }

        return array_values(Qualification::query()->where('professional_id', $professional->id)->where('status', QualificationStatus::Approved)
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
