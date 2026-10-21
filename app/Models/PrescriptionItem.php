<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $prn_condition
 * @property Carbon $starts_at
 * @property string|null $notes
 * @property list<string> $alerts
 * @property string|null $justification
 * @property Carbon $created_at
 */
#[Fillable(['prescription_id', 'medication_id', 'medication_label', 'dose', 'dose_unit', 'route', 'frequency_hours', 'times', 'prn', 'prn_condition', 'starts_at', 'notes', 'alerts', 'justification', 'created_at'])]
class PrescriptionItem extends Model
{
    use BelongsToTenant;

    public const FREQUENCIES = [1, 2, 3, 4, 6, 8, 12, 24];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dose' => 'decimal:3', 'frequency_hours' => 'integer', 'times' => 'array', 'prn' => 'boolean',
            'starts_at' => 'datetime', 'alerts' => 'array', 'created_at' => 'datetime',
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
     * @return BelongsTo<Medication, $this>
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }
}
