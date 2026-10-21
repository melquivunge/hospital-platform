<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The pharmacist's review of one prescription item (step 4b.3a, ADR-0005). One per item, never changed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $prescription_item_id
 * @property string $decision
 * @property string|null $note
 * @property int $reviewed_by_user_id
 * @property Carbon $created_at
 */
#[Fillable(['prescription_item_id', 'decision', 'note', 'reviewed_by_user_id', 'created_at'])]
class PrescriptionReview extends Model
{
    use BelongsToTenant;

    public const DECISIONS = ['approved' => 'Aprovado', 'approved_with_note' => 'Aprovado com observação', 'refused' => 'Recusado'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
