<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Document attached to a visit (step 3.7). The file is on the private disk under `clinical/{tenant}/`;
 * withdrawing keeps it and records who, when and why.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $visit_id
 * @property int $uploaded_by_user_id
 * @property int $professional_id
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $path
 * @property string $sha256
 * @property Carbon $created_at
 * @property Carbon|null $withdrawn_at
 * @property string|null $withdraw_reason
 */
#[Fillable(['patient_id', 'visit_id', 'uploaded_by_user_id', 'professional_id', 'original_name', 'mime_type', 'size_bytes', 'path', 'sha256', 'created_at'])]
class ClinicalDocument extends Model
{
    use BelongsToTenant;

    public const MAX_KILOBYTES = 10240;

    // ponytail: fixed cap against disk abuse; make it a policy setting if a specialty needs more.
    public const MAX_PER_VISIT = 20;

    /** Extension => the only content type accepted for it. */
    public const TYPES = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'withdrawn_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
