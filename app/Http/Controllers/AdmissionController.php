<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\EmergencyEpisode;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use App\Professionals\QualificationStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inpatient admission (step 4.4a): the emergency doctor requests it when closing the visit (sector,
 * responsible doctor, isolation); bed management allocates a free bed. A bed outside the sex or isolation
 * rule is allowed with a recorded justification (user decision). Bed management sees identification only,
 * never clinical content.
 */
class AdmissionController extends Controller
{
    public function index(Request $request): Response
    {
        $unitIds = BedController::managedUnitIds(UnitPolicy::currentMembership($request->user()));
        $units = Unit::query()->whereKey($unitIds)->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        Inertia::encryptHistory();
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';

        $admissions = Admission::query()->where('unit_id', $unit->id)->whereIn('status', Admission::ACTIVE)
            ->with(['patient:id,name,social_name,sex,birth_date,unidentified,apparent_age', 'requestedSector:id,name', 'responsible:id,name', 'bed:id,code,room,sector_id', 'bed.sector:id,name'])
            ->orderBy('requested_at')->get();

        return Inertia::render('admissions/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unit' => $unit->only('id', 'name'),
            'admissions' => $admissions->map(fn (Admission $admission) => [
                'id' => $admission->id,
                'status' => $admission->status,
                'patient' => $admission->patient->social_name ?? $admission->patient->name ?? null,
                'sex' => $admission->patient?->sex,
                'age' => $admission->patient?->birth_date->age ?? $admission->patient?->apparent_age,
                'sector' => $admission->requestedSector?->name,
                'responsible' => $admission->responsible?->name,
                'isolation' => $admission->isolation,
                'requestedAt' => $admission->requested_at->setTimezone($timezone)->format('d/m H:i'),
                'bed' => $admission->bed === null ? null : "{$admission->bed->sector?->name} · quarto {$admission->bed->room} · leito {$admission->bed->code}",
                'admittedAt' => $admission->admitted_at?->setTimezone($timezone)->format('d/m H:i'),
                'justification' => $admission->compatibility_justification,
                'history' => $admission->status === 'admitted' ? WardController::history($admission, $timezone, bedsOnly: true) : [],
            ])->values(),
            // Step 4.6: open transfers into and out of this unit, identification only.
            'transfers' => AdmissionTransfer::query()->whereIn('status', AdmissionTransfer::OPEN)
                ->where(fn ($query) => $query->where('to_unit_id', $unit->id)->orWhere('from_unit_id', $unit->id))
                ->with(['admission.patient:id,name,social_name,sex', 'fromUnit:id,name', 'toUnit:id,name', 'requestedSector:id,name', 'responsible:id,name', 'reservedBed:id,code,room,sector_id', 'reservedBed.sector:id,name'])
                ->orderBy('requested_at')->get()
                ->map(fn (AdmissionTransfer $transfer) => [
                    'id' => $transfer->id,
                    'status' => $transfer->status,
                    'incoming' => $transfer->to_unit_id === $unit->id,
                    'patient' => $transfer->admission?->patient->social_name ?? $transfer->admission?->patient?->name,
                    'sex' => $transfer->admission?->patient?->sex,
                    'isolation' => (bool) $transfer->admission?->isolation,
                    'from' => $transfer->fromUnit?->name,
                    'to' => $transfer->toUnit?->name,
                    'sector' => $transfer->requestedSector?->name,
                    'responsible' => $transfer->responsible?->name,
                    'responsibleId' => $transfer->responsible_professional_id,
                    'bed' => $transfer->reservedBed === null ? null : "{$transfer->reservedBed->sector?->name} · quarto {$transfer->reservedBed->room} · leito {$transfer->reservedBed->code}",
                ])->values(),
            // Step 4.9: who can be named responsible when receiving a transfer here.
            'professionals' => self::requestOptions($unit->id)['professionals'],
            'freeBeds' => Bed::query()->where('unit_id', $unit->id)->whereNull('deactivated_at')->where('status', 'free')
                ->with('sector:id,name')->orderBy('sector_id')->orderBy('room')->orderBy('code')->get()
                ->map(fn (Bed $bed) => ['id' => $bed->id, 'sectorId' => $bed->sector_id, 'label' => "{$bed->sector?->name} · quarto {$bed->room} · leito {$bed->code}", 'sex' => $bed->sex_restriction, 'isolation' => $bed->isolation])
                ->values(),
        ]);
    }

    /**
     * Bed management allocates a free bed. Locks: admission, then the bed by a conditional update, then
     * the emergency episode. The database refuses a second patient in the bed or a second admission.
     */
    public function allocate(Request $request, Admission $admission): RedirectResponse
    {
        abort_unless(in_array($admission->unit_id, BedController::managedUnitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        [$bedId, $typed] = self::bedData($request);

        DB::transaction(function () use ($admission, $bedId, $typed, $request) {
            $locked = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'requested') {
                throw ValidationException::withMessages(['bed_id' => 'Esta internação já recebeu leito. Confira a lista atualizada.']);
            }
            [$bed, $justification, $outsideRule] = self::occupy($locked, $bedId, $typed);
            $locked->forceFill([
                'status' => 'admitted', 'bed_id' => $bed->id, 'admitted_at' => now(), 'admitted_by_user_id' => $request->user()->id,
                'compatibility_justification' => $justification,
            ])->save();
            self::movement($locked, ['kind' => 'bed', 'to_bed_id' => $bed->id, 'reason' => $justification]);
            if ($locked->emergency_episode_id !== null) {
                EmergencyEpisode::query()->whereKey($locked->emergency_episode_id)->where('status', 'admission_requested')->update(['status' => 'admitted']);
            }
            // Ids and flags only; the justification stays in the clinical table.
            AuditEvent::record('admission.admitted', $locked->tenant_id, $locked, array_filter(['unit_id' => $locked->unit_id, 'bed_id' => $bed->id, 'outside_rule' => $outsideRule ?: null]));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Leito alocado. Paciente internado.']);

        return back();
    }

    /**
     * Step 4.5: bed management moves an inpatient to another free bed of the unit (another sector included).
     * The new bed becomes occupied, the previous one goes to cleaning, and the move is a new row in the
     * history. Ward access follows the bed's sector at once. Locks: admission, new bed, previous bed.
     */
    public function move(Request $request, Admission $admission): RedirectResponse
    {
        abort_unless(in_array($admission->unit_id, BedController::managedUnitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        [$bedId, $typed] = self::bedData($request);

        DB::transaction(function () use ($admission, $bedId, $typed) {
            $locked = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'admitted' || $locked->bed_id === null) {
                throw ValidationException::withMessages(['bed_id' => 'Esta internação não está num leito. Confira a lista atualizada.']);
            }
            if (AdmissionTransfer::query()->where('admission_id', $locked->id)->whereIn('status', AdmissionTransfer::OPEN)->exists()) {
                throw ValidationException::withMessages(['bed_id' => 'Há uma transferência entre unidades em andamento. Conclua ou cancele antes de trocar de leito.']);
            }
            if ($bedId === $locked->bed_id) {
                throw ValidationException::withMessages(['bed_id' => 'O paciente já está neste leito.']);
            }
            $previous = Bed::query()->findOrFail($locked->bed_id);
            [$bed, $justification, $outsideRule] = self::occupy($locked, $bedId, $typed);
            $released = Bed::query()->whereKey($previous->id)->where('status', 'occupied')
                ->update(['status' => 'cleaning', 'status_changed_at' => now(), 'updated_at' => now()]);
            if ($released === 0) {
                throw ValidationException::withMessages(['bed_id' => 'O leito atual deixou de estar ocupado. Confira o mapa de leitos antes de transferir.']);
            }
            BedController::event($previous, 'occupied', 'cleaning');
            $locked->forceFill(['bed_id' => $bed->id, 'compatibility_justification' => $justification])->save();
            self::movement($locked, ['kind' => 'bed', 'from_bed_id' => $previous->id, 'to_bed_id' => $bed->id, 'reason' => $justification]);
            AuditEvent::record('admission.moved', $locked->tenant_id, $locked, array_filter(['unit_id' => $locked->unit_id, 'from_bed_id' => $previous->id, 'bed_id' => $bed->id, 'outside_rule' => $outsideRule ?: null]));
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Paciente transferido de leito. O leito anterior foi para higienização.']);

        return back();
    }

    /**
     * A new row in the admission's history (step 4.5): insert-only, clinical.
     *
     * @param  array<string, int|string|null>  $values
     */
    public static function movement(Admission $admission, array $values): void
    {
        DB::table('admission_movements')->insert([...$values, 'tenant_id' => $admission->tenant_id, 'admission_id' => $admission->id, 'user_id' => Auth::id(), 'occurred_at' => now()]);
    }

    /**
     * The chosen bed and the justification typed (empty when none).
     *
     * @return array{int, string}
     */
    private static function bedData(Request $request): array
    {
        $data = $request->validate([
            'bed_id' => ['required', 'integer'],
            'compatibility_justification' => ['nullable', 'string', 'max:500'],
        ], [], ['bed_id' => 'leito', 'compatibility_justification' => 'justificativa']);

        return [(int) $data['bed_id'], trim((string) ($data['compatibility_justification'] ?? ''))];
    }

    /**
     * Takes a free active bed of the admission's unit for it (conditional update), with a justification when
     * the bed is outside the sex or isolation rule. Returns the bed, the justification kept and the flag.
     *
     * @return array{Bed, string|null, bool}
     */
    private static function occupy(Admission $admission, int $bedId, string $justification): array
    {
        $bed = Bed::query()->whereKey($bedId)->where('unit_id', $admission->unit_id)->whereNull('deactivated_at')->first()
            ?? throw ValidationException::withMessages(['bed_id' => 'Escolha um leito livre desta unidade.']);
        $mismatches = self::mismatches($bed, Patient::query()->findOrFail($admission->patient_id), $admission->isolation);
        if ($mismatches !== [] && $justification === '') {
            throw ValidationException::withMessages(['compatibility_justification' => 'Leito fora da regra ('.implode('; ', $mismatches).'). Registre a justificativa para usar este leito mesmo assim.']);
        }
        $changed = Bed::query()->whereKey($bed->id)->whereNull('deactivated_at')->where('status', 'free')
            ->update(['status' => 'occupied', 'block_reason' => null, 'status_changed_at' => now(), 'updated_at' => now()]);
        if ($changed === 0) {
            throw ValidationException::withMessages(['bed_id' => 'O leito deixou de estar livre nesse meio-tempo. Escolha outro.']);
        }
        BedController::event($bed, 'free', 'occupied');

        return [$bed, $mismatches === [] ? null : $justification, $mismatches !== []];
    }

    /**
     * Step 4.4a: created when the emergency visit closes with an admission request (inside its
     * transaction, after the visit and episode locks).
     *
     * @param  array{requested_sector_id: int, responsible_professional_id: int, isolation: bool}  $data
     */
    public static function request(EmergencyEpisode $episode, array $data, int $userId): void
    {
        $options = self::requestOptions($episode->unit_id);
        if (! in_array($data['requested_sector_id'], array_column($options['sectors'], 'id'), true)) {
            throw ValidationException::withMessages(['requested_sector_id' => 'Escolha um setor de internação desta unidade.']);
        }
        if (! in_array($data['responsible_professional_id'], array_column($options['professionals'], 'id'), true)) {
            throw ValidationException::withMessages(['responsible_professional_id' => 'Escolha um profissional habilitado nesta unidade.']);
        }
        // One active admission per person: registrations merged into the same record count too.
        $patient = Patient::query()->findOrFail($episode->patient_id);
        $taken = ValidationException::withMessages(['outcome' => 'Este paciente já tem uma internação em andamento.']);
        if (Admission::query()->whereIn('patient_id', ClinicalAccess::recordIds($patient))->whereIn('status', Admission::ACTIVE)->exists()) {
            throw $taken;
        }
        try {
            $admission = DB::transaction(fn () => Admission::query()->create([
                'unit_id' => $episode->unit_id, 'patient_id' => $episode->patient_id, 'emergency_episode_id' => $episode->id,
                'requested_sector_id' => $data['requested_sector_id'], 'responsible_professional_id' => $data['responsible_professional_id'],
                'isolation' => $data['isolation'], 'requested_by_user_id' => $userId, 'requested_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw $taken;
        }
        AuditEvent::record('admission.requested', $admission->tenant_id, $admission, ['unit_id' => $admission->unit_id, 'emergency_episode_id' => $episode->id]);
    }

    /**
     * Inpatient sectors of the unit (those with active beds) and the professionals who can be responsible:
     * an approved qualification valid today at the unit and a linked, active account.
     *
     * @return array{sectors: list<array{id: int, name: string}>, professionals: list<array{id: int, name: string}>}
     */
    public static function requestOptions(int $unitId): array
    {
        $unit = Unit::query()->findOrFail($unitId);
        $today = now($unit->timezone ?? 'UTC')->toDateString();
        $sectors = Sector::query()->where('unit_id', $unitId)
            ->whereExists(fn ($query) => $query->from('beds')->whereColumn('beds.sector_id', 'sectors.id')->whereNull('beds.deactivated_at'))
            ->orderBy('name')->get(['id', 'name']);
        $professionals = Professional::query()
            ->whereIn('membership_id', Membership::query()->whereNull('revoked_at')
                ->whereIn('user_id', User::query()->whereNull('deactivated_at')->select('id'))->select('id'))
            // Step 4.9: only functions that attend patients are responsible for an admission.
            ->whereIn('id', Qualification::query()->where('unit_id', $unitId)->where('status', QualificationStatus::Approved)
                ->whereIn('job_function_id', JobFunction::query()->where('attends_patients', true)->select('id'))
                ->whereDate('valid_from', '<=', $today)->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today))
                ->select('professional_id'))
            ->orderBy('name')->get(['id', 'name']);

        return [
            'sectors' => array_values($sectors->map(fn (Sector $sector) => ['id' => (int) $sector->id, 'name' => (string) $sector->name])->all()),
            'professionals' => array_values($professionals->map(fn (Professional $professional) => ['id' => (int) $professional->id, 'name' => (string) $professional->name])->all()),
        ];
    }

    /**
     * @return list<string>
     */
    public static function mismatches(Bed $bed, Patient $patient, bool $isolation): array
    {
        return array_values(array_filter([
            $bed->sex_restriction !== null && $patient->sex !== $bed->sex_restriction ? 'restrição de sexo do leito' : null,
            $isolation && ! $bed->isolation ? 'paciente precisa de isolamento e o leito não é de isolamento' : null,
            ! $isolation && $bed->isolation ? 'leito de isolamento para paciente sem indicação de isolamento' : null,
        ]));
    }
}
