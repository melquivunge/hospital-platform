<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\JobFunctionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Function defined by the network (e.g. "Médico plantonista"). Qualifications and shifts refer to it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 */
#[Fillable(['name'])]
class JobFunction extends Model
{
    /** @use HasFactory<JobFunctionFactory> */
    use BelongsToTenant, HasFactory;
}
