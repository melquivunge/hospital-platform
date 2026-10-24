<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One exam of an order (step 5.2), with the exam's name and type as ordered. Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $exam_order_id
 * @property int $exam_id
 * @property string $exam_name
 * @property string $exam_type
 */
#[Fillable(['exam_order_id', 'exam_id', 'exam_name', 'exam_type'])]
class ExamOrderItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;
}
