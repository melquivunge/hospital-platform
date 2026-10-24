<?php

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Client of the SaaS: a hospital network or an independent hospital. Isolation boundary.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $exam_target_urgent_minutes target time of the exam queues (step 5.3)
 * @property int $exam_target_routine_minutes
 */
#[Fillable(['name', 'slug'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
