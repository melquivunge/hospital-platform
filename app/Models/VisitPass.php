<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Step 7b.1 (ADR-0012): the QR handed over on arrival — one arrival's journey, without an account. Valid until
 * revoked or 12 hours after the arrival ends.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int|null $appointment_id
 * @property int|null $emergency_episode_id
 * @property string $token_hash
 * @property int $created_by_user_id
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property Carbon $created_at
 */
class VisitPass extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** The journey stays open this long after the discharge (contract, section 4). */
    public const HOURS_AFTER_END = 12;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public static function forToken(string $token): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }

    /**
     * Where the arrival is now, in the patient's words, and when it ended (null while it goes on).
     *
     * @return array{steps: list<string>, current: int, endedAt: CarbonInterface|null}
     */
    public function journey(): array
    {
        if ($this->appointment_id !== null) {
            $appointment = Appointment::query()->findOrFail($this->appointment_id);
            $current = match ($appointment->status) {
                'in_progress' => 2,
                'completed', 'cancelled', 'no_show' => 3,
                default => 1,
            };

            // A completed appointment ends when its visit closes (the appointment's own closed_at is for cancel and no-show).
            $ended = $appointment->status === 'completed'
                ? Visit::query()->where('appointment_id', $appointment->id)->max('closed_at')
                : $appointment->closed_at;

            return ['steps' => ['Chegou', 'Aguardando', 'Em atendimento', 'Encerrado'], 'current' => $current, 'endedAt' => $ended === null ? null : Carbon::parse($ended)];
        }

        $episode = EmergencyEpisode::query()->findOrFail($this->emergency_episode_id);
        $current = match (true) {
            $episode->ended_at !== null || $episode->status === 'admission_requested' => 4,
            $episode->status === 'in_care' => 3,
            $episode->triaged_at !== null => 2,
            default => 0,
        };

        // The last step says how it ended: discharge, an admission request, or an end without care.
        $last = match ($episode->status) {
            'admission_requested' => 'Internação solicitada',
            'evaded' => 'Encerrado',
            default => 'Alta',
        };

        return ['steps' => ['Chegou', 'Classificação', 'Aguardando', 'Em atendimento', $last], 'current' => $current, 'endedAt' => $episode->ended_at];
    }

    public static function expiredAt(?CarbonInterface $ended): bool
    {
        return $ended !== null && $ended->toImmutable()->addHours(self::HOURS_AFTER_END)->isPast();
    }
}
