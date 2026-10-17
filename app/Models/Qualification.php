<?php

namespace App\Models;

use App\Professionals\QualificationStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Qualification (habilitação) of a professional for one unit and function, with authorized sectors and
 * validity in the unit's local dates. Every change locks the professional and bumps eligibility_version.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $professional_id
 * @property int $unit_id
 * @property int $job_function_id
 * @property QualificationStatus $status
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 * @property int|null $decided_by_user_id
 */
#[Fillable(['professional_id', 'unit_id', 'job_function_id', 'status', 'valid_from', 'valid_until', 'decided_by_user_id'])]
class Qualification extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QualificationStatus::class,
            'valid_from' => 'date',
            'valid_until' => 'date',
            'professional_id' => 'integer',
            'unit_id' => 'integer',
            'job_function_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<JobFunction, $this>
     */
    public function jobFunction(): BelongsTo
    {
        return $this->belongsTo(JobFunction::class);
    }

    /**
     * @return BelongsToMany<Sector, $this>
     */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class)->withPivot('unit_id');
    }

    /**
     * New qualifications always start pending: requesting (or importing) never approves (V1-10).
     *
     * @param  array{job_function_id: int, valid_from: string, valid_until: string|null}  $data
     * @param  list<int>  $sectorIds
     */
    public static function request(Professional $professional, Unit $unit, array $data, array $sectorIds): self
    {
        return DB::transaction(function () use ($professional, $unit, $data, $sectorIds) {
            $professional = self::lockProfessional($professional);

            $qualification = self::create([
                ...$data,
                'professional_id' => $professional->id,
                'unit_id' => $unit->id,
                'status' => QualificationStatus::Pending,
            ]);
            $qualification->sectors()->attach(array_fill_keys($sectorIds, ['unit_id' => $unit->id]));

            AuditEvent::record('qualification.requested', $qualification->tenant_id, $qualification, [
                'professional_id' => $professional->id,
                'unit_id' => $unit->id,
                'job_function_id' => $qualification->job_function_id,
                'sector_ids' => $sectorIds,
            ]);

            return $qualification;
        });
    }

    /**
     * Approve, suspend, reactivate (approve again) or revoke, under the professional lock.
     */
    public function transitionTo(QualificationStatus $next, ?string $reason): void
    {
        DB::transaction(function () use ($next, $reason) {
            self::lockProfessional($this->professional()->firstOrFail());
            $current = self::query()->lockForUpdate()->findOrFail($this->id);

            if (! in_array($next, $current->status->allowedNext(), true)) {
                throw ValidationException::withMessages(['status' => 'Esta habilitação não pode mudar para o status pedido. Recarregue a página.']);
            }

            $from = $current->status;
            $current->forceFill(['status' => $next, 'decided_by_user_id' => Auth::id()])->save();

            AuditEvent::record('qualification.'.$next->value, $current->tenant_id, $current, array_filter([
                'from' => $from->value,
                'reason' => $reason,
            ]));

            $this->setRawAttributes($current->getAttributes(), true);
        });
    }

    /**
     * Serializes qualification changes per professional (also against assignments in later steps).
     */
    private static function lockProfessional(Professional $professional): Professional
    {
        $locked = Professional::query()->lockForUpdate()->findOrFail($professional->id);
        $locked->increment('eligibility_version');

        return $locked;
    }
}
