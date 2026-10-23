<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionDispensing;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionReview;
use App\Models\RoleAssignment;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Visit;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pharmacy review (step 4b.3a, ADR-0005). The unit's Pharmacy role sees a queue of the valid prescriptions of
 * the unit (identification and counts only, no read logged) and opens one at a time: only the prescription,
 * the allergies and the patient's identification, each opening logged with reason "pharmacy". Each item is
 * approved, approved with a note or refused with a reason, once; a refusal notifies the prescriber. An approved
 * item is dispensed once for the 24 hours (step 4b.3b), and what comes back is recorded as its return, once, up to
 * 48 hours after the dispensing: also after a renewal or a discharge, when the prescription leaves the queue.
 * Step 4c.2 (ADR-0007): the dispensing takes from the unit's stock lots and the return puts back into them.
 */
class PharmacyController extends Controller
{
    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(self::unitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', (int) $request->query('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';

        $queue = self::valid()->where(fn (Builder $query) => $query
            ->whereIn('admission_id', Admission::query()->where('unit_id', $unit->id)->whereIn('status', Admission::ACTIVE)->select('id'))
            ->orWhereIn('visit_id', Visit::query()->where('unit_id', $unit->id)->whereNull('closed_at')->select('id')))
            ->withCount(['items as pending' => fn (Builder $items) => $items->whereNull('suspended_at')->whereDoesntHave('review')])
            ->with(['admission.patient:id,name,social_name', 'admission.bed:id,code,room,sector_id', 'admission.bed.sector:id,name', 'visit.patient:id,name,social_name'])
            ->orderByDesc('pending')->orderBy('signed_at')->get();
        // Out of the queue (replaced, expired or the encounter closed) but with a dispensing still to be returned.
        $returns = Prescription::query()->where('unit_id', $unit->id)->whereKeyNot($queue->modelKeys())
            ->whereHas('items', fn (Builder $items) => $items->whereIn('id', self::returnable()->select('prescription_item_id')))
            ->with(['admission.patient:id,name,social_name', 'admission.bed:id,code,room,sector_id', 'admission.bed.sector:id,name', 'visit.patient:id,name,social_name'])
            ->orderByDesc('signed_at')->get();
        $row = fn (Prescription $prescription) => [
            'id' => $prescription->id,
            'patient' => ($patient = ($prescription->admission ?? $prescription->visit)?->patient) === null ? null : ($patient->social_name ?? $patient->name),
            'place' => $prescription->admission !== null ? (WardController::bedLabel($prescription->admission) ?? (self::open($prescription->admission) ? 'Aguardando leito' : 'Internação')) : 'Urgência',
            'signedAt' => $prescription->signed_at?->setTimezone($timezone)->format('d/m H:i'),
        ];

        return Inertia::render('pharmacy/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'prescriptions' => $queue->map(fn (Prescription $prescription) => [...$row($prescription), 'pending' => (int) $prescription->getAttribute('pending')])->values(),
            'returns' => $returns->map($row)->values(),
        ]);
    }

    public function show(Request $request, Prescription $prescription): Response
    {
        [$encounter, $patient, $current] = self::access($request, $prescription);
        ClinicalAccess::recordRead($request->user(), $patient, $prescription);
        Inertia::encryptHistory();
        $timezone = Unit::query()->whereKey($encounter->unit_id)->value('timezone') ?? 'UTC';
        $prescription->load(['items.review', 'items.dispensing', 'professional:id,name']);
        $doses = PrescriptionController::doses($prescription->items->modelKeys(), $timezone, withRecords: false);
        // Step 4c.2: the valid lots with balance, the ones that expire first first (the suggestion).
        $lots = $current ? StockLot::query()->where('unit_id', $prescription->unit_id)->whereIn('medication_id', $prescription->items->pluck('medication_id')->all())
            ->where('expires_on', '>=', now($timezone)->toDateString())->where('balance', '>', 0)->orderBy('expires_on')->orderBy('id')
            ->get()->groupBy('medication_id') : collect();
        $stockUnits = Medication::query()->whereKey($prescription->items->pluck('medication_id')->all())->pluck('stock_unit', 'id');

        return Inertia::render('pharmacy/show', [
            'prescription' => [
                'id' => $prescription->id,
                'place' => $encounter instanceof Admission ? (WardController::bedLabel($encounter->load('bed.sector')) ?? 'Aguardando leito') : 'Urgência',
                'signedAt' => $prescription->signed_at?->setTimezone($timezone)->format('d/m/Y H:i'),
                'validUntil' => $prescription->valid_until?->setTimezone($timezone)->format('d/m/Y H:i'),
                'author' => $prescription->professional?->name,
                'unitId' => $encounter->unit_id,
                // Out of the queue, only the returns are recorded.
                'current' => $current,
            ],
            // ADR-0005: identification, age and sex; not the birth date.
            'patient' => ['name' => $patient->social_name ?? $patient->name, 'age' => $patient->birth_date?->age, 'sex' => $patient->sex],
            'allergies' => PatientAllergyController::listing(ClinicalAccess::recordIds($patient), $timezone),
            'items' => array_map(fn (PrescriptionItem $item) => [
                ...PrescriptionController::describe($item, $timezone),
                // The times only: the administration records are not the pharmacy's (ADR-0005).
                'doses' => $doses[$item->id]['doses'] ?? [],
                'given' => [],
                'medicationId' => $item->medication_id,
                'stockUnit' => $stockUnits[$item->medication_id] ?? 'unidade',
                'lots' => ($lots[$item->medication_id] ?? collect())->map(fn (StockLot $lot) => [
                    'id' => $lot->id, 'code' => $lot->lot_code, 'expiresOn' => $lot->expires_on->format('d/m/Y'), 'balance' => $lot->balance,
                ])->values(),
                'returnable' => $item->dispensing !== null && $item->dispensing->returned_at === null
                    && $item->dispensing->created_at->gt(now()->subHours(PrescriptionDispensing::RETURN_HOURS)),
            ], $prescription->items->all()),
            'decisions' => PrescriptionReview::DECISIONS,
            'today' => now($timezone)->toDateString(),
        ]);
    }

    public function review(Request $request, PrescriptionItem $item): RedirectResponse
    {
        $prescription = Prescription::query()->findOrFail($item->prescription_id);
        [$encounter, , $current] = self::access($request, $prescription);
        abort_unless($current, 403);
        $bag = "review{$item->id}";
        $data = $request->validateWithBag($bag, [
            'decision' => ['required', Rule::in(array_keys(PrescriptionReview::DECISIONS))],
            'note' => ['exclude_if:decision,approved', 'required', 'string', 'max:500'],
        ], ['note.required' => 'Escreva a observação ou o motivo da recusa.'], ['decision' => 'decisão', 'note' => 'observação']);

        try {
            DB::transaction(function () use ($item, $request, $data, $prescription, $encounter, $bag) {
                // Lock order of the suspension: the encounter, then the item. A prescription replaced or an
                // encounter closed in between is seen here, and a suspended item is not reviewed.
                self::lockCurrent($encounter, $prescription);
                $locked = PrescriptionItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                if ($locked->suspended_at !== null) {
                    throw ValidationException::withMessages(['decision' => 'Este item foi suspenso pelo médico.'])->errorBag($bag);
                }
                $review = PrescriptionReview::query()->create([
                    'prescription_item_id' => $locked->id, 'decision' => $data['decision'], 'note' => $data['note'] ?? null,
                    'reviewed_by_user_id' => $request->user()->id, 'created_at' => now(),
                ]);
                AuditEvent::record('prescription_item.reviewed', $review->tenant_id, $locked, [
                    'decision' => $review->decision, 'prescription_id' => $prescription->id, 'author_user_id' => $prescription->author_user_id,
                    ...($encounter instanceof Admission ? ['admission_id' => $encounter->id] : ['visit_id' => $encounter->id]),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['decision' => 'Este item já foi avaliado.'])->errorBag($bag);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $data['decision'] === 'refused' ? 'Item recusado. O médico foi avisado.' : 'Avaliação registrada.']);

        return back();
    }

    public function dispense(Request $request, PrescriptionItem $item): RedirectResponse
    {
        $prescription = Prescription::query()->findOrFail($item->prescription_id);
        [$encounter, , $current] = self::access($request, $prescription);
        abort_unless($current, 403);
        $bag = "dispense{$item->id}";
        $data = $request->validateWithBag($bag, [
            'quantity' => ['required', 'integer', 'min:1', 'max:500'],
            // Step 4c.2: the lots the pharmacy chose (a zero is a lot left out); without them, the suggestion.
            'lots' => ['sometimes', 'array', 'max:20'],
            'lots.*.id' => ['required', 'integer'],
            'lots.*.quantity' => ['required', 'integer', 'min:0', 'max:500'],
        ], [], ['quantity' => 'quantidade', 'lots' => 'lotes']);
        $chosen = [];
        foreach ($data['lots'] ?? [] as $lot) {
            if (isset($chosen[(int) $lot['id']])) {
                throw ValidationException::withMessages(['quantity' => 'Um lote aparece duas vezes.'])->errorBag($bag);
            }
            $chosen[(int) $lot['id']] = (int) $lot['quantity'];
        }
        $chosen = array_filter($chosen);
        // A choice sent is the choice: all at zero is no lot, not the suggestion.
        if (isset($data['lots']) && array_sum($chosen) !== (int) $data['quantity']) {
            throw ValidationException::withMessages(['quantity' => 'A soma dos lotes tem que ser a quantidade dispensada.'])->errorBag($bag);
        }

        try {
            DB::transaction(function () use ($item, $request, $data, $prescription, $encounter, $bag, $chosen) {
                self::lockCurrent($encounter, $prescription);
                $locked = PrescriptionItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                $decision = PrescriptionReview::query()->where('prescription_item_id', $locked->id)->value('decision');
                $refusal = match (true) {
                    PrescriptionDispensing::query()->where('prescription_item_id', $locked->id)->exists() => 'Este item já foi dispensado.',
                    $locked->suspended_at !== null => 'Este item foi suspenso pelo médico.',
                    $decision === null || $decision === 'refused' => 'Só se dispensa item aprovado pela farmácia.',
                    default => null,
                };
                if ($refusal !== null) {
                    throw ValidationException::withMessages(['quantity' => $refusal])->errorBag($bag);
                }
                $taken = self::takeFromLots((int) $prescription->unit_id, $locked->medication_id, (int) $data['quantity'], $chosen, $bag);
                $dispensing = PrescriptionDispensing::query()->create([
                    'prescription_item_id' => $locked->id, 'quantity' => $data['quantity'], 'dispensed_by_user_id' => $request->user()->id, 'created_at' => now(),
                ]);
                foreach ($taken as $lotId => $quantity) {
                    StockMovement::query()->create([
                        'stock_lot_id' => $lotId, 'type' => 'dispensing', 'quantity' => -$quantity, 'user_id' => $request->user()->id,
                        'prescription_dispensing_id' => $dispensing->id, 'created_at' => now(),
                    ]);
                }
                AuditEvent::record('prescription_item.dispensed', $dispensing->tenant_id, $locked, [
                    'quantity' => $dispensing->quantity, 'prescription_id' => $prescription->id, 'stock_lots' => $taken,
                    ...($encounter instanceof Admission ? ['admission_id' => $encounter->id] : ['visit_id' => $encounter->id]),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['quantity' => 'Este item já foi dispensado.'])->errorBag($bag);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Dispensação registrada.']);

        return back();
    }

    public function returnItem(Request $request, PrescriptionDispensing $dispensing): RedirectResponse
    {
        $item = PrescriptionItem::query()->findOrFail($dispensing->prescription_item_id);
        $prescription = Prescription::query()->findOrFail($item->prescription_id);
        [$encounter] = self::access($request, $prescription);
        $bag = "return{$dispensing->id}";
        $data = $request->validateWithBag($bag, [
            'quantity' => ['required', 'integer', 'min:1', "max:{$dispensing->quantity}"],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['quantity' => 'quantidade', 'reason' => 'motivo']);

        DB::transaction(function () use ($dispensing, $request, $data, $prescription, $encounter, $bag) {
            $locked = PrescriptionDispensing::query()->whereKey($dispensing->id)->lockForUpdate()->firstOrFail();
            $refusal = match (true) {
                $locked->returned_at !== null => 'A devolução deste item já foi registrada.',
                $locked->created_at->lte(now()->subHours(PrescriptionDispensing::RETURN_HOURS)) => 'O prazo de devolução desta dispensação terminou.',
                default => null,
            };
            if ($refusal !== null) {
                throw ValidationException::withMessages(['quantity' => $refusal])->errorBag($bag);
            }
            // Step 4c.2: back into the lots the dispensing took from, up to what each gave, the longest expiry first.
            // A dispensing from before the stock has no movement, and its return none either.
            $given = StockMovement::query()->where('prescription_dispensing_id', $locked->id)->where('type', 'dispensing')->get();
            $lots = StockLot::query()->whereKey($given->pluck('stock_lot_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $remaining = (int) $data['quantity'];
            $back = [];
            foreach ($given->sortByDesc(fn (StockMovement $movement) => $lots[$movement->stock_lot_id]->expires_on->toDateString().sprintf('%020d', $movement->stock_lot_id)) as $movement) {
                $quantity = min(-$movement->quantity, $remaining);
                if ($quantity > 0) {
                    StockMovement::query()->create([
                        'stock_lot_id' => $movement->stock_lot_id, 'type' => 'return', 'quantity' => $quantity, 'user_id' => $request->user()->id,
                        'prescription_dispensing_id' => $locked->id, 'created_at' => now(),
                    ]);
                    $back[$movement->stock_lot_id] = $quantity;
                    $remaining -= $quantity;
                }
            }
            $locked->forceFill([
                'returned_quantity' => $data['quantity'], 'return_reason' => $data['reason'], 'returned_by_user_id' => $request->user()->id, 'returned_at' => now(),
            ])->save();
            // The reason may be clinical: only the quantities go to the audit.
            AuditEvent::record('prescription_item.returned', $locked->tenant_id, $locked, [
                'quantity' => $locked->returned_quantity, 'prescription_id' => $prescription->id, 'stock_lots' => $back,
                ...($encounter instanceof Admission ? ['admission_id' => $encounter->id] : ['visit_id' => $encounter->id]),
            ]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Devolução registrada.']);

        return back();
    }

    /**
     * The units where the membership has the Pharmacy role.
     *
     * @return list<int>
     */
    public static function unitIds(?Membership $membership): array
    {
        if ($membership === null || $membership->revoked_at !== null) {
            return [];
        }

        return array_values(array_unique($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => $assignment->role === Role::Pharmacy)
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)->all()));
    }

    /**
     * Under the transaction, after the item (ADR-0007): the lots of the medication in the unit, locked in id order,
     * and what to take from each: the pharmacy's choice, or else the valid lots that expire first. An expired lot,
     * a lot of another unit or medication, or a balance short of the quantity is refused.
     *
     * @param  array<int, int>  $chosen  lot id => quantity
     * @return array<int, int> lot id => quantity
     */
    private static function takeFromLots(int $unitId, int $medicationId, int $quantity, array $chosen, string $bag): array
    {
        $today = now(Unit::query()->whereKey($unitId)->value('timezone') ?? 'UTC')->toDateString();
        // FOR UPDATE re-reads a lot changed by a dispensing that committed while this one waited.
        $lots = StockLot::query()->where(['unit_id' => $unitId, 'medication_id' => $medicationId])
            ->when($chosen !== [], fn (Builder $query) => $query->whereKey(array_keys($chosen)), fn (Builder $query) => $query->where('expires_on', '>=', $today)->where('balance', '>', 0))
            ->orderBy('id')->lockForUpdate()->get();
        if ($chosen === []) {
            $remaining = $quantity;
            foreach ($lots->sortBy(fn (StockLot $lot) => $lot->expires_on->toDateString().sprintf('%020d', $lot->id)) as $lot) {
                $take = min($lot->balance, $remaining);
                if ($take > 0) {
                    $chosen[$lot->id] = $take;
                    $remaining -= $take;
                }
            }
            if ($remaining > 0) {
                throw ValidationException::withMessages(['quantity' => $lots->isEmpty()
                    ? 'Sem saldo deste medicamento no estoque da unidade. Registre a entrada do lote.'
                    : "Saldo insuficiente: há só {$lots->sum('balance')} no estoque da unidade."])->errorBag($bag);
            }

            return $chosen;
        }
        $lots = $lots->keyBy('id');
        foreach ($chosen as $id => $take) {
            $lot = $lots->get($id);
            $refusal = match (true) {
                $lot === null => 'Lote fora do estoque deste medicamento na unidade.',
                $lot->expires_on->toDateString() < $today => "O lote {$lot->lot_code} venceu e não é dispensado.",
                $lot->balance < $take => "O lote {$lot->lot_code} tem só {$lot->balance} em estoque.",
                default => null,
            };
            if ($refusal !== null) {
                throw ValidationException::withMessages(['quantity' => $refusal])->errorBag($bag);
            }
        }

        return $chosen;
    }

    /**
     * Signed, still valid and the newest of its encounter.
     *
     * @return Builder<Prescription>
     */
    private static function valid(): Builder
    {
        return Prescription::query()->where('status', 'signed')->where('valid_until', '>', now())
            // Step 4b.6: a prescription of the unit the admission is in now (a transfer ends the origin's one).
            ->where(fn ($unit) => $unit->whereNull('admission_id')->orWhereIn('admission_id', Admission::query()->whereColumn('admissions.unit_id', 'prescriptions.unit_id')->select('id')))
            ->whereNotExists(fn ($newer) => $newer->from('prescriptions as newer')->where('newer.status', 'signed')
                ->where(fn ($same) => $same->whereColumn('newer.admission_id', 'prescriptions.admission_id')->orWhereColumn('newer.visit_id', 'prescriptions.visit_id'))
                ->where(fn ($later) => $later->whereColumn('newer.signed_at', '>', 'prescriptions.signed_at')
                    ->orWhere(fn ($tie) => $tie->whereColumn('newer.signed_at', 'prescriptions.signed_at')->whereColumn('newer.id', '>', 'prescriptions.id'))));
    }

    /**
     * Dispensings whose return may still be recorded.
     *
     * @return Builder<PrescriptionDispensing>
     */
    private static function returnable(): Builder
    {
        return PrescriptionDispensing::query()->whereNull('returned_at')->where('created_at', '>', now()->subHours(PrescriptionDispensing::RETURN_HOURS));
    }

    private static function open(Admission|Visit $encounter): bool
    {
        return $encounter instanceof Admission ? in_array($encounter->status, Admission::ACTIVE, true) : $encounter->closed_at === null;
    }

    /**
     * Under a transaction, in the lock order of the suspension (the encounter, then the item): a prescription
     * replaced or an encounter closed in between is seen here.
     */
    private static function lockCurrent(Admission|Visit $encounter, Prescription $prescription): void
    {
        $locked = ($encounter instanceof Admission ? Admission::query() : Visit::query())->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
        abort_unless(self::open($locked) && self::valid()->whereKey($prescription->id)->exists(), 403);
    }

    /**
     * The encounter, the patient and whether the prescription is current (the valid one, the encounter open), if
     * the user has the Pharmacy role in its unit (ADR-0005) and it is current or has a dispensing still to be
     * returned; otherwise 403.
     *
     * @return array{Admission|Visit, Patient, bool}
     */
    private static function access(Request $request, Prescription $prescription): array
    {
        abort_unless(in_array((int) $prescription->unit_id, self::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $encounter = $prescription->admission_id !== null
            ? Admission::query()->findOrFail($prescription->admission_id)
            : Visit::query()->findOrFail($prescription->visit_id);
        $current = self::open($encounter) && self::valid()->whereKey($prescription->id)->exists();
        abort_unless($current || self::returnable()->whereIn('prescription_item_id', PrescriptionItem::query()->where('prescription_id', $prescription->id)->select('id'))->exists(), 403);

        return [$encounter, Patient::query()->findOrFail($encounter->patient_id), $current];
    }
}
