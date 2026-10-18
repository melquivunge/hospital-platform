<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Presence attempt by location (step 8a), append-only. Accepted events are the presence record;
 * rejected ones keep the attempt and the reason. Written only through Shift::recordAttendance.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $shift_id
 * @property int $professional_id
 * @property int $user_id
 * @property string $kind
 * @property string $result
 * @property string|null $reason
 * @property string|null $accuracy_m
 * @property Carbon|null $measured_at
 * @property Carbon $received_at
 * @property int|null $point_id
 * @property string|null $distance_m
 * @property string $idempotency_key
 */
#[Fillable(['shift_id', 'professional_id', 'user_id', 'kind', 'result', 'reason', 'latitude', 'longitude', 'accuracy_m', 'measured_at', 'received_at', 'point_id', 'distance_m', 'policy_version_ids', 'idempotency_key'])]
class AttendanceEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    public const REASONS = [
        'not_configured' => 'A presença ainda não está configurada nesta unidade (pontos, precisão e janelas).',
        'outside_window' => 'Fora da janela de horário permitida para este registro.',
        'stale_measurement' => 'A medição de localização é antiga ou tem horário inválido. Tente de novo.',
        'low_accuracy' => 'A precisão da localização está acima do limite da unidade. Tente em área aberta.',
        'outside_radius' => 'Você está fora do raio permitido pelo hospital.',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measured_at' => 'datetime',
            'received_at' => 'datetime',
            'policy_version_ids' => 'array',
            'shift_id' => 'integer',
            'point_id' => 'integer',
        ];
    }

    public function accepted(): bool
    {
        return $this->result === 'accepted';
    }

    public function reasonMessage(): ?string
    {
        return $this->reason === null ? null : (self::REASONS[$this->reason] ?? $this->reason);
    }

    /**
     * @return BelongsTo<AttendancePoint, $this>
     */
    public function point(): BelongsTo
    {
        return $this->belongsTo(AttendancePoint::class);
    }
}
