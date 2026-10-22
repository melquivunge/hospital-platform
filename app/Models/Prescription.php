<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Prescription of an admission or an emergency visit (step 4b.2a). Signed, it never changes and is valid for
 * 24 hours (decision 4: renewed daily). Only the newest signed one of the encounter is active, and only while
 * valid: signing a new one replaces the whole earlier regimen.
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

    /** Microseconds kept: a suspension and the next draft may fall in the same second (4b.2b alert rule). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

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
     * @return BelongsTo<Admission, $this>
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /**
     * @return BelongsTo<Visit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return HasOne<PrescriptionSignature, $this>
     */
    public function signature(): HasOne
    {
        return $this->hasOne(PrescriptionSignature::class);
    }
}
