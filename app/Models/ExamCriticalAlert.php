<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The alert of a critical result (step 5.5): acknowledged once by whoever ordered it or attends the patient, and
 * escalated once to the unit manager without that in 1 hour. Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $exam_result_id
 * @property int $unit_id
 * @property Carbon $created_at
 * @property Carbon|null $acknowledged_at
 * @property int|null $acknowledged_by_user_id
 * @property Carbon|null $escalated_at
 */
#[Fillable(['exam_result_id', 'unit_id', 'created_at', 'acknowledged_at', 'acknowledged_by_user_id', 'escalated_at'])]
class ExamCriticalAlert extends Model
{
    use BelongsToTenant;

    /** Without an acknowledgement after this many minutes, the unit manager hears it. */
    public const ESCALATE_MINUTES = 60;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'acknowledged_at' => 'datetime', 'escalated_at' => 'datetime'];
    }
}
