<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Clinical note (step 3.6a): a draft only the author sees, then signed with the password and immutable
 * (a database trigger refuses changes and deletes). An addendum is a note pointing to the signed one it
 * corrects.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int|null $visit_id
 * @property int|null $addendum_of_id
 * @property int $author_user_id
 * @property int $author_professional_id
 * @property string $body
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon|null $signed_at
 */
#[Fillable(['patient_id', 'visit_id', 'addendum_of_id', 'author_user_id', 'author_professional_id', 'body'])]
class ClinicalNote extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'author_professional_id');
    }

    /**
     * @return BelongsTo<Visit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * @return HasMany<ClinicalNote, $this>
     */
    public function addenda(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'addendum_of_id');
    }
}
