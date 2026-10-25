<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A surgery (step 5b.2), born from the order of the professional who attends an open visit or an active admission,
 * signed with the password. Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $unit_id
 * @property int|null $admission_id
 * @property int|null $visit_id
 * @property int $author_user_id
 * @property int $professional_id
 * @property int $surgical_procedure_id
 * @property string $procedure_name as ordered
 * @property string $laterality none | left | right | bilateral
 * @property string $urgency elective | urgent
 * @property Carbon $desired_date
 * @property string $indication
 * @property string $status requested | scheduled | cancelled
 * @property string $signature_method
 * @property Carbon $created_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 * @property int|null $operating_room_id step 5b.3a: the booking
 * @property Carbon|null $scheduled_start
 * @property Carbon|null $scheduled_end
 * @property int|null $scheduled_by_user_id
 * @property Carbon|null $scheduled_at
 * @property string|null $urgent_reason
 */
#[Fillable(['patient_id', 'unit_id', 'admission_id', 'visit_id', 'author_user_id', 'professional_id', 'surgical_procedure_id', 'procedure_name', 'laterality', 'urgency', 'desired_date', 'indication', 'status', 'signature_method', 'created_at', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason', 'operating_room_id', 'scheduled_start', 'scheduled_end', 'scheduled_by_user_id', 'scheduled_at', 'urgent_reason'])]
class Surgery extends Model
{
    use BelongsToTenant;

    /** Explicit, never empty: the side is part of the safe surgery checks. */
    public const LATERALITIES = ['none' => 'Não se aplica', 'left' => 'Esquerdo', 'right' => 'Direito', 'bilateral' => 'Bilateral'];

    public const URGENCIES = ['elective' => 'Eletiva', 'urgent' => 'Urgência'];

    /** How far ahead an elective surgery may be wished for. */
    public const MAX_DAYS_AHEAD = 365;

    /**
     * Step 5b.3a: still open to cancel and reschedule. A scheduled surgery past its time may not have happened (the
     * patient did not come), so it stays open; the first checklist step (step 5b.4) starts it and closes this.
     */
    public const OPEN = ['requested', 'scheduled'];

    /** Security review 5b.3b: an unscheduled request gives the record until this many days after its wished date. */
    public const REQUESTED_ACCESS_DAYS = 7;

    /** ADR-0009: a surgery still scheduled this many hours after its planned end reaches the manager (step 5b.3c). */
    public const OVERDUE_HOURS = 24;

    public $timestamps = false;

    /**
     * Step 5b.3c: still scheduled more than OVERDUE_HOURS after the planned end (a long surgery still running is not
     * stalled); it keeps the team's access to the record.
     *
     * @return Builder<Surgery>
     */
    public static function overdue(): Builder
    {
        return self::query()->where('status', 'scheduled')->where('scheduled_end', '<=', now()->subHours(self::OVERDUE_HOURS));
    }

    /**
     * Step 5b.3c: requested and never scheduled, past the days it opens the record after the wished date.
     *
     * @return Builder<Surgery>
     */
    public static function stalled(): Builder
    {
        return self::query()->where('status', 'requested')->where('desired_date', '<', now()->subDays(self::REQUESTED_ACCESS_DAYS)->toDateString());
    }

    /**
     * The active team, removed members excluded.
     *
     * @return HasMany<SurgeryTeamMember, $this>
     */
    public function team(): HasMany
    {
        return $this->hasMany(SurgeryTeamMember::class)->whereNull('removed_at')->orderBy('id');
    }

    /**
     * @return HasMany<SurgeryReschedule, $this>
     */
    public function reschedules(): HasMany
    {
        return $this->hasMany(SurgeryReschedule::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<OperatingRoom, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(OperatingRoom::class, 'operating_room_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['desired_date' => 'date', 'created_at' => 'datetime', 'cancelled_at' => 'datetime', 'scheduled_start' => 'datetime', 'scheduled_end' => 'datetime', 'scheduled_at' => 'datetime'];
    }
}
