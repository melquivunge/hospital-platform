<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One CSV line and its result: ready (valid, waiting), created, identical, conflict or rejected.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $import_batch_id
 * @property int $line
 * @property array<string, string|null> $data
 * @property string $status
 * @property list<string>|null $messages
 * @property int|null $record_id
 */
#[Fillable(['import_batch_id', 'line', 'data', 'status', 'messages', 'record_id'])]
class ImportItem extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'messages' => 'array'];
    }
}
