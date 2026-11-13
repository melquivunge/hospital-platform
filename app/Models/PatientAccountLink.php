<?php

namespace App\Models;

use App\Http\Controllers\Portal\PatientPortalInviteController;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Step 7a.2 (ADR-0012): one act of the reception on a guardian's link to a minor — "linked" or "ended". Insert
 * only; the current state of a pair is its latest row.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $account_id
 * @property int $patient_id
 * @property string $event
 * @property int $actor_user_id
 * @property Carbon $created_at
 */
class PatientAccountLink extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * The minors the account reaches now: latest act "linked", still under 18, record not merged away.
     *
     * @return list<int>
     */
    public static function minorsOf(PatientAccount $account): array
    {
        return array_values(self::current(self::query()->where('account_id', $account->id), 'patient_id')
            ->filter(fn (self $link) => $link->event === 'linked')
            ->pluck('patient_id')
            ->pipe(fn ($ids) => Patient::query()->whereIn('id', $ids)->whereNull('merged_into_id')->get())
            ->filter(fn (Patient $patient) => self::isMinor($patient))
            ->map(fn (Patient $patient) => (int) $patient->id)->all());
    }

    /**
     * The guardian accounts linked to the patient now.
     *
     * @return list<int>
     */
    public static function guardiansOf(Patient $patient): array
    {
        return array_values(self::current(self::query()->where('patient_id', $patient->id), 'account_id')
            ->filter(fn (self $link) => $link->event === 'linked')
            ->map(fn (self $link) => $link->account_id)->all());
    }

    public static function isMinor(Patient $patient): bool
    {
        return $patient->birth_date !== null && $patient->birth_date->age < PatientPortalInviteController::ADULT_AGE;
    }

    /**
     * @param  Builder<self>  $query
     * @return Collection<int, self>
     */
    private static function current($query, string $other)
    {
        return $query->orderBy('id')->get()->keyBy($other)->values();
    }
}
