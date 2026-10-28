<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A billable item of the network (step 6.1a, ADR-0010), tied to what it charges for: a consultation, a day in a bed
 * type, or an exam, procedure, material or medication of the catalogs. Deactivated, never deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $kind
 * @property int|null $exam_id
 * @property int|null $surgical_procedure_id
 * @property int|null $material_id
 * @property int|null $medication_id
 * @property string|null $bed_type ward | private | icu, for a daily rate
 * @property string $name
 * @property string|null $code TUSS or the network's own
 * @property string|null $sigtap_code
 * @property bool $active
 */
#[Fillable(['kind', 'exam_id', 'surgical_procedure_id', 'material_id', 'medication_id', 'bed_type', 'name', 'code', 'sigtap_code', 'active'])]
class BillingItem extends Model
{
    use BelongsToTenant;

    public const KINDS = [
        'consultation' => 'Consulta', 'emergency_consultation' => 'Consulta de urgência', 'daily' => 'Diária', 'exam' => 'Exame',
        'procedure' => 'Procedimento cirúrgico', 'material' => 'Material', 'medication' => 'Medicamento',
    ];

    /** The reference column of each catalog kind. */
    public const REFERENCES = ['exam' => 'exam_id', 'procedure' => 'surgical_procedure_id', 'material' => 'material_id', 'medication' => 'medication_id'];

    public const BED_TYPES = ['ward' => 'Enfermaria', 'private' => 'Apartamento', 'icu' => 'UTI'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
