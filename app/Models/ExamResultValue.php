<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One analyte of a laboratory result (step 5.4), with the name, unit and reference as released. Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $exam_result_id
 * @property int $exam_analyte_id
 * @property string $analyte_name
 * @property string|null $unit
 * @property string|null $reference_min
 * @property string|null $reference_max
 * @property string|null $reference_text
 * @property string|null $value_numeric
 * @property string|null $value_text
 * @property string|null $flag low | high
 */
#[Fillable(['tenant_id', 'exam_result_id', 'exam_analyte_id', 'analyte_name', 'unit', 'reference_min', 'reference_max', 'reference_text', 'value_numeric', 'value_text', 'flag'])]
class ExamResultValue extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reference_min' => 'decimal:4', 'reference_max' => 'decimal:4', 'value_numeric' => 'decimal:4'];
    }
}
