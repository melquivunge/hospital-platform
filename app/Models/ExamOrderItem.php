<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One exam of an order (step 5.2), with the exam's name and type as ordered. Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $exam_order_id
 * @property int $exam_id
 * @property string $exam_name
 * @property string $exam_type
 * @property Carbon|null $collected_at collected (laboratory) or performed (imaging), once (step 5.3)
 * @property int|null $collected_by_user_id
 */
#[Fillable(['exam_order_id', 'exam_id', 'exam_name', 'exam_type', 'collected_at', 'collected_by_user_id'])]
class ExamOrderItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['collected_at' => 'datetime'];
    }

    /**
     * The released versions, oldest first (step 5.4).
     *
     * @return HasMany<ExamResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class)->orderBy('version');
    }
}
