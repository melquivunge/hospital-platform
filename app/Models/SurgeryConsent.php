<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The signed consent term of a surgery (step 5b.4), or the reason an urgent surgery goes without one. Kept: a new
 * one supersedes, none changes (trigger). Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $surgery_id
 * @property string $kind document | waiver
 * @property string|null $path
 * @property string|null $original_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property string|null $waiver_reason
 * @property int $created_by_user_id
 * @property Carbon $created_at
 */
#[Fillable(['tenant_id', 'surgery_id', 'kind', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'waiver_reason', 'created_by_user_id', 'created_at'])]
class SurgeryConsent extends Model
{
    use BelongsToTenant;

    /** Kept for ever: a few replacements are enough to fix a wrong file (security review 5b.4). */
    public const MAX_PER_SURGERY = 5;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
