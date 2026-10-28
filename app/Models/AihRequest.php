<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Step 6b.5a (ADR-0011): the AIH request report of a SUS admission, signed with the password by the responsible
 * doctor; a correction is a new version (insert-only in the database).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $patient_id
 * @property int $admission_id
 * @property int $version
 * @property string $procedure_code
 * @property string $procedure_name
 * @property string $cid_code
 * @property string|null $cid_secondary
 * @property string $justification
 * @property int $author_user_id
 * @property int $professional_id
 * @property Carbon $signed_at
 */
class AihRequest extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }
}
