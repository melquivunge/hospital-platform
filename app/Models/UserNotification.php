<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Inbox item of one user (step 9a). Written only by DeliverNotifications.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $audit_event_id
 * @property string $title
 * @property string|null $url
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
class UserNotification extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
