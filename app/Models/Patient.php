<?php

namespace App\Models;

use App\Clinical\PatientIdentity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Patient of the client (step 3.2). Registration data only; clinical records come in step 3.6.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $registered_unit_id
 * @property string $status
 * @property string $name
 * @property string $name_key
 * @property string|null $social_name
 * @property Carbon|null $birth_date
 * @property string|null $sex
 * @property string|null $cpf
 * @property string|null $cns
 * @property string|null $mother_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $guardian_name
 * @property string|null $guardian_relationship
 * @property string|null $guardian_phone
 * @property int $created_by_user_id
 */
#[Fillable(['registered_unit_id', 'name', 'social_name', 'birth_date', 'sex', 'cpf', 'cns', 'mother_name', 'phone', 'email', 'guardian_name', 'guardian_relationship', 'guardian_phone'])]
class Patient extends Model
{
    use BelongsToTenant;

    public const SEXES = ['female', 'male', 'unknown'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    protected static function booted(): void
    {
        // Complete once it can be told apart for sure: a document, the birth date and the sex.
        static::saving(function (Patient $patient) {
            $patient->name_key = PatientIdentity::nameKey($patient->name);
            $patient->status = ($patient->cpf !== null || $patient->cns !== null) && $patient->birth_date !== null && $patient->sex !== null
                ? 'complete' : 'provisional';
        });
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function registeredUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'registered_unit_id');
    }

    /**
     * Same birth date and the same name, or the same first and last names (user decision: warn, never
     * merge on its own).
     *
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function scopePossibleDuplicatesOf(Builder $query, string $name, ?string $birthDate): Builder
    {
        $parts = explode(' ', PatientIdentity::nameKey($name));
        $first = $parts[0];
        $last = end($parts);

        return $query->whereDate('birth_date', $birthDate ?? '0001-01-01')
            ->where(fn (Builder $match) => $match->where('name_key', PatientIdentity::nameKey($name))
                ->orWhere(fn (Builder $ends) => $ends->where('name_key', 'like', addcslashes($first, '%_\\').' %')->where('name_key', 'like', '% '.addcslashes($last, '%_\\'))));
    }
}
