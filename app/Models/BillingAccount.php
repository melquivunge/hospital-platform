<?php

namespace App\Models;

use App\Billing\SusRules;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A patient's account (step 6.2a, ADR-0010): one per consultation or per emergency episode, opened on the arrival
 * with the stay's modality and card. An episode that becomes an admission keeps its account (user's decision,
 * 25/10/2026): what was done in the emergency and in the admission is billed together.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $patient_id
 * @property int|null $appointment_id
 * @property int|null $emergency_episode_id
 * @property string $status open | closed (step 6.4a: immutable in the database)
 * @property string|null $modality private | insurance | sus; null for an episode from before step 6.2a0
 * @property int|null $insurer_id
 * @property string|null $insurance_card_number
 * @property Carbon|null $insurance_card_valid_until
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property int|null $closed_by_user_id
 * @property int|null $complement_of_id the account it complements (step 6.4a): what the stay produced after it closed
 */
class BillingAccount extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * Opens the account of a consultation or an emergency episode on its arrival (inside its transaction).
     */
    public static function open(Appointment|EmergencyEpisode $stay): self
    {
        $account = new self;
        $account->forceFill([
            $stay instanceof Appointment ? 'appointment_id' : 'emergency_episode_id' => $stay->id,
            'unit_id' => $stay->unit_id, 'patient_id' => $stay->patient_id, 'modality' => $stay->modality, 'insurer_id' => $stay->insurer_id,
            'insurance_card_number' => $stay->insurance_card_number, 'insurance_card_valid_until' => $stay->insurance_card_valid_until, 'opened_at' => now(),
        ])->save();

        return $account;
    }

    /**
     * The account a visit belongs to, if any.
     */
    public static function ofVisit(Visit $visit): ?self
    {
        return $visit->appointment_id !== null
            ? self::query()->where('appointment_id', $visit->appointment_id)->first()
            : self::query()->where('emergency_episode_id', $visit->emergency_episode_id)->first();
    }

    /**
     * The account of the stay an exam order or a surgery belongs to (its visit, or the admission an episode became).
     */
    public static function ofStay(?int $visitId, ?int $admissionId): ?self
    {
        $visit = $visitId === null ? null : Visit::query()->find($visitId);
        if ($visit !== null) {
            return self::ofVisit($visit);
        }
        $episode = $admissionId === null ? null : Admission::query()->whereKey($admissionId)->value('emergency_episode_id');

        return $episode === null ? null : self::query()->where('emergency_episode_id', $episode)->first();
    }

    /**
     * Adds the items of the care records not billed yet (step 6.2a): the closed visit (a consultation, or an emergency
     * one), each released exam of an order and each completed surgery of the stay (its visit or, for an episode, the
     * admission it became); since step 6.2b, each day of the admission by its bed type, the material a surgery of the
     * stay consumed or gave back, and the medication dispensed or returned. A return is a negative quantity at the
     * price of the charge it gives back. Idempotent: run on the visit's close, an exam's first release, a surgery's
     * completion, its materials, a dispensing or return and the discharge (and, from step 6.2c, when billing opens the
     * account); a record already billed is skipped, and the unique origin makes a concurrent run insert nothing twice.
     * Returns how many items were added.
     */
    public function generate(): int
    {
        return DB::transaction(function () {
            // The stay's account (a complementary one runs its account's): its lock serializes runs of the whole family;
            // the unique origin is the guarantee across accounts.
            $account = self::query()->whereKey($this->complement_of_id ?? $this->id)->lockForUpdate()->firstOrFail();
            // A stay without modality (before step 6.2a0) waits for it.
            if (! in_array($account->modality, ['private', 'insurance', 'sus'], true)) {
                return 0;
            }
            // Step 6b.4: the SUS bills the SIGTAP procedures registered in its instrument (BPA or AIH).
            $sus = $account->modality === 'sus' ? SusRules::instrument($account) : null;
            // An emergency still in care may yet become an admission (AIH): its SUS items wait for the outcome, so an
            // item is never billed in the BPA and then kept in the AIH package.
            if ($sus === 'bpa' && $account->emergency_episode_id !== null
                && ! in_array(EmergencyEpisode::query()->whereKey($account->emergency_episode_id)->value('status'), EmergencyEpisode::OUTCOMES, true)) {
                return 0;
            }
            $timezone = Unit::query()->whereKey($account->unit_id)->value('timezone') ?? 'UTC';
            $day = fn (CarbonInterface|string $at) => Carbon::parse($at)->setTimezone($timezone)->toDateString();
            $visits = Visit::query()->where($account->appointment_id !== null ? 'appointment_id' : 'emergency_episode_id', $account->appointment_id ?? $account->emergency_episode_id);
            $admissions = Admission::query()->where('emergency_episode_id', $account->emergency_episode_id ?? 0);
            $ofStay = fn ($query) => $query->whereIn('visit_id', (clone $visits)->select('id'))->orWhereIn('admission_id', (clone $admissions)->select('id'));
            $surgeries = Surgery::query()->where($ofStay);

            // [origin type, origin id, billable kind, its reference, name without a billable item, day, quantity, price day?]
            $records = [];
            foreach ((clone $visits)->whereNotNull('closed_at')->get() as $visit) {
                $kind = $visit->emergency_episode_id === null ? 'consultation' : 'emergency_consultation';
                $records[] = ['visit', $visit->id, $kind, [], BillingItem::KINDS[$kind], $day($visit->closed_at), 1];
            }
            // Released: the first version of the result exists; a rectification bills nothing more.
            $exams = ExamOrderItem::query()->whereIn('exam_order_id', ExamOrder::query()->where($ofStay)->select('id'))
                ->joinSub(ExamResult::query()->selectRaw('exam_order_item_id, min(released_at) as released_at')->groupBy('exam_order_item_id'), 'released', 'released.exam_order_item_id', '=', 'exam_order_items.id')
                ->get(['exam_order_items.id', 'exam_order_items.exam_id', 'exam_order_items.exam_name', 'released.released_at']);
            foreach ($exams as $exam) {
                $records[] = ['exam_order_item', $exam->id, 'exam', ['exam_id' => $exam->exam_id], $exam->exam_name, $day($exam->getAttribute('released_at')), 1];
            }
            foreach ((clone $surgeries)->where('status', 'completed')->get(['id', 'surgical_procedure_id', 'procedure_name', 'completed_at']) as $surgery) {
                $records[] = ['surgery', $surgery->id, 'procedure', ['surgical_procedure_id' => $surgery->surgical_procedure_id], $surgery->procedure_name, $day($surgery->completed_at), 1];
            }
            foreach ((clone $admissions)->whereNotNull('admitted_at')->get() as $admission) {
                foreach (self::admissionDays($admission, $timezone) as $date => $bedType) {
                    $records[] = ['admission_day', $admission->id, 'daily', ['bed_type' => $bedType], 'Diária '.BillingItem::BED_TYPES[$bedType], $date, 1];
                }
            }
            $materials = StockMovement::query()->whereIn('surgery_id', (clone $surgeries)->select('id'))->whereIn('stock_movements.type', ['surgery', 'surgery_return'])
                ->join('stock_lots', 'stock_lots.id', '=', 'stock_movements.stock_lot_id')->join('materials', 'materials.id', '=', 'stock_lots.material_id')
                ->orderBy('stock_movements.id')
                ->get(['stock_movements.id', 'stock_movements.type', 'stock_movements.surgery_id', 'stock_movements.stock_lot_id', 'stock_movements.quantity', 'stock_movements.created_at', 'stock_lots.material_id', 'materials.name']);
            foreach ($materials as $movement) {
                // A consumption takes from the stock (negative there): billed positive; a return, negative, credited at
                // the price of the consumption it gives back (the last one of the surgery from that lot).
                $taken = $movement->type === 'surgery' ? $movement : $materials->last(fn (StockMovement $earlier) => $earlier->type === 'surgery'
                    && $earlier->surgery_id === $movement->surgery_id && $earlier->stock_lot_id === $movement->stock_lot_id && $earlier->id < $movement->id) ?? $movement;
                $records[] = ['stock_movement', $movement->id, 'material', ['material_id' => $movement->getAttribute('material_id')], $movement->getAttribute('name'), $day($movement->created_at), -$movement->quantity, $day($taken->created_at)];
            }
            $dispensings = PrescriptionDispensing::query()->join('prescription_items', 'prescription_items.id', '=', 'prescription_dispensings.prescription_item_id')
                ->whereIn('prescription_items.prescription_id', Prescription::query()->where($ofStay)->select('id'))
                ->get(['prescription_dispensings.id', 'prescription_dispensings.quantity', 'prescription_dispensings.created_at', 'prescription_dispensings.returned_quantity',
                    'prescription_dispensings.returned_at', 'prescription_items.medication_id', 'prescription_items.medication_label']);
            foreach ($dispensings as $dispensing) {
                $medication = ['medication_id' => $dispensing->getAttribute('medication_id')];
                $label = mb_substr((string) $dispensing->getAttribute('medication_label'), 0, 255);
                $records[] = ['dispensing', $dispensing->id, 'medication', $medication, $label, $day($dispensing->created_at), $dispensing->quantity];
                if ($dispensing->returned_quantity > 0) {
                    // Credited at the price it was charged at.
                    $records[] = ['dispensing_return', $dispensing->id, 'medication', $medication, $label, $day($dispensing->returned_at), -$dispensing->returned_quantity, $day($dispensing->created_at)];
                }
            }

            // An admission day is billed once per day; any other record, once.
            $key = fn (string $type, int $id, string $date) => $type === 'admission_day' ? "{$type}:{$id}:{$date}" : "{$type}:{$id}";
            // Manual items and compensations (step 6.3a) are billing's own, not care records.
            $family = self::query()->where('id', $account->id)->orWhere('complement_of_id', $account->id)->pluck('id')->all();
            $billed = BillingAccountItem::query()->whereIn('billing_account_id', $family)->whereNotIn('origin_type', ['manual', 'compensation'])
                ->get(['origin_type', 'origin_id', 'performed_on'])
                ->map(fn (BillingAccountItem $item) => $key($item->origin_type, $item->origin_id, $item->performed_on->toDateString()))->flip();
            $rows = [];
            foreach ($records as $record) {
                // The price is the one of the day it happened, or, for a return, of the charge it gives back.
                [$type, $id, $kind, $reference, $name, $date, $quantity] = $record;
                $priceDay = $record[7] ?? $date;
                if ($billed->has($key($type, $id, $date))) {
                    continue;
                }
                // The billable item of the kind and, for a catalog kind or a daily rate, of its exam, procedure, material,
                // medication or bed type.
                $item = BillingItem::query()->where('kind', $kind)->where('active', true)->where($reference)->first();
                $price = $item === null ? null : InsurerContract::priceFor($account->insurer_id, $item->id, $priceDay);
                $code = $item?->code;
                if ($sus !== null) {
                    // Not billed apart in the SUS: no SIGTAP code, or in the package of the account's instrument. Without the
                    // competence imported it waits (items never change): the account shows it as pending.
                    $procedure = $item?->sigtap_code === null ? null : SusRules::procedure($account->tenant_id, $item->sigtap_code, $priceDay);
                    if ($procedure === null || ! SusRules::registered($procedure, $sus)) {
                        continue;
                    }
                    [$code, $price] = [$procedure->code, SusRules::price($procedure, $sus)];
                }
                $rows[] = [
                    'tenant_id' => $account->tenant_id, 'billing_account_id' => $account->id, 'origin_type' => $type, 'origin_id' => $id,
                    'billing_item_id' => $item?->id, 'code' => $code, 'name' => $item->name ?? $name, 'quantity' => $quantity,
                    'unit_price_cents' => $price,
                    // Step 6.5b2b: an insurance admission's daily rate always carries the insurer's password (the summary requires it).
                    'needs_authorization' => $item !== null && $account->insurer_id !== null && ($type === 'admission_day' || InsurerContract::needsAuthorization($account->insurer_id, $item->id)),
                    'performed_on' => $date, 'created_at' => now(),
                ];
            }

            if ($rows === []) {
                return 0;
            }
            // Step 6.4a: after the account closed, into its open complementary account.
            $target = self::target($account);

            return BillingAccountItem::query()->insertOrIgnore(array_map(fn (array $row) => [...$row, 'billing_account_id' => $target->id], $rows));
        });
    }

    /**
     * Step 6.4a: the account that receives what comes now, under the stay's account lock: itself while open, else its
     * open complementary account, opened when needed with the same patient, unit, modality and card.
     */
    public static function target(self $account): self
    {
        if ($account->status === 'open') {
            return $account;
        }
        $complement = self::query()->where('complement_of_id', $account->id)->where('status', 'open')->first();
        if ($complement !== null) {
            return $complement;
        }
        $complement = new self;
        $complement->forceFill([
            ...$account->only('unit_id', 'patient_id', 'modality', 'insurer_id', 'insurance_card_number', 'insurance_card_valid_until'),
            'complement_of_id' => $account->id, 'opened_at' => now(),
        ])->save();
        AuditEvent::record('billing_account.complement_opened', $complement->tenant_id, $complement, ['unit_id' => $complement->unit_id, 'complement_of_id' => $account->id]);

        return $complement;
    }

    /**
     * Step 6.4a: whether the stay ended (nothing more is expected from it): the consultation's visit closed, or the
     * emergency episode ended and, when it became an admission, the patient discharged. A complementary account's is
     * its account's.
     */
    public function stayEnded(): bool
    {
        $account = $this->complement_of_id === null ? $this : self::query()->findOrFail($this->complement_of_id);
        if ($account->appointment_id !== null) {
            return Visit::query()->where('appointment_id', $account->appointment_id)->whereNotNull('closed_at')->exists();
        }
        if (EmergencyEpisode::query()->whereKey($account->emergency_episode_id)->whereNull('ended_at')->exists()) {
            return false;
        }

        return ! Admission::query()->where('emergency_episode_id', $account->emergency_episode_id)->where('status', '<>', 'discharged')->exists();
    }

    /**
     * The days of an admission to bill, each by the type of the bed it held at the end of that day (step 6.2b): from
     * the day of the admission to the day before the discharge or, while it lasts, to yesterday; a stay that ends on
     * the day it began is one day, by the last bed. In transit between units, the last bed counts.
     *
     * @return array<string, string> day (Y-m-d) => bed type
     */
    public static function admissionDays(Admission $admission, string $timezone): array
    {
        $beds = DB::table('admission_movements')->join('beds', 'beds.id', '=', 'admission_movements.to_bed_id')
            ->where('admission_movements.admission_id', $admission->id)->where('admission_movements.kind', 'bed')
            ->orderBy('admission_movements.occurred_at')->orderBy('admission_movements.id')->get(['admission_movements.occurred_at', 'beds.type']);
        if ($beds->isEmpty() || $admission->admitted_at === null) {
            return [];
        }
        $first = $admission->admitted_at->copy()->setTimezone($timezone)->startOfDay();
        $end = ($admission->discharged_at ?? now())->copy()->setTimezone($timezone)->startOfDay();
        $typeBefore = fn (CarbonInterface $moment) => $beds->last(fn ($bed) => Carbon::parse($bed->occurred_at)->lt($moment))->type ?? $beds->first()->type;

        $days = [];
        // Immutable dates (the application's default): each step is a new value.
        for ($date = $first->toImmutable(); $date->lt($end); $date = $date->addDay()) {
            $days[$date->toDateString()] = $typeBefore($date->addDay());
        }
        if ($days === [] && $admission->discharged_at !== null) {
            $days[$first->toDateString()] = $beds->last()->type;
        }

        return $days;
    }

    /**
     * @return HasMany<BillingAccountItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BillingAccountItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'insurance_card_valid_until' => 'date'];
    }
}
