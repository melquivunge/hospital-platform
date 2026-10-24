<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A released result of an exam order item (step 5.4), signed with the password; never changed. A rectification is
 * the next version with its reason. Clinical (RLS).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $exam_order_id
 * @property int $exam_order_item_id
 * @property int $version
 * @property string|null $rectification_reason
 * @property string|null $report
 * @property string|null $attachment_name
 * @property string|null $attachment_path
 * @property int|null $attachment_size
 * @property string|null $attachment_sha256
 * @property int $released_by_user_id
 * @property int|null $professional_id
 * @property string $signature_method
 * @property Carbon $released_at
 * @property int $value_count the database refuses more values than this
 */
#[Fillable(['patient_id', 'exam_order_id', 'exam_order_item_id', 'version', 'rectification_reason', 'report', 'attachment_name', 'attachment_path', 'attachment_size', 'attachment_sha256', 'released_by_user_id', 'professional_id', 'signature_method', 'released_at', 'value_count'])]
class ExamResult extends Model
{
    use BelongsToTenant;

    public const MAX_KILOBYTES = 10240;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['released_at' => 'datetime', 'version' => 'integer', 'attachment_size' => 'integer', 'value_count' => 'integer'];
    }

    /**
     * @return HasMany<ExamResultValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ExamResultValue::class)->orderBy('id');
    }
}
