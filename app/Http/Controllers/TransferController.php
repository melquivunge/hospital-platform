<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\Patient;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Transfer between units of the network (step 4.6). The responsible doctor requests it; the destination's
 * bed management accepts (reserving a bed), refuses or receives; the origin's bed management records the
 * departure; the requester or the origin's bed management cancels. On reception the same admission moves
 * to the destination with its history. Locks: admission, then transfer, then beds. Audit and notifications
 * carry ids only (bed management of both units hears of each step).
 */
class TransferController extends Controller
{
    public function store(Request $request, Admission $admission): RedirectResponse
    {
        $professional = ClinicalAccess::professional($request->user()) ?? abort(403);
        abort_unless($admission->responsible_professional_id === $professional->id, 403);
        $data = $request->validate([
            'to_unit_id' => ['required', 'integer'],
            'requested_sector_id' => ['required', 'integer'],
            'responsible_professional_id' => ['required', 'integer'],
        ], [], ['to_unit_id' => 'unidade de destino', 'requested_sector_id' => 'setor', 'responsible_professional_id' => 'médico responsável no destino']);
        $toUnit = (int) $data['to_unit_id'];
        if ($toUnit === $admission->unit_id || ! Unit::query()->whereKey($toUnit)->exists()) {
            throw ValidationException::withMessages(['to_unit_id' => 'Escolha outra unidade da rede.']);
        }
        $options = AdmissionController::requestOptions($toUnit);
        if (! in_array((int) $data['requested_sector_id'], array_column($options['sectors'], 'id'), true)) {
            throw ValidationException::withMessages(['requested_sector_id' => 'Escolha um setor de internação da unidade de destino.']);
        }
        if (! in_array((int) $data['responsible_professional_id'], array_column($options['professionals'], 'id'), true)) {
            throw ValidationException::withMessages(['responsible_professional_id' => 'Escolha um profissional habilitado na unidade de destino.']);
        }

        DB::transaction(function () use ($admission, $professional, $data, $toUnit, $request) {
            $locked = Admission::query()->whereKey($admission->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'admitted' || $locked->responsible_professional_id !== $professional->id) {
                throw ValidationException::withMessages(['to_unit_id' => 'A internação mudou nesse meio-tempo. Recarregue a página.']);
            }
            if (AdmissionTransfer::query()->where('admission_id', $locked->id)->whereIn('status', AdmissionTransfer::OPEN)->exists()) {
                throw ValidationException::withMessages(['to_unit_id' => 'Já há uma transferência em andamento para esta internação.']);
            }
            $transfer = AdmissionTransfer::query()->create([
                'admission_id' => $locked->id, 'from_unit_id' => $locked->unit_id, 'from_bed_id' => $locked->bed_id, 'to_unit_id' => $toUnit,
                'requested_sector_id' => (int) $data['requested_sector_id'], 'responsible_professional_id' => (int) $data['responsible_professional_id'],
                'requested_by_user_id' => $request->user()->id, 'requested_at' => now(),
            ]);
            self::audit('transfer.requested', $transfer);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transferência solicitada. A gestão de leitos do destino foi avisada.']);

        return back();
    }

    /**
     * The destination's bed management reserves a free bed (justification outside the sex/isolation rule).
     */
    public function accept(Request $request, AdmissionTransfer $transfer): RedirectResponse
    {
        self::authorizeUnit($request, $transfer->to_unit_id);
        $data = $request->validate(['bed_id' => ['required', 'integer'], 'compatibility_justification' => ['nullable', 'string', 'max:500']], [], ['bed_id' => 'leito', 'compatibility_justification' => 'justificativa']);

        self::step($transfer, 'requested', function (AdmissionTransfer $locked, Admission $admission) use ($data, $request) {
            self::responsibleStillValid($locked);
            $bed = Bed::query()->whereKey((int) $data['bed_id'])->where('unit_id', $locked->to_unit_id)->whereNull('deactivated_at')->first()
                ?? throw ValidationException::withMessages(['bed_id' => 'Escolha um leito livre desta unidade.']);
            $mismatches = AdmissionController::mismatches($bed, Patient::query()->findOrFail($admission->patient_id), $admission->isolation);
            $justification = trim((string) ($data['compatibility_justification'] ?? ''));
            if ($mismatches !== [] && $justification === '') {
                throw ValidationException::withMessages(['compatibility_justification' => 'Leito fora da regra ('.implode('; ', $mismatches).'). Registre a justificativa para reservar mesmo assim.']);
            }
            self::bedTo($bed, 'free', 'reserved', 'O leito deixou de estar livre nesse meio-tempo. Escolha outro.');
            $locked->forceFill([
                'status' => 'accepted', 'reserved_bed_id' => $bed->id, 'compatibility_justification' => $mismatches === [] ? null : $justification,
                'decided_by_user_id' => $request->user()->id, 'decided_at' => now(),
            ])->save();
        });

        return self::done('Transferência aceita. Leito reservado.');
    }

    public function refuse(Request $request, AdmissionTransfer $transfer): RedirectResponse
    {
        self::authorizeUnit($request, $transfer->to_unit_id);
        $reason = self::reason($request);
        self::step($transfer, 'requested', function (AdmissionTransfer $locked) use ($reason, $request) {
            $locked->forceFill(['status' => 'refused', 'reason' => $reason, 'decided_by_user_id' => $request->user()->id, 'decided_at' => now()])->save();
        });

        return self::done('Transferência recusada.');
    }

    /**
     * Before departure: the requester or the origin's bed management; a reserved bed goes back to free.
     */
    public function cancel(Request $request, AdmissionTransfer $transfer): RedirectResponse
    {
        // The origin's bed management, or whoever is the responsible doctor now (not a former one).
        $origin = in_array($transfer->from_unit_id, BedController::managedUnitIds(UnitPolicy::currentMembership($request->user())), true);
        $responsible = Admission::query()->whereKey($transfer->admission_id)->value('responsible_professional_id') === ClinicalAccess::professional($request->user())?->id;
        abort_unless($origin || $responsible, 403);
        $reason = self::reason($request);
        self::step($transfer, ['requested', 'accepted'], function (AdmissionTransfer $locked) use ($reason) {
            if ($locked->reserved_bed_id !== null) {
                self::bedTo(Bed::query()->findOrFail($locked->reserved_bed_id), 'reserved', 'free', 'O leito reservado mudou de estado. Confira o mapa de leitos.');
            }
            // A cancelled transfer keeps no reservation: the bed is free again.
            $locked->forceFill(['status' => 'cancelled', 'reason' => $reason, 'reserved_bed_id' => null])->save();
        });

        return self::done('Transferência cancelada.');
    }

    /**
     * The origin's bed management records the departure: the bed goes to cleaning, the patient is in transit.
     */
    public function depart(Request $request, AdmissionTransfer $transfer): RedirectResponse
    {
        self::authorizeUnit($request, $transfer->from_unit_id);
        self::step($transfer, 'accepted', function (AdmissionTransfer $locked, Admission $admission) use ($request) {
            if ($admission->status !== 'admitted' || $admission->bed_id !== $locked->from_bed_id) {
                throw ValidationException::withMessages(['transfer' => 'O paciente mudou de leito depois do pedido. Cancele e peça a transferência de novo.']);
            }
            self::bedTo(Bed::query()->findOrFail($locked->from_bed_id), 'occupied', 'cleaning', 'O leito de origem deixou de estar ocupado. Confira o mapa de leitos.');
            $admission->forceFill(['status' => 'in_transit', 'bed_id' => null])->save();
            $locked->forceFill(['status' => 'departed', 'departed_by_user_id' => $request->user()->id, 'departed_at' => now()])->save();
        });

        return self::done('Saída registrada. O paciente está em transferência.');
    }

    /**
     * The destination's bed management receives the patient: the same admission now belongs to the
     * destination (unit, sector, bed, responsible), recorded in the history.
     */
    public function receive(Request $request, AdmissionTransfer $transfer): RedirectResponse
    {
        self::authorizeUnit($request, $transfer->to_unit_id);
        self::step($transfer, 'departed', function (AdmissionTransfer $locked, Admission $admission) use ($request) {
            self::responsibleStillValid($locked);
            $bed = Bed::query()->findOrFail($locked->reserved_bed_id);
            self::bedTo($bed, 'reserved', 'occupied', 'O leito reservado mudou de estado. Confira o mapa de leitos.');
            $previousResponsible = $admission->responsible_professional_id;
            $admission->forceFill([
                'status' => 'admitted', 'unit_id' => $locked->to_unit_id, 'requested_sector_id' => $bed->sector_id, 'bed_id' => $bed->id,
                'responsible_professional_id' => $locked->responsible_professional_id, 'compatibility_justification' => $locked->compatibility_justification,
            ])->save();
            AdmissionController::movement($admission, ['kind' => 'bed', 'from_bed_id' => $locked->from_bed_id, 'to_bed_id' => $bed->id, 'reason' => $locked->compatibility_justification]);
            if ($previousResponsible !== $locked->responsible_professional_id) {
                AdmissionController::movement($admission, ['kind' => 'responsible', 'from_professional_id' => $previousResponsible, 'to_professional_id' => $locked->responsible_professional_id, 'reason' => 'Transferência entre unidades']);
            }
            $locked->forceFill(['status' => 'completed', 'received_by_user_id' => $request->user()->id, 'received_at' => now()])->save();
        });

        return self::done('Paciente recebido e internado nesta unidade.');
    }

    /**
     * Locks the admission, then the transfer, checks its state and runs the step; then the audit event.
     *
     * @param  string|list<string>  $from
     * @param  callable(AdmissionTransfer, Admission): void  $apply
     */
    private static function step(AdmissionTransfer $transfer, string|array $from, callable $apply): void
    {
        DB::transaction(function () use ($transfer, $from, $apply) {
            $admission = Admission::query()->whereKey($transfer->admission_id)->lockForUpdate()->firstOrFail();
            $locked = AdmissionTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, (array) $from, true)) {
                throw ValidationException::withMessages(['transfer' => 'A transferência mudou nesse meio-tempo. Confira a lista atualizada.']);
            }
            $apply($locked, $admission);
            self::audit('transfer.'.$locked->status, $locked);
        });
    }

