<?php

namespace App\Models;

use App\Scheduling\RequestStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Request to change who holds shifts: direct transfer (step 7a) or swap of two shifts (step 7b). Writes go
 * through Shift so they share its lock order: professionals, then the shifts in id order, then the request.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $type
 * @property int $shift_id
 * @property int $requester_professional_id
 * @property int $recipient_professional_id
 * @property RequestStatus $status
 * @property string|null $note
 * @property int $requested_by_user_id
 * @property Carbon|null $recipient_decided_at
 * @property Carbon|null $decided_at
 * @property int|null $decided_by_user_id
 * @property string|null $decision_reason
 * @property int|null $counterpart_shift_id
 * @property int|null $origin_approved_by_user_id
 * @property Carbon|null $origin_approved_at
 * @property int|null $counterpart_approved_by_user_id
 * @property Carbon|null $counterpart_approved_at
 * @property Carbon $created_at
 */
#[Fillable(['type', 'shift_id', 'counterpart_shift_id', 'requester_professional_id', 'recipient_professional_id', 'status', 'note', 'requested_by_user_id'])]
class ShiftRequest extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'recipient_decided_at' => 'datetime',
            'decided_at' => 'datetime',
            'origin_approved_at' => 'datetime',
            'counterpart_approved_at' => 'datetime',
            'shift_id' => 'integer',
            'counterpart_shift_id' => 'integer',
            'requester_professional_id' => 'integer',
            'recipient_professional_id' => 'integer',
        ];
    }

    /**
     * Pending requests whose shift already started expire (PRODUCT.md, limite temporal). Called before
     * reading or acting on requests.
     */
    public static function expireStarted(): void
    {
        // ponytail: lazy expiry on access; a scheduled job is only needed if reports must see it without anyone opening the screen.
        // Rows an in-flight action holds are skipped: that action re-checks the start itself.
        DB::transaction(function () {
            $started = self::query()
                ->whereIn('status', RequestStatus::pending())
                ->where(fn (Builder $query) => $query
                    ->whereHas('shift', fn (Builder $shift) => $shift->where('starts_at', '<=', now()))
                    ->orWhereHas('counterpart', fn (Builder $shift) => $shift->where('starts_at', '<=', now())))
                ->with('shift')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            foreach ($started as $request) {
                $request->forceFill(['status' => RequestStatus::Expired, 'decided_at' => now(), 'decision_reason' => 'O plantão começou antes da conclusão.'])->save();
                AuditEvent::record("shift.{$request->type}_expired", $request->tenant_id, $request->shift, ['request_id' => $request->id]);
            }
        }, 3);
    }

    /**
     * Pending requests that involve the shift on either side.
     *
     * @return Builder<self>
     */
    public static function pendingFor(int $shiftId): Builder
    {
        return self::query()
            ->whereIn('status', RequestStatus::pending())
            ->where(fn (Builder $query) => $query->where('shift_id', $shiftId)->orWhere('counterpart_shift_id', $shiftId));
    }

    public function isSwap(): bool
    {
        return $this->type === 'swap';
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'counterpart_shift_id');
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'requester_professional_id');
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'recipient_professional_id');
    }
}
