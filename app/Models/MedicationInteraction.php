<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Interaction between two active ingredients, kept by the network (step 4b.1a). Stored once per pair, in
 * byte order of the normalized keys (Medication::key). Deactivated, never deleted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $ingredient_a
 * @property string $ingredient_b
 * @property string $severity mild, moderate, severe or contraindicated
 * @property string $note
 * @property bool $active
 * @property int $created_by_user_id
 */
#[Fillable(['ingredient_a', 'ingredient_b', 'severity', 'note', 'active', 'created_by_user_id'])]
class MedicationInteraction extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
