<?php

namespace App\Models;

use App\Clinical\PatientIdentity;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 * @property string|null $race_color SUS code 01-05 (step 6b.6a1)
 * @property string|null $ethnicity Portaria SAS 508/2010 code, only for 05
 * @property string|null $address_street the address (step 6b.6b1), sizes of the SISAIH01 layout
 * @property string|null $address_number
 * @property string|null $address_complement
 * @property string|null $address_district
 * @property string|null $address_municipality IBGE code, 7 digits
 * @property string|null $address_zip
 * @property int $created_by_user_id
 * @property int|null $merged_into_id
 */
#[Fillable(['registered_unit_id', 'name', 'social_name', 'birth_date', 'sex', 'cpf', 'cns', 'mother_name', 'phone', 'email', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'race_color', 'ethnicity', 'address_street', 'address_number', 'address_complement', 'address_district', 'address_municipality', 'address_zip'])]
class Patient extends Model
{
    use BelongsToTenant;

    public const SEXES = ['female', 'male', 'unknown'];

    /** Step 6b.6a1: the SUS race/color codes (self-declared); 05 carries the ethnicity. */
    public const RACE_COLORS = ['01' => 'Branca', '02' => 'Preta', '03' => 'Parda', '04' => 'Amarela', '05' => 'Indígena'];

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
            $patient->status = match (true) {
                $patient->merged_into_id !== null => 'merged',
                ($patient->cpf !== null || $patient->cns !== null) && $patient->birth_date !== null && $patient->sex !== null => 'complete',
                default => 'provisional',
            };
        });
    }

    /**
     * Step 3.3: the duplicate points to the primary registration; nothing is deleted. Both rows are locked
     * in id order; a registration already merged, or the same one twice, is refused.
     */
    public static function merge(self $primary, self $duplicate, string $reason): PatientMerge
    {
        return DB::transaction(function () use ($primary, $duplicate, $reason) {
            $locked = self::query()->whereKey([$primary->id, $duplicate->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $primary = $locked->get($primary->id);
            $duplicate = $locked->get($duplicate->id);

            if ($primary === null || $duplicate === null || $primary->is($duplicate)) {
                throw ValidationException::withMessages(['merge' => 'Escolha dois cadastros diferentes.']);
            }
            if ($primary->merged_into_id !== null || $duplicate->merged_into_id !== null) {
                throw ValidationException::withMessages(['merge' => 'Um dos cadastros já foi unificado. Recarregue a página.']);
            }

            // No chains: a registration that is already the primary of a merge cannot become a duplicate,
            // or that earlier decision would silently change meaning and could no longer be undone.
            if (self::query()->where('merged_into_id', $duplicate->id)->exists()) {
                throw ValidationException::withMessages(['merge' => 'O cadastro escolhido como duplicado é o principal de outra unificação. Escolha-o como principal ou desfaça a outra unificação antes.']);
            }

            // Step 4.2: one open emergency episode per person; the arrival checks the merged record too.
            if (EmergencyEpisode::query()->where('patient_id', $primary->id)->whereIn('status', EmergencyEpisode::OPEN)->exists()
                && EmergencyEpisode::query()->where('patient_id', $duplicate->id)->whereIn('status', EmergencyEpisode::OPEN)->exists()) {
                throw ValidationException::withMessages(['merge' => 'Os dois cadastros estão na urgência ao mesmo tempo. Encerre um dos episódios antes de unificar.']);
            }
            // Step 4.4a: one active admission per person, as for emergency episodes.
            if (Admission::query()->where('patient_id', $primary->id)->whereIn('status', Admission::ACTIVE)->exists()
                && Admission::query()->where('patient_id', $duplicate->id)->whereIn('status', Admission::ACTIVE)->exists()) {
                throw ValidationException::withMessages(['merge' => 'Os dois cadastros têm internação em andamento. Encerre uma delas antes de unificar.']);
            }

            $duplicate->forceFill(['merged_into_id' => $primary->id])->save();
            $merge = PatientMerge::query()->create([
                'primary_patient_id' => $primary->id, 'duplicate_patient_id' => $duplicate->id, 'reason' => $reason,
                'merged_by_user_id' => Auth::id(), 'merged_at' => now(),
            ]);
            AuditEvent::record('patient.merged', $primary->tenant_id, $primary, ['merge_id' => $merge->id, 'duplicate_patient_id' => $duplicate->id]);

            return $merge;
        });
    }

    /**
     * Undoing is a new decision: the duplicate becomes an independent registration again.
     */
    public static function undoMerge(PatientMerge $merge, string $reason): void
    {
        DB::transaction(function () use ($merge, $reason) {
            $current = PatientMerge::query()->lockForUpdate()->findOrFail($merge->id);
            $duplicate = self::query()->lockForUpdate()->findOrFail($current->duplicate_patient_id);

            if ($current->undone_at !== null || $duplicate->merged_into_id !== $current->primary_patient_id) {
                throw ValidationException::withMessages(['merge' => 'Esta unificação já foi desfeita ou mudou. Recarregue a página.']);
            }

            $duplicate->forceFill(['merged_into_id' => null])->save();
            $current->forceFill(['undone_at' => now(), 'undone_by_user_id' => Auth::id(), 'undo_reason' => $reason])->save();
            AuditEvent::record('patient.merge_undone', $duplicate->tenant_id, $duplicate, ['merge_id' => $current->id, 'primary_patient_id' => $current->primary_patient_id]);
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

        return $query->whereNull('merged_into_id')->where('birth_date', $birthDate ?? '0001-01-01')
            ->where(fn (Builder $match) => $match->where('name_key', PatientIdentity::nameKey($name))
                ->orWhere(fn (Builder $ends) => $ends->where('name_key', 'like', addcslashes($first, '%_\\').' %')->where('name_key', 'like', '% '.addcslashes($last, '%_\\'))));
    }
}
