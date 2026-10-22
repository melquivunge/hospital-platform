<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Item of a prescription (step 4b.2a): either every N hours or at fixed times; "if needed" items need a
 * condition and a minimum interval. The alerts found when it was saved and the justification travel with it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $prescription_id
 * @property int $medication_id
 * @property string $medication_label
 * @property string $dose
 * @property string $dose_unit
 * @property string $route
 * @property int|null $frequency_hours
 * @property list<string>|null $times
 * @property bool $prn
 * @property bool|null $double_check
 * @property string|null $prn_condition
 * @property Carbon $starts_at
 * @property string|null $notes
 * @property list<string> $alerts
 * @property string|null $justification
 * @property Carbon $created_at
 * @property Carbon|null $suspended_at step 4b.2b: suspended once, with a reason
 * @property string|null $suspension_reason
 */
#[Fillable(['prescription_id', 'medication_id', 'medication_label', 'dose', 'dose_unit', 'route', 'frequency_hours', 'times', 'prn', 'prn_condition', 'starts_at', 'notes', 'alerts', 'justification', 'double_check', 'created_at'])]
class PrescriptionItem extends Model
{
    use BelongsToTenant;

    public const FREQUENCIES = [1, 2, 3, 4, 6, 8, 12, 24];

    public $timestamps = false;

    /** Microseconds kept: a suspension and the next draft may fall in the same second (4b.2b alert rule). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dose' => 'decimal:3', 'frequency_hours' => 'integer', 'times' => 'array', 'prn' => 'boolean', 'double_check' => 'boolean',
            'starts_at' => 'datetime', 'alerts' => 'array', 'created_at' => 'datetime', 'suspended_at' => 'datetime',
        ];
    }

    /**
     * Doses in 24 hours: the most an "if needed" item allows, or the fixed times.
     */
    public function dosesPerDay(): int
    {
        return $this->frequency_hours !== null ? intdiv(24, $this->frequency_hours) : count($this->times ?? []);
    }

    /**
     * The doses to schedule from the item's start until the prescription expires (step 4b.2b): every N hours, or
     * the fixed times of each day in the unit's timezone. "If needed" items have none.
     *
     * @return list<CarbonImmutable>
     */
    public function schedule(CarbonImmutable $until, string $timezone): array
    {
        if ($this->prn) {
            return [];
        }
        $start = $this->starts_at->toImmutable();
        $doses = [];
        if ($this->frequency_hours !== null) {
            for ($at = $start; $at->lt($until); $at = $at->addHours($this->frequency_hours)) {
                $doses[] = $at;
            }

            return $doses;
        }
        for ($day = $start->setTimezone($timezone)->startOfDay(); $day->lte($until->setTimezone($timezone)); $day = $day->addDay()) {
            foreach ($this->times ?? [] as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $at = $day->setTime($hour, $minute)->utc();
                if ($at->gte($start) && $at->lt($until)) {
                    $doses[] = $at;
                }
            }
        }

        return $doses;
    }

    /**
     * @return HasOne<PrescriptionReview, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(PrescriptionReview::class);
    }

    /**
     * @return HasOne<PrescriptionDispensing, $this>
     */
    public function dispensing(): HasOne
    {
        return $this->hasOne(PrescriptionDispensing::class);
    }

    /**
     * @return BelongsTo<Medication, $this>
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    /**
     * Step 4b.6: a second person at administration, as fixed when prescribed. Items from before have none and read
     * the catalog; an item whose medication is gone needs one (fails closed).
     */
    public function needsDoubleCheck(): bool
    {
        if ($this->double_check !== null) {
            return $this->double_check;
        }
        $medication = Medication::query()->find($this->medication_id);

        return $medication === null || $medication->controlled || $medication->high_alert;
    }
}
