<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Step 7b.3: an item of the portal's inbox. Written only by App\Jobs\PortalNotifier; the title has no clinical data.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $account_id
 * @property int $audit_event_id
 * @property string $kind
 * @property string|null $ref
 * @property string $title
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
class PatientNotification extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
