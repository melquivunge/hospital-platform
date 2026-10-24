<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An exam order of an open visit or an active admission (step 5.2), signed with the password when created.
 * Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $unit_id
 * @property int|null $admission_id
 * @property int|null $visit_id
 * @property int $author_user_id
 * @property int $professional_id
 * @property string $priority routine | urgent
 * @property string $indication
 * @property string $status requested | cancelled
 * @property string $signature_method
 * @property Carbon $created_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 */
#[Fillable(['patient_id', 'unit_id', 'admission_id', 'visit_id', 'author_user_id', 'professional_id', 'priority', 'indication', 'status', 'signature_method', 'created_at', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason'])]
class ExamOrder extends Model
{
    use BelongsToTenant;

    public const PRIORITIES = ['routine' => 'Rotina', 'urgent' => 'Urgente'];

    public $timestamps = false;

    /**
     * @return HasMany<ExamOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ExamOrderItem::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
