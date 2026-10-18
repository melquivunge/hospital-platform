<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\RoleAssignment;
use App\Models\ScheduleBlock;
use App\Models\ScheduleRule;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use App\Tenancy\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reception books patients into the schedule grid (step 3.4b): free slots are the grid minus blocks and
 * live bookings; a walk-in is explicit and may overlap. Cancel needs a reason, rescheduling cancels and
 * links the new booking, a no-show is marked after the time. The database refuses double bookings.
 */
class AppointmentController extends Controller
{
    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(self::bookingUnitIds(self::membership($request)))->orderBy('name')->get(['id', 'tenant_id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        Inertia::encryptHistory();

        $rescheduling = $request->filled('reschedule')
            ? Appointment::query()->whereKey($request->integer('reschedule'))->where('status', 'scheduled')->whereIn('unit_id', $units->modelKeys())->first()
            : null;
        $unit = $units->firstWhere('id', $rescheduling->unit_id ?? $request->integer('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';
        $professionals = ScheduleController::professionals($unit);
        $professional = $professionals->firstWhere('id', $request->integer('professional') ?: $rescheduling?->professional_id) ?? $professionals->first();
        $date = is_string($request->query('date')) ? CarbonImmutable::createFromFormat('!Y-m-d', $request->query('date'), $timezone) : false;
        $day = $date instanceof CarbonImmutable && $date->format('Y-m-d') === $request->query('date') ? $date : CarbonImmutable::now($timezone)->startOfDay();
        $patient = self::patient($rescheduling->patient_id ?? $request->integer('patient'));

        return Inertia::render('appointments/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unit' => $unit->only('id', 'name', 'timezone'),
            'professionals' => $professionals->map->only('id', 'name')->values(),
            'professionalId' => $professional?->id,
            'date' => $day->format('Y-m-d'),
            'patient' => $patient === null ? null : ['id' => $patient->id, 'name' => $patient->social_name ?? $patient->name],
            'rescheduling' => $rescheduling === null ? null : [
                'id' => $rescheduling->id,
                'when' => $rescheduling->starts_at->setTimezone($timezone)->format('d/m/Y H:i'),
            ],
            'slots' => $professional === null ? [] : self::slots($unit, $professional->id, $day),
            'appointments' => $professional === null ? [] : Appointment::query()->where('unit_id', $unit->id)->where('professional_id', $professional->id)
                ->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc())
                ->with('patient:id,name,social_name')->orderBy('starts_at')->orderBy('id')->get()
                ->map(fn (Appointment $appointment) => [
                    'id' => $appointment->id,
                    'start' => $appointment->starts_at->setTimezone($timezone)->format('H:i'),
                    'end' => $appointment->ends_at->setTimezone($timezone)->format('H:i'),
                    'patientId' => $appointment->patient_id,
                    'patient' => $appointment->patient->social_name ?? $appointment->patient->name ?? null,
                    'walkIn' => $appointment->walk_in,
                    'status' => $appointment->status,
                    'cancelReason' => $appointment->cancel_reason,
                    'rescheduled' => $appointment->rescheduled_from_id !== null,
                    'canNoShow' => $appointment->status === 'scheduled' && $appointment->starts_at->isPast(),
                ]),
        ]);
    }

    public function store(Request $request, Unit $unit): RedirectResponse
    {
        self::authorizeUnit($request, $unit);
        $data = $request->validate([
            'professional_id' => ['required', 'integer', Rule::in(ScheduleController::professionals($unit)->pluck('id')->all())],
            'patient_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'walk_in' => ['boolean'],
            'rescheduled_from_id' => ['nullable', 'integer'],
            'reason' => ['required_with:rescheduled_from_id', 'nullable', 'string', 'max:255'],
        ], ['professional_id.in' => 'Escolha um profissional com habilitação aprovada nesta unidade.'], ['starts_at' => 'horário', 'reason' => 'motivo da remarcação']);
        $patient = self::patient((int) $data['patient_id']) ?? throw ValidationException::withMessages(['patient_id' => 'Paciente não encontrado.']);
        $walkIn = (bool) ($data['walk_in'] ?? false);
        $timezone = $unit->timezone ?? 'UTC';
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['starts_at'], $timezone);
        $minute = $start->hour * 60 + $start->minute;

        // The time must belong to the grid: a slot boundary, or any minute of a window for a walk-in.
        $rule = ScheduleRule::query()->where('unit_id', $unit->id)->where('professional_id', $data['professional_id'])->whereNull('deactivated_at')
            ->where('weekday', $start->isoWeekday())->where('start_minute', '<=', $minute)->where('end_minute', '>', $minute)->first();
        if ($rule === null || (! $walkIn && (($minute - $rule->start_minute) % $rule->slot_minutes !== 0 || $minute + $rule->slot_minutes > $rule->end_minute))) {
            throw ValidationException::withMessages(['starts_at' => $walkIn ? 'O encaixe precisa estar dentro do horário de atendimento do profissional.' : 'Escolha um horário livre da agenda.']);
        }
        // A walk-in late in the window ends with the window, never after the professional's hours.
        $end = $start->addMinutes(min($rule->slot_minutes, $rule->end_minute - $minute));
        // A walk-in is often the patient standing at the desk: today is fine, even a few minutes late.
        if ($start->isPast() && ! ($walkIn && $start->isToday())) {
            throw ValidationException::withMessages(['starts_at' => 'Este horário já passou.']);
        }
        if (self::blocked($unit->id, (int) $data['professional_id'], $start, $end)) {
            throw ValidationException::withMessages(['starts_at' => 'O profissional está com a agenda bloqueada neste horário.']);
        }

        try {
            DB::transaction(function () use ($unit, $data, $patient, $walkIn, $start, $end) {
                // Bookings of one professional are serialized: two concurrent inserts checking the exclusion
                // constraint wait on each other and deadlock; with this lock the second gets 23P01 instead.
                Professional::query()->whereKey($data['professional_id'])->lockForUpdate()->firstOrFail();
                // Revoking a qualification locks the same row: checked again now that nothing can change it.
                if (! Qualification::query()->where('professional_id', $data['professional_id'])->where('unit_id', $unit->id)->where('status', QualificationStatus::Approved)->exists()) {
                    throw ValidationException::withMessages(['professional_id' => 'Escolha um profissional com habilitação aprovada nesta unidade.']);
                }
                $previous = null;
                if (($data['rescheduled_from_id'] ?? null) !== null) {
                    // Locked first: two desks rescheduling the same booking end with one new booking.
                    $previous = Appointment::query()->whereKey($data['rescheduled_from_id'])->where('unit_id', $unit->id)->lockForUpdate()->first();
                    if ($previous?->status !== 'scheduled') {
                        throw ValidationException::withMessages(['starts_at' => 'Este agendamento não pode mais ser remarcado.']);
                    }
                    self::close($previous, 'cancelled', $data['reason']);
                }
                $patientId = self::lockedPatientId($previous->patient_id ?? $patient->id);
                $appointment = Appointment::query()->create([
                    'unit_id' => $unit->id, 'professional_id' => $data['professional_id'], 'patient_id' => $patientId,
                    'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'walk_in' => $walkIn, 'rescheduled_from_id' => $previous?->id,
                    'created_by_user_id' => Auth::id(), 'created_at' => now(),
                ]);
                AuditEvent::record('appointment.created', $unit->tenant_id, $appointment, array_filter([
                    'unit_id' => $unit->id, 'professional_id' => $appointment->professional_id, 'patient_id' => $appointment->patient_id,
                    'walk_in' => $walkIn ?: null, 'rescheduled_from' => $previous?->id,
                ]));
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23P01') {
                throw $exception;
            }
            throw ValidationException::withMessages(['starts_at' => 'Este horário acabou de ser ocupado. Escolha outro ou registre como encaixe.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $walkIn ? 'Encaixe registrado.' : 'Consulta agendada.']);

        return to_route('appointments.index', ['unit' => $unit->id, 'professional' => $data['professional_id'], 'date' => $start->format('Y-m-d')]);
    }

    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        $unit = self::authorizeAppointment($request, $appointment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motivo']);

        DB::transaction(function () use ($appointment, $data) {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'scheduled') {
                throw ValidationException::withMessages(['reason' => 'Este agendamento já foi encerrado.']);
            }
            self::close($locked, 'cancelled', $data['reason']);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Agendamento cancelado.']);

        return self::back($unit, $appointment);
    }

    public function noShow(Request $request, Appointment $appointment): RedirectResponse
    {
        $unit = self::authorizeAppointment($request, $appointment);

        DB::transaction(function () use ($appointment) {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'scheduled' || $locked->starts_at->isFuture()) {
                throw ValidationException::withMessages(['starts_at' => 'Só é possível marcar falta de um agendamento em aberto, depois do horário.']);
            }
            self::close($locked, 'no_show');
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Falta registrada.']);

        return self::back($unit, $appointment);
    }

    /**
     * Units where the member books consultations: the reception desks.
     *
     * @return list<int>
     */
    public static function bookingUnitIds(?Membership $membership): array
    {
        if ($membership === null || $membership->revoked_at !== null) {
            return [];
        }

        return array_values($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => $assignment->role === Role::Reception)
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)
            ->unique()
            ->all());
    }

    /**
     * The grid of the day for one professional, each slot free, booked, blocked or past.
     *
     * @return list<array{startsAt: string, time: string, state: string}>
     */
    private static function slots(Unit $unit, int $professionalId, CarbonImmutable $day): array
    {
        $taken = Appointment::query()->where('professional_id', $professionalId)->where('status', '<>', 'cancelled')->where('walk_in', false)
            ->where('starts_at', '<', $day->addDay()->utc())->where('ends_at', '>', $day->utc())->get(['starts_at', 'ends_at']);
        $blocks = ScheduleBlock::query()->where('unit_id', $unit->id)->where('professional_id', $professionalId)->whereNull('cancelled_at')
            ->where('starts_at', '<', $day->addDay()->utc())->where('ends_at', '>', $day->utc())->get(['starts_at', 'ends_at']);
        $overlaps = fn ($rows, CarbonImmutable $start, CarbonImmutable $end) => $rows->contains(fn ($row) => $row->starts_at < $end && $row->ends_at > $start);

        $slots = [];
        $rules = ScheduleRule::query()->where('unit_id', $unit->id)->where('professional_id', $professionalId)->whereNull('deactivated_at')
            ->where('weekday', $day->isoWeekday())->orderBy('start_minute')->get();
        foreach ($rules as $rule) {
            for ($minute = $rule->start_minute; $minute + $rule->slot_minutes <= $rule->end_minute; $minute += $rule->slot_minutes) {
                $start = $day->setTime(intdiv($minute, 60), $minute % 60);
                $end = $start->addMinutes($rule->slot_minutes);
                $slots[] = [
                    'startsAt' => $start->format('Y-m-d\TH:i'),
                    'time' => $start->format('H:i'),
                    'state' => match (true) {
                        $overlaps($taken, $start, $end) => 'booked',
                        $overlaps($blocks, $start, $end) => 'blocked',
                        $start->isPast() => 'past',
                        default => 'free',
                    },
                ];
            }
        }

        return $slots;
    }

    private static function blocked(int $unitId, int $professionalId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return ScheduleBlock::query()->where('unit_id', $unitId)->where('professional_id', $professionalId)->whereNull('cancelled_at')
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->exists();
    }

    private static function close(Appointment $appointment, string $status, ?string $reason = null): void
    {
        $appointment->forceFill(['status' => $status, 'cancel_reason' => $reason, 'closed_at' => now(), 'closed_by_user_id' => Auth::id()])->save();
        AuditEvent::record($status === 'cancelled' ? 'appointment.cancelled' : 'appointment.no_show', $appointment->tenant_id, $appointment, ['unit_id' => $appointment->unit_id]);
    }

    /**
     * A merged registration books under its primary one.
     */
    private static function patient(int $id): ?Patient
    {
        $patient = $id > 0 ? Patient::query()->find($id) : null;

        return $patient?->merged_into_id === null ? $patient : Patient::query()->find($patient->merged_into_id);
    }

    /**
     * The registration the booking goes to, read under a share lock: a merge or its undoing (which update
     * this row) cannot commit in between, so a booking never lands on a registration merged meanwhile.
     */
    private static function lockedPatientId(int $id): int
    {
        $patient = Patient::query()->whereKey($id)->sharedLock()->firstOrFail();

        return $patient->merged_into_id ?? $patient->id;
    }

    private static function membership(Request $request): ?Membership
    {
        return UnitPolicy::currentMembership($request->user());
    }

    private static function authorizeUnit(Request $request, Unit $unit): void
    {
        abort_unless(in_array($unit->id, self::bookingUnitIds(self::membership($request)), true), 403);
    }

    private static function authorizeAppointment(Request $request, Appointment $appointment): Unit
    {
        $unit = Unit::query()->findOrFail($appointment->unit_id);
        self::authorizeUnit($request, $unit);

        return $unit;
    }

    private static function back(Unit $unit, Appointment $appointment): RedirectResponse
    {
        return to_route('appointments.index', [
            'unit' => $unit->id, 'professional' => $appointment->professional_id,
            'date' => $appointment->starts_at->setTimezone($unit->timezone ?? 'UTC')->format('Y-m-d'),
        ]);
    }
}
