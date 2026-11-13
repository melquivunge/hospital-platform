<?php

namespace App\Models;

use App\Http\Controllers\Portal\PortalJourney;
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
     * Where the arrival is now (steps 7b.1 and 7b.2).
     *
     * @return array{kind: string, unit: string, steps: list<string>, current: int, endedAt: CarbonInterface|null, position: int|null, waitMinutes: int|null, level: string|null, detail: string|null}
     */
    public function journey(): array
    {
        return $this->appointment_id !== null
            ? PortalJourney::appointment(Appointment::query()->findOrFail($this->appointment_id))
            : PortalJourney::episode(EmergencyEpisode::query()->findOrFail($this->emergency_episode_id));
    }

    public static function expiredAt(?CarbonInterface $ended): bool
    {
        return $ended !== null && $ended->toImmutable()->addHours(self::HOURS_AFTER_END)->isPast();
    }
}
