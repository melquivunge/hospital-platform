<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The link between a person and the certificate signer (step 4b.5, ADR-0006): only the credential the provider
 * needs, encrypted at rest, never logged. Deleted when the person loses access.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $provider
 * @property string $credential
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'provider', 'credential', 'created_at'])]
class SigningAccount extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $hidden = ['credential'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['credential' => 'encrypted', 'created_at' => 'datetime'];
    }
}
