<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Prescription of an admission or an emergency visit (step 4b.2a). Signed, it never changes and is valid for
 * 24 hours; the newest signed one of the encounter is the active one while the encounter lasts.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $unit_id
 * @property int|null $admission_id
 * @property int|null $visit_id
 * @property int $author_user_id
 * @property int $professional_id
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon|null $signed_at
 * @property Carbon|null $valid_until
 * @property string|null $signature_method
 */
#[Fillable(['patient_id', 'unit_id', 'admission_id', 'visit_id', 'author_user_id', 'professional_id', 'created_at'])]
class Prescription extends Model
{
    use BelongsToTenant;

    public const VALID_HOURS = 24;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'signed_at' => 'datetime', 'valid_until' => 'datetime'];
    }

    /**
     * @return HasMany<PrescriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
