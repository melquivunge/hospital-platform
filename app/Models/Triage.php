<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Risk classification (step 4.2): level on the network's 5-level scale, chief complaint and vital signs.
 * Insert-only; a reclassification points to the previous one.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $episode_id
 * @property int|null $previous_id
 * @property int $level
 * @property string $complaint
 * @property int $professional_id
 * @property Carbon $created_at
 */
#[Fillable(['episode_id', 'previous_id', 'level', 'complaint', 'systolic', 'diastolic', 'heart_rate', 'respiratory_rate', 'temperature', 'spo2', 'pain', 'glucose', 'professional_id', 'user_id', 'created_at'])]
class Triage extends Model
{
    use BelongsToTenant;

    public const VITALS = [
        'systolic' => [30, 300], 'diastolic' => [10, 200], 'heart_rate' => [10, 300], 'respiratory_rate' => [2, 80],
        'temperature' => [25, 45], 'spo2' => [30, 100], 'pain' => [0, 10], 'glucose' => [10, 1500],
    ];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'level' => 'integer'];
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
