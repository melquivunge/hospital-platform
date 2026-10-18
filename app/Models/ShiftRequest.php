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
 * Request to change who holds a shift (step 7a: direct transfer). Writes go through Shift so they share
 * its lock order: professionals, then the shift, then the request.
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
 * @property Carbon $created_at
 */
#[Fillable(['type', 'shift_id', 'requester_professional_id', 'recipient_professional_id', 'status', 'note', 'requested_by_user_id'])]
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
            'shift_id' => 'integer',
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
                ->whereHas('shift', fn (Builder $query) => $query->where('starts_at', '<=', now()))
                ->with('shift')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            foreach ($started as $request) {
                $request->forceFill(['status' => RequestStatus::Expired, 'decided_at' => now(), 'decision_reason' => 'O plantão começou antes da conclusão.'])->save();
                AuditEvent::record('shift.transfer_expired', $request->tenant_id, $request->shift, ['request_id' => $request->id]);
            }
        }, 3);
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
