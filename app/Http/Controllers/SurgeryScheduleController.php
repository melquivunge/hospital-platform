<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\OperatingRoom;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\RoleAssignment;
use App\Models\Surgery;
use App\Models\SurgeryReschedule;
use App\Models\SurgeryTeamMember;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use App\Tenancy\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The surgical agenda (step 5b.3a, ADR-0009). The Surgical center role of a unit sees the surgeries requested in it
 * and the day of each room, with the patient's name, the procedure, the time and the team, without a read logged.
 * It books a room, the start and the length, with the surgeon; reschedules and cancels with a reason; adds and
 * removes team members. Two scheduled surgeries never overlap in a room (exclusion constraint, rooms locked).
 */
class SurgeryScheduleController extends Controller
{
    /** An urgent surgery may be registered as starting a little before now: the patient is often on the way. */
    private const URGENT_GRACE_MINUTES = 30;

    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(self::unitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', (int) $request->query('unit')) ?? $units->first();
        $query = (string) $request->query('date');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $query) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $query, $unit->timezone) : null;
        $day = $date === null || $date->format('Y-m-d') !== $query ? CarbonImmutable::now($unit->timezone)->startOfDay() : $date;

        $requested = Surgery::query()->where('unit_id', $unit->id)->where('status', 'requested')
            ->orderByRaw("urgency = 'urgent' DESC")->orderBy('desired_date')->orderBy('id')->get();
        // Step 5b.5: the completed ones stay in the day, marked.
        $booked = Surgery::query()->where('unit_id', $unit->id)->whereIn('status', [...Surgery::ACTIVE, 'completed'])
            ->where('scheduled_start', '<', $day->addDay()->utc())->where('scheduled_end', '>', $day->utc())
            ->with('team.professional:id,name')->orderBy('scheduled_start')->get();
        $rooms = OperatingRoom::query()->where('unit_id', $unit->id)
            ->where(fn (Builder $query) => $query->where('active', true)->orWhereKey($booked->pluck('operating_room_id')->all()))
            ->orderBy('name_key')->get(['id', 'name', 'active']);
        $names = self::patientNames($requested->concat($booked));
        $places = ExamQueueController::places($requested->concat($booked));

        return Inertia::render('surgery-schedule/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'date' => $day->format('Y-m-d'),
            'urgencies' => Surgery::URGENCIES,
            'lateralities' => Surgery::LATERALITIES,
            'requested' => $requested->map(fn (Surgery $surgery) => [
                ...self::summary($surgery, $names, $places),
                'desiredDate' => $surgery->desired_date->format('d/m/Y'),
            ])->values(),
            'rooms' => $rooms->map(fn (OperatingRoom $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'active' => $room->active,
                'surgeries' => $booked->where('operating_room_id', $room->id)->map(fn (Surgery $surgery) => [
                    ...self::summary($surgery, $names, $places),
                    'start' => $surgery->scheduled_start?->timezone($unit->timezone)->format('d/m H:i'),
                    'end' => $surgery->scheduled_end?->timezone($unit->timezone)->format('d/m H:i'),
                    'surgeons' => $surgery->team->where('role', 'surgeon')->map(fn (SurgeryTeamMember $member) => $member->professional->name)->values(),
                    'status' => $surgery->status,
                ])->values(),
            ])->values(),
        ]);
    }

    public function show(Request $request, Surgery $surgery): Response
    {
        self::authorizeCenter($request->user(), $surgery);
        $timezone = Unit::query()->whereKey($surgery->unit_id)->value('timezone') ?? 'UTC';
        $surgery->load(['team.professional:id,name,council,council_number,council_uf', 'reschedules', 'room:id,name']);
        $names = self::patientNames([$surgery]);
        $rooms = OperatingRoom::query()->where('unit_id', $surgery->unit_id)->where('active', true)->orderBy('name_key')->get(['id', 'name']);
        $roomNames = OperatingRoom::query()->whereKey($surgery->reschedules->pluck('previous_room_id')->all())->pluck('name', 'id');
        $users = User::query()->whereKey(array_filter([$surgery->author_user_id, $surgery->scheduled_by_user_id, $surgery->cancelled_by_user_id, ...$surgery->reschedules->pluck('changed_by_user_id')->all()]))->pluck('name', 'id');
        $format = fn (?\DateTimeInterface $at) => $at === null ? null : CarbonImmutable::instance($at)->timezone($timezone)->format('d/m/Y H:i');
        $duration = DB::table('surgical_procedures')->where('id', $surgery->surgical_procedure_id)->value('duration_minutes');

        return Inertia::render('surgery-schedule/show', [
            'surgery' => [
                ...self::summary($surgery, $names, ExamQueueController::places([$surgery])),
                'status' => $surgery->status,
                'desiredDate' => $surgery->desired_date->format('d/m/Y'),
                'author' => $users->get($surgery->author_user_id),
                'room' => $surgery->room?->name,
                'roomId' => $surgery->operating_room_id,
                'start' => $format($surgery->scheduled_start),
                'end' => $format($surgery->scheduled_end),
                'startInput' => $surgery->scheduled_start?->timezone($timezone)->format('Y-m-d\TH:i'),
                'durationMinutes' => $surgery->scheduled_start === null ? $duration : (int) $surgery->scheduled_start->diffInMinutes($surgery->scheduled_end),
                'scheduledBy' => $users->get((int) $surgery->scheduled_by_user_id),
                'startedAt' => $format($surgery->started_at),
                'completedAt' => $format($surgery->completed_at),
                'urgentReason' => $surgery->urgent_reason,
                'cancellationReason' => $surgery->cancellation_reason,
                'cancelledBy' => $users->get((int) $surgery->cancelled_by_user_id),
                'team' => $surgery->team->map(fn (SurgeryTeamMember $member) => [
                    'id' => $member->id,
                    'name' => $member->professional->name,
                    'council' => trim(implode(' ', array_filter([$member->professional->council, $member->professional->council_number, $member->professional->council_uf]))) ?: null,
                    'role' => $member->role,
                ])->values(),
                'reschedules' => $surgery->reschedules->map(fn (SurgeryReschedule $change) => [
                    'id' => $change->id,
                    'room' => $roomNames->get($change->previous_room_id),
                    'start' => $format($change->previous_start),
                    'reason' => $change->reason,
                    'by' => $users->get($change->changed_by_user_id),
                    'at' => $format($change->changed_at),
                ])->values(),
            ],
            'rooms' => $rooms->values(),
            'professionals' => self::eligible($surgery->unit_id)->orderBy('name')->get(['id', 'name'])->values(),
            'roles' => SurgeryTeamMember::ROLES,
            'lateralities' => Surgery::LATERALITIES,
            'urgencies' => Surgery::URGENCIES,
            'now' => CarbonImmutable::now($timezone)->format('Y-m-d\TH:i'),
            // Step 5b.6c: the materials it used.
            'canUseMaterials' => $canUseMaterials = SurgeryMaterialController::canUse($request->user(), $surgery),
            'materials' => SurgeryMaterialController::present($surgery, $timezone, $canUseMaterials),
        ]);
    }

    public function schedule(Request $request, Surgery $surgery): RedirectResponse
    {
        self::authorizeCenter($request->user(), $surgery);
        $data = $request->validateWithBag('schedule', [
            ...self::bookingRules($surgery),
            'surgeon_id' => ['required', 'integer', Rule::in(self::eligible($surgery->unit_id)->pluck('professionals.id')->all())],
            'anesthetist_id' => ['nullable', 'integer', 'different:surgeon_id', Rule::in(self::eligible($surgery->unit_id)->pluck('professionals.id')->all())],
            'urgent_reason' => [$surgery->urgency === 'urgent' ? 'required' : 'prohibited', 'nullable', 'string', 'max:500'],
        ], [], self::attributes());
        [$start, $end] = self::interval($surgery, $data, 'schedule');

        self::book($surgery, (int) $data['operating_room_id'], 'schedule', function (Surgery $locked) use ($request, $data, $start, $end) {
            if ($locked->status !== 'requested') {
                throw ValidationException::withMessages(['operating_room_id' => 'Esta cirurgia já foi agendada ou cancelada. Recarregue a página.'])->errorBag('schedule');
            }
            $locked->update([
                'status' => 'scheduled', 'operating_room_id' => (int) $data['operating_room_id'], 'scheduled_start' => $start->utc(), 'scheduled_end' => $end->utc(),
                'scheduled_by_user_id' => $request->user()->id, 'scheduled_at' => now(), 'urgent_reason' => isset($data['urgent_reason']) ? trim($data['urgent_reason']) : null,
            ]);
            foreach (array_filter(['surgeon' => $data['surgeon_id'], 'anesthetist' => $data['anesthetist_id'] ?? null]) as $role => $professionalId) {
                $locked->team()->create(['tenant_id' => $locked->tenant_id, 'professional_id' => (int) $professionalId, 'role' => $role, 'added_by_user_id' => $request->user()->id, 'added_at' => now()]);
            }
            AuditEvent::record('surgery.scheduled', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id, 'operating_room_id' => $locked->operating_room_id, 'urgency' => $locked->urgency]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cirurgia agendada.']);

        return to_route('surgery-schedule.show', $surgery);
    }

    public function reschedule(Request $request, Surgery $surgery): RedirectResponse
    {
        self::authorizeCenter($request->user(), $surgery);
        $data = $request->validateWithBag('reschedule', [...self::bookingRules($surgery), 'reason' => ['required', 'string', 'max:500']], [], [...self::attributes(), 'reason' => 'motivo']);
        [$start, $end] = self::interval($surgery, $data, 'reschedule');

        self::book($surgery, (int) $data['operating_room_id'], 'reschedule', function (Surgery $locked) use ($request, $data, $start, $end) {
            if ($locked->status !== 'scheduled') {
                throw ValidationException::withMessages(['operating_room_id' => 'Esta cirurgia não está agendada. Recarregue a página.'])->errorBag('reschedule');
            }
            SurgeryReschedule::query()->create([
                'tenant_id' => $locked->tenant_id, 'surgery_id' => $locked->id, 'previous_room_id' => $locked->operating_room_id,
                'previous_start' => $locked->scheduled_start, 'previous_end' => $locked->scheduled_end, 'reason' => trim($data['reason']),
                'changed_by_user_id' => $request->user()->id, 'changed_at' => now(),
            ]);
            $locked->update(['operating_room_id' => (int) $data['operating_room_id'], 'scheduled_start' => $start->utc(), 'scheduled_end' => $end->utc()]);
            AuditEvent::record('surgery.rescheduled', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id, 'operating_room_id' => $locked->operating_room_id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cirurgia reagendada.']);

        return to_route('surgery-schedule.show', $surgery);
    }

    public function addMember(Request $request, Surgery $surgery): RedirectResponse
    {
        self::authorizeCenter($request->user(), $surgery);
        $data = $request->validateWithBag('team', [
            'professional_id' => ['required', 'integer', Rule::in(self::eligible($surgery->unit_id)->pluck('professionals.id')->all())],
            'role' => ['required', Rule::in(array_keys(SurgeryTeamMember::ROLES))],
        ], [], ['professional_id' => 'profissional', 'role' => 'função na equipe']);
        try {
            DB::transaction(function () use ($request, $surgery, $data) {
                $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
                if (! in_array($locked->status, Surgery::ACTIVE, true)) {
                    throw ValidationException::withMessages(['professional_id' => 'A equipe só muda numa cirurgia agendada ou em andamento.'])->errorBag('team');
                }
                $member = $locked->team()->create(['tenant_id' => $locked->tenant_id, 'professional_id' => (int) $data['professional_id'], 'role' => $data['role'], 'added_by_user_id' => $request->user()->id, 'added_at' => now()]);
                AuditEvent::record('surgery.team_added', $locked->tenant_id, $locked, ['member_id' => $member->id, 'professional_id' => $member->professional_id, 'role' => $member->role]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['professional_id' => 'Este profissional já está na equipe.'])->errorBag('team');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profissional incluído na equipe.']);

        return back();
    }

    public function removeMember(Request $request, SurgeryTeamMember $member): RedirectResponse
    {
        $surgery = Surgery::query()->findOrFail($member->surgery_id);
        self::authorizeCenter($request->user(), $surgery);
        $bag = "member{$member->id}";
        DB::transaction(function () use ($request, $surgery, $member, $bag) {
            $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
            $active = $locked->team()->get();
            if (! in_array($locked->status, Surgery::ACTIVE, true) || ! $active->contains('id', $member->id)) {
                throw ValidationException::withMessages(['member' => 'A equipe mudou nesse meio-tempo. Recarregue a página.'])->errorBag($bag);
            }
            if ($member->role === 'surgeon' && $active->where('role', 'surgeon')->count() === 1) {
                throw ValidationException::withMessages(['member' => 'A cirurgia precisa de um cirurgião. Inclua outro antes de retirar este.'])->errorBag($bag);
            }
            SurgeryTeamMember::query()->whereKey($member->id)->update(['removed_at' => now(), 'removed_by_user_id' => $request->user()->id]);
            AuditEvent::record('surgery.team_removed', $locked->tenant_id, $locked, ['member_id' => $member->id, 'professional_id' => $member->professional_id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profissional retirado da equipe.']);

        return back();
    }

    public function cancel(Request $request, Surgery $surgery): RedirectResponse
    {
        self::authorizeCenter($request->user(), $surgery);
        $data = $request->validateWithBag('cancel', ['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        DB::transaction(function () use ($request, $surgery, $data) {
            $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
            if (! in_array($locked->status, Surgery::OPEN, true)) {
                throw ValidationException::withMessages(['reason' => 'Esta cirurgia não pode mais ser cancelada.'])->errorBag('cancel');
            }
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $request->user()->id, 'cancellation_reason' => trim($data['reason'])]);
            AuditEvent::record('surgery.cancelled', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id, 'by' => 'surgical_center']);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cirurgia cancelada.']);

        return to_route('surgery-schedule.index', ['unit' => $surgery->unit_id]);
    }

    /**
     * The units where the membership holds the Surgical center role.
     *
     * @return list<int>
     */
    public static function unitIds(?Membership $membership): array
    {
        if ($membership === null || $membership->revoked_at !== null) {
            return [];
        }

        return array_values(array_unique($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => $assignment->role === Role::SurgicalCenter && $assignment->unit_id !== null)
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)->all()));
    }

    /**
     * Professionals with an approved qualification valid today in the unit: who may be on a surgical team there.
     *
     * @return Builder<Professional>
     */
    public static function eligible(int $unitId): Builder
    {
        $today = now(Unit::query()->whereKey($unitId)->value('timezone') ?? 'UTC')->toDateString();

        return Professional::query()->whereIn('professionals.id', Qualification::query()->where('unit_id', $unitId)->where('status', QualificationStatus::Approved)
            ->whereDate('valid_from', '<=', $today)->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today))
            ->select('professional_id'));
    }

    private static function authorizeCenter(User $user, Surgery $surgery): void
    {
        abort_unless(in_array($surgery->unit_id, self::unitIds(UnitPolicy::currentMembership($user)), true), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bookingRules(Surgery $surgery): array
    {
        return [
            'operating_room_id' => ['required', 'integer', Rule::exists('operating_rooms', 'id')->where('unit_id', $surgery->unit_id)->where('active', true)],
            'start' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['required', 'integer', 'between:5,1440'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(): array
    {
        return ['operating_room_id' => 'sala', 'start' => 'início', 'duration_minutes' => 'duração', 'surgeon_id' => 'cirurgião', 'anesthetist_id' => 'anestesista', 'urgent_reason' => 'motivo da urgência'];
    }

    /**
     * The booked interval in the unit's time: an elective starts in the future, within a year; an urgent may start
     * now (a few minutes ago at most).
     *
     * @param  array<string, mixed>  $data
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private static function interval(Surgery $surgery, array $data, string $bag): array
    {
        $timezone = Unit::query()->whereKey($surgery->unit_id)->value('timezone') ?? 'UTC';
        $start = rescue(fn () => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['start'], $timezone), null, false);
        if ($start === null || $start->format('Y-m-d\TH:i') !== $data['start']) {
            throw ValidationException::withMessages(['start' => 'Informe um horário válido.'])->errorBag($bag);
        }
        $earliest = $surgery->urgency === 'urgent' ? now()->subMinutes(self::URGENT_GRACE_MINUTES) : now();
        if ($start->lt($earliest)) {
            throw ValidationException::withMessages(['start' => 'Este horário já passou.'])->errorBag($bag);
        }
        if ($start->gt(now()->addYear())) {
            throw ValidationException::withMessages(['start' => 'Agende dentro de um ano.'])->errorBag($bag);
        }

        return [$start, $start->addMinutes((int) $data['duration_minutes'])];
    }

    /**
     * Runs the booking with the room locked: two bookings of the same room are serialized, so the second meets the
     * exclusion constraint as 23P01 instead of a deadlock.
     *
     * @param  \Closure(Surgery): void  $write
     */
    private static function book(Surgery $surgery, int $roomId, string $bag, \Closure $write): void
    {
        try {
            DB::transaction(function () use ($surgery, $roomId, $write) {
                // The current room too (a reschedule references it), always in id order: two reschedules swapping
                // rooms would otherwise wait on each other.
                OperatingRoom::query()->whereKey(array_unique(array_filter([$roomId, $surgery->operating_room_id])))->orderBy('id')->lockForUpdate()->get();
                $write(Surgery::query()->lockForUpdate()->findOrFail($surgery->id));
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '40P01') {
                throw ValidationException::withMessages(['start' => 'A agenda mudou ao mesmo tempo. Tente de novo.'])->errorBag($bag);
            }
            if ($exception->getCode() !== '23P01') {
                throw $exception;
            }
            throw ValidationException::withMessages(['start' => 'A sala já está ocupada neste horário. Escolha outro horário ou outra sala.'])->errorBag($bag);
        }
    }

    /**
     * @param  iterable<Surgery>  $surgeries
     * @return array<int, string>
     */
    private static function patientNames(iterable $surgeries): array
    {
        return Patient::query()->whereKey(collect($surgeries)->pluck('patient_id')->unique()->all())->get(['id', 'name', 'social_name'])
            ->mapWithKeys(fn (Patient $patient) => [$patient->id => $patient->social_name ?? $patient->name])->all();
    }

    /**
     * What the agenda shows (ADR-0009): the patient, the procedure, the side and where the patient is; never the
     * indication.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $places
     * @return array<string, mixed>
     */
    private static function summary(Surgery $surgery, array $names, array $places): array
    {
        return [
            'id' => $surgery->id,
            'patient' => $names[$surgery->patient_id] ?? null,
            'place' => $places[$surgery->id] ?? null,
            'procedure' => $surgery->procedure_name,
            'laterality' => $surgery->laterality,
            'urgency' => $surgery->urgency,
        ];
    }
}
