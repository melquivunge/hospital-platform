<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Presence occurrence (step 8b): open → granted | denied. Written only through Shift so it shares the
 * lock order. A granted exit is the administrative closing of the presence, never a geographic exit.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $shift_id
 * @property int $professional_id
 * @property string $kind
 * @property string $description
 * @property string $status
 * @property Carbon|null $informed_at
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon $created_at
 */
#[Fillable(['shift_id', 'professional_id', 'kind', 'description', 'reported_by_user_id', 'status'])]
class AttendanceOccurrence extends Model
{
    use BelongsToTenant;

    public const STATUS_LABELS = ['open' => 'Aberta', 'granted' => 'Deferida', 'denied' => 'Indeferida'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'informed_at' => 'datetime',
            'decided_at' => 'datetime',
            'shift_id' => 'integer',
            'professional_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
