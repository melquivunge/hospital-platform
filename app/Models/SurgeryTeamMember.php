<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A professional on the team of a surgery (step 5b.3a, ADR-0009); removed, never deleted. Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $surgery_id
 * @property int $professional_id
 * @property string $role surgeon | assistant | anesthetist | scrub | circulating
 * @property int $added_by_user_id
 * @property Carbon $added_at
 * @property int|null $removed_by_user_id
 * @property Carbon|null $removed_at
 */
#[Fillable(['tenant_id', 'surgery_id', 'professional_id', 'role', 'added_by_user_id', 'added_at', 'removed_by_user_id', 'removed_at'])]
class SurgeryTeamMember extends Model
{
    use BelongsToTenant;

    public const ROLES = ['surgeon' => 'Cirurgião', 'assistant' => 'Auxiliar', 'anesthetist' => 'Anestesista', 'scrub' => 'Instrumentação', 'circulating' => 'Circulante'];

    public $timestamps = false;

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['added_at' => 'datetime', 'removed_at' => 'datetime'];
    }
}
