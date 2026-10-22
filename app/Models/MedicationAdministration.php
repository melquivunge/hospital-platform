<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One record of the ward team for a scheduled dose, or for a dose of an "if needed" item (step 4b.4,
 * ADR-0005). Never changed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $prescription_item_id
 * @property int|null $prescription_dose_id
 * @property string $outcome
 * @property Carbon $occurred_at
 * @property string|null $reason
 * @property bool $exception
 * @property int|null $witness_user_id
 * @property int $recorded_by_user_id
 * @property Carbon $created_at
 */
#[Fillable(['prescription_item_id', 'prescription_dose_id', 'outcome', 'occurred_at', 'reason', 'exception', 'witness_user_id', 'recorded_by_user_id', 'created_at'])]
class MedicationAdministration extends Model
{
    use BelongsToTenant;

    public const OUTCOMES = ['administered' => 'Administrado', 'refused' => 'Recusado pelo paciente', 'not_done' => 'Não realizado'];

    /** A dose not recorded this long after its time is late (computed, never stored). */
    public const LATE_MINUTES = 30;

    /** A dose is recorded from this long before its time. */
    public const EARLY_MINUTES = 60;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime', 'exception' => 'boolean'];
    }
}
