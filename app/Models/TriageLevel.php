<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One of the network's 5 risk levels (step 4.2): name, color and target time to care. The defaults are a
 * starting point the network admin adjusts; they are not a licensed protocol and must be validated by a
 * specialist before real use.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $level
 * @property string $name
 * @property string $color
 * @property int $target_minutes
 */
#[Fillable(['level', 'name', 'color', 'target_minutes'])]
class TriageLevel extends Model
{
    use BelongsToTenant;

    public const DEFAULTS = [
        1 => ['Emergência', 'red', 0],
        2 => ['Muito urgente', 'orange', 10],
        3 => ['Urgente', 'yellow', 60],
        4 => ['Pouco urgente', 'green', 120],
        5 => ['Não urgente', 'blue', 240],
    ];

    /**
     * The scale of the current client, created from the defaults the first time it is read.
     *
     * @return Collection<int, TriageLevel>
     */
    public static function scale(): Collection
    {
        if (! self::query()->exists()) {
            foreach (self::DEFAULTS as $level => [$name, $color, $minutes]) {
                // createOrFirst: two first reads at once meet the unique index instead of a 500.
                self::query()->createOrFirst(['level' => $level], ['name' => $name, 'color' => $color, 'target_minutes' => $minutes]);
            }
        }

        return self::query()->orderBy('level')->get()->keyBy('level');
    }
}
