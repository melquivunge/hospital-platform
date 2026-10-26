<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
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
 * @property string $status open
 * @property string|null $modality private | insurance | sus; null for an episode from before step 6.2a0
 * @property int|null $insurer_id
 * @property string|null $insurance_card_number
 * @property Carbon|null $insurance_card_valid_until
 * @property Carbon $opened_at
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
     * Adds the items of the care records not billed yet: the closed visit (a consultation, or an emergency one), each
     * released exam of an order and each completed surgery of the stay (its visit or, for an episode, the admission it
     * became). Idempotent: run on the visit's close, the first release of an exam's result, a surgery's completion and the
     * discharge (and, from step 6.2c, when billing opens the account); a record
     * already billed is skipped, and the unique origin makes a concurrent run insert nothing twice. Returns how many
     * items were added.
     */
    public function generate(): int
    {
        return DB::transaction(function () {
            // Serializes runs of the same account; the unique origin is the guarantee across accounts.
            $account = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            // ponytail: SUS has its own codes and files (delivery 6b); a stay without modality waits for it.
            if ($account->status !== 'open' || ! in_array($account->modality, ['private', 'insurance'], true)) {
                return 0;
            }
            $timezone = Unit::query()->whereKey($account->unit_id)->value('timezone') ?? 'UTC';
            $visits = Visit::query()->where($account->appointment_id !== null ? 'appointment_id' : 'emergency_episode_id', $account->appointment_id ?? $account->emergency_episode_id);
            $admissions = Admission::query()->where('emergency_episode_id', $account->emergency_episode_id ?? 0);
            $ofStay = fn ($query) => $query->whereIn('visit_id', (clone $visits)->select('id'))->orWhereIn('admission_id', (clone $admissions)->select('id'));

            $records = [];
            foreach ((clone $visits)->whereNotNull('closed_at')->get() as $visit) {
                $kind = $visit->emergency_episode_id === null ? 'consultation' : 'emergency_consultation';
                $records[] = ['visit', $visit->id, $kind, [], BillingItem::KINDS[$kind], $visit->closed_at];
            }
            // Released: the first version of the result exists; a rectification bills nothing more.
            $exams = ExamOrderItem::query()->whereIn('exam_order_id', ExamOrder::query()->where($ofStay)->select('id'))
                ->joinSub(ExamResult::query()->selectRaw('exam_order_item_id, min(released_at) as released_at')->groupBy('exam_order_item_id'), 'released', 'released.exam_order_item_id', '=', 'exam_order_items.id')
                ->get(['exam_order_items.id', 'exam_order_items.exam_id', 'exam_order_items.exam_name', 'released.released_at']);
            foreach ($exams as $exam) {
                $records[] = ['exam_order_item', $exam->id, 'exam', ['exam_id' => $exam->exam_id], $exam->exam_name, Carbon::parse($exam->getAttribute('released_at'))];
            }
            foreach (Surgery::query()->where($ofStay)->where('status', 'completed')->get(['id', 'surgical_procedure_id', 'procedure_name', 'completed_at']) as $surgery) {
                $records[] = ['surgery', $surgery->id, 'procedure', ['surgical_procedure_id' => $surgery->surgical_procedure_id], $surgery->procedure_name, $surgery->completed_at];
            }

            $billed = BillingAccountItem::query()->where('billing_account_id', $account->id)->get(['origin_type', 'origin_id'])
                ->map(fn (BillingAccountItem $item) => "{$item->origin_type}:{$item->origin_id}")->flip();
            $rows = [];
            foreach ($records as [$type, $id, $kind, $reference, $name, $at]) {
                if ($billed->has("{$type}:{$id}")) {
                    continue;
                }
                // The billable item of the kind and, for a catalog kind, of the exam or procedure.
                $item = BillingItem::query()->where('kind', $kind)->where('active', true)->where($reference)->first();
                $day = $at->copy()->setTimezone($timezone)->toDateString();
                $rows[] = [
                    'tenant_id' => $account->tenant_id, 'billing_account_id' => $account->id, 'origin_type' => $type, 'origin_id' => $id,
                    'billing_item_id' => $item?->id, 'code' => $item?->code, 'name' => $item->name ?? $name, 'quantity' => 1,
                    'unit_price_cents' => $item === null ? null : InsurerContract::priceFor($account->insurer_id, $item->id, $day),
                    'needs_authorization' => $item !== null && $account->insurer_id !== null && InsurerContract::needsAuthorization($account->insurer_id, $item->id),
                    'performed_on' => $day, 'created_at' => now(),
                ];
            }

            return $rows === [] ? 0 : BillingAccountItem::query()->insertOrIgnore($rows);
        });
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
        return ['opened_at' => 'datetime', 'insurance_card_valid_until' => 'date'];
    }
}
