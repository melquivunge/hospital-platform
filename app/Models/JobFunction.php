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
 * @property bool $attends_patients step 4.9: calls patients from the emergency queue
 */
#[Fillable(['name', 'attends_patients'])]
class JobFunction extends Model
{
    /** @use HasFactory<JobFunctionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['attends_patients' => 'boolean'];
    }
}
