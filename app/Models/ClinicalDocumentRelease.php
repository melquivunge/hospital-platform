<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Step 7a.4: one act on a document's release to the patient's portal — "released" or "revoked". Insert only; the
 * latest row of a document decides.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $clinical_document_id
 * @property string $event
 * @property int $user_id
 * @property Carbon $created_at
 */
class ClinicalDocumentRelease extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * Of the given documents, those whose latest act is "released".
     *
     * @param  list<int>  $documentIds
     * @return list<int>
     */
    public static function released(array $documentIds): array
    {
        return array_values(self::query()->whereIn('clinical_document_id', $documentIds)->orderBy('id')->get()
            ->keyBy('clinical_document_id')
            ->filter(fn (self $act) => $act->event === 'released')
            ->map(fn (self $act) => $act->clinical_document_id)->values()->all());
    }
}
