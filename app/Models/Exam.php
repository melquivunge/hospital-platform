<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An exam of the network catalog (step 5.1a): laboratory (with its analytes) or imaging. Deactivated, never
 * deleted: orders refer to it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $type laboratory | imaging
 * @property string $name
 * @property string $name_key normalized name, unique per type
 * @property string $specimen material (laboratory) or body region (imaging)
 * @property string|null $preparation
 * @property string|null $code TUSS or SIGTAP
 * @property bool $active
 */
#[Fillable(['type', 'name', 'specimen', 'preparation', 'code', 'active'])]
class Exam extends Model
{
    use BelongsToTenant;

    public const TYPES = ['laboratory' => 'Laboratório', 'imaging' => 'Imagem'];

    protected static function booted(): void
    {
        static::saving(fn (Exam $exam) => $exam->name_key = Medication::key($exam->name));
    }

    /**
     * @return HasMany<ExamAnalyte, $this>
     */
    public function analytes(): HasMany
    {
        return $this->hasMany(ExamAnalyte::class)->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
