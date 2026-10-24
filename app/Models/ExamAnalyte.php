<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One measured value of a laboratory exam (step 5.1a), with its unit and reference range. Deactivated, never
 * deleted: results refer to it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $exam_id
 * @property string $name
 * @property string|null $unit
 * @property string|null $reference_min
 * @property string|null $reference_max
 * @property string|null $reference_text
 * @property int $position
 * @property bool $active
 */
#[Fillable(['exam_id', 'name', 'unit', 'reference_min', 'reference_max', 'reference_text', 'position', 'active'])]
class ExamAnalyte extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'position' => 'integer', 'reference_min' => 'decimal:4', 'reference_max' => 'decimal:4'];
    }
}
