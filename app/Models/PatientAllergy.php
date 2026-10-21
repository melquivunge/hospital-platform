<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Allergy recorded on a patient (step 4b.1c). Insert-only: inactivating keeps the row with who, when and why.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property string $substance
 * @property string $substance_key
 * @property string $severity
 * @property string|null $reaction
 * @property int $recorded_by_user_id
 * @property int $professional_id
 * @property Carbon $created_at
 * @property Carbon|null $inactivated_at
 * @property string|null $inactivation_reason
 */
#[Fillable(['patient_id', 'substance', 'substance_key', 'severity', 'reaction', 'recorded_by_user_id', 'professional_id', 'created_at'])]
class PatientAllergy extends Model
{
    use BelongsToTenant;

    public const SEVERITIES = ['mild' => 'Leve', 'moderate' => 'Moderada', 'severe' => 'Grave', 'unknown' => 'Não informada'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'inactivated_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