    /**
     * The destination's responsible may have lost the qualification or the account since the request.
     */
    private static function responsibleStillValid(AdmissionTransfer $transfer): void
    {
        if (! in_array($transfer->responsible_professional_id, array_column(AdmissionController::requestOptions($transfer->to_unit_id)['professionals'], 'id'), true)) {
            throw ValidationException::withMessages(['transfer' => 'O médico responsável escolhido no destino não está mais habilitado. Cancele e peça a transferência de novo.']);
        }
    }

    private static function bedTo(Bed $bed, string $from, string $to, string $message): void
    {
        $changed = Bed::query()->whereKey($bed->id)->whereNull('deactivated_at')->where('status', $from)
            ->update(['status' => $to, 'block_reason' => null, 'status_changed_at' => now(), 'updated_at' => now()]);
        if ($changed === 0) {
            throw ValidationException::withMessages(['bed_id' => $message]);
        }
        BedController::event($bed, $from, $to);
    }

    private static function audit(string $action, AdmissionTransfer $transfer): void
    {
        AuditEvent::record($action, $transfer->tenant_id, $transfer, ['admission_id' => $transfer->admission_id, 'from_unit_id' => $transfer->from_unit_id, 'to_unit_id' => $transfer->to_unit_id]);
    }

    private static function reason(Request $request): string
    {
        return trim((string) $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo'])['reason']);
    }

    private static function authorizeUnit(Request $request, int $unitId): void
    {
        abort_unless(in_array($unitId, BedController::managedUnitIds(UnitPolicy::currentMembership($request->user())), true), 403);
    }

    private static function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
