<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The surgical description (step 5b.5, ADR-0009), signed with the password by a surgeon of the team; or, with
 * `addendum_of_id`, an addendum to it signed later. Never changed (trigger). Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $surgery_id
 * @property int|null $addendum_of_id
 * @property string|null $findings
 * @property string|null $technique
 * @property string|null $complications
 * @property string|null $specimens
 * @property string|null $body the addendum's text
 * @property int $author_user_id
 * @property int $professional_id
 * @property string $signature_method
 * @property Carbon $signed_at
 */
#[Fillable(['tenant_id', 'surgery_id', 'addendum_of_id', 'findings', 'technique', 'complications', 'specimens', 'body', 'author_user_id', 'professional_id', 'signature_method', 'signed_at'])]
class SurgeryDescription extends Model
{
    use BelongsToTenant;

    /** After the completion, the surgeons of the team still open the surgery this long to sign an addendum. */
    public const ADDENDUM_DAYS = 30;

    /** A bound on the addenda of one description. */
    public const MAX_ADDENDA = 20;

    public $timestamps = false;

    /**
     * @return HasMany<SurgeryDescription, $this>
     */
    public function addenda(): HasMany
    {
        return $this->hasMany(self::class, 'addendum_of_id')->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }
}
