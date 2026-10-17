<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use LogicException;

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
     * @param  array<string, mixed>  $context
     */
    public static function record(string $action, ?int $tenantId, ?Model $subject = null, array $context = []): self
    {
        return self::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context ?: null,
            'occurred_at' => now(),
        ]);
    }
}
