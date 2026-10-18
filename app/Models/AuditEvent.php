<?php

namespace App\Models;

use App\Jobs\DeliverNotifications;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Append-only trail of access changes and decisions (V1-14). Never store secrets or coordinates in context.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $actor_user_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 */
#[Fillable(['tenant_id', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'context', 'occurred_at'])]
class AuditEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // ponytail: app-level guard only; add a DB trigger if other writers ever touch this table.
        static::updating(fn () => throw new LogicException('Eventos de auditoria não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos de auditoria não podem ser removidos.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $action, ?int $tenantId, ?Model $subject = null, array $context = []): self
    {
        $event = self::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context ?: null,
            'occurred_at' => now(),
        ]);

        // Notifications are derived after the change commits (step 9a); a rollback delivers nothing.
        // Registered after the insert: outside a transaction afterCommit runs at once.
        // A failed dispatch must not turn a committed change into an error; the next event catches up.
        DB::afterCommit(function () {
            try {
                DeliverNotifications::dispatch();
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return $event;
    }
}
