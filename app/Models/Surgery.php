<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A surgery (step 5b.2), born from the order of the professional who attends an open visit or an active admission,
 * signed with the password. Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $unit_id
 * @property int|null $admission_id
 * @property int|null $visit_id
 * @property int $author_user_id
 * @property int $professional_id
 * @property int $surgical_procedure_id
 * @property string $procedure_name as ordered
 * @property string $laterality none | left | right | bilateral
 * @property string $urgency elective | urgent
 * @property Carbon $desired_date
 * @property string $indication
 * @property string $status requested | cancelled
 * @property string $signature_method
 * @property Carbon $created_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 */
#[Fillable(['patient_id', 'unit_id', 'admission_id', 'visit_id', 'author_user_id', 'professional_id', 'surgical_procedure_id', 'procedure_name', 'laterality', 'urgency', 'desired_date', 'indication', 'status', 'signature_method', 'created_at', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason'])]
class Surgery extends Model
{
    use BelongsToTenant;

    /** Explicit, never empty: the side is part of the safe surgery checks. */
    public const LATERALITIES = ['none' => 'Não se aplica', 'left' => 'Esquerdo', 'right' => 'Direito', 'bilateral' => 'Bilateral'];

    public const URGENCIES = ['elective' => 'Eletiva', 'urgent' => 'Urgência'];

    /** How far ahead an elective surgery may be wished for. */
    public const MAX_DAYS_AHEAD = 365;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['desired_date' => 'date', 'created_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
