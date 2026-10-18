<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * tenant + source system + entity + original id → record created by an import (PRODUCT.md, "Importação").
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $source_system
 * @property string $entity_type
 * @property string $original_id
 * @property int $record_id
 */
#[Fillable(['source_system', 'entity_type', 'original_id', 'record_id'])]
class ImportIdentity extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;
}
