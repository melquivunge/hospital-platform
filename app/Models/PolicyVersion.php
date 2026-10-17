<?php

namespace App\Models;

use App\Governance\EffectivePolicy;
use App\Governance\PolicyKey;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One published version of a rule, for the network (unit_id NULL) or a unit. Append-only (DB trigger).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $unit_id
 * @property PolicyKey $key
 * @property int $version
 * @property int|null $value
 * @property bool|null $delegable
 * @property int|null $min_value
 * @property int|null $max_value
 * @property string $reason
 * @property int $author_user_id
 * @property Carbon $effective_from
 * @property Carbon $created_at
 */
#[Fillable(['tenant_id', 'unit_id', 'key', 'version', 'value', 'delegable', 'min_value', 'max_value', 'reason', 'author_user_id', 'effective_from'])]
class PolicyVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => PolicyKey::class,
            'delegable' => 'boolean',
            'effective_from' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * Latest version in force at $at for the network (null unit) or one unit.
     */
    public static function current(PolicyKey $key, ?int $unitId, ?CarbonInterface $at = null): ?self
    {
        return self::query()
            ->where('key', $key)
            ->where('unit_id', $unitId)
            ->where('effective_from', '<=', $at ?? now())
            // ponytail: versions take effect on publish, so the highest version is the latest; scheduling would need effective_from here.
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Resolved on every read, so a local value never outlives the network limits that allowed it
     * (defense in depth: publish() also clears local values a network version makes incompatible).
     * Null while the network has not configured the rule: no implicit default.
     */
    public static function effective(PolicyKey $key, Unit $unit, ?CarbonInterface $at = null): ?EffectivePolicy
    {
        $network = self::current($key, null, $at);

        if ($network === null) {
            return null;
        }

        $local = self::current($key, $unit->id, $at);

        if ($local?->value === null) {
            return new EffectivePolicy((int) $network->value, $network, null, false);
        }

        return $network->allows($local->value)
            ? new EffectivePolicy($local->value, $network, $local, false)
            : new EffectivePolicy((int) $network->value, $network, null, true);
    }

    /**
     * Network row only: whether a unit may use this local value.
     */
    public function allows(int $value): bool
    {
        return (bool) $this->delegable
            && ($this->min_value === null || $value >= $this->min_value)
            && ($this->max_value === null || $value <= $this->max_value);
    }

    /**
     * Publishes the next version, effective immediately. $expectedVersion is the version the author saw:
     * if someone published in between, the author gets a conflict instead of silently overwriting it.
     *
     * @param  array{value: int|null, delegable?: bool, min_value?: int|null, max_value?: int|null, reason: string, confirm_incompatible?: bool}  $data
     */
    public static function publish(PolicyKey $key, int $tenantId, ?Unit $unit, int $expectedVersion, array $data): self
    {
        try {
            return DB::transaction(function () use ($key, $tenantId, $unit, $expectedVersion, $data) {
                // ponytail: one lock per tenant serializes all rule publications; fine at admin-edit rates.
                Membership::lockTenant($tenantId);

                $latest = (int) self::query()->where('key', $key)->where('unit_id', $unit?->id)->max('version');

                if ($latest !== $expectedVersion) {
                    throw self::conflict();
                }

                $incompatible = [];

                if ($unit === null) {
                    $incompatible = self::checkNetwork($key, $data);
                } else {
                    self::checkLocal($key, $data['value']);
                }

                $version = self::create([
                    'tenant_id' => $tenantId,
                    'unit_id' => $unit?->id,
                    'key' => $key,
                    'version' => $latest + 1,
                    'value' => $data['value'],
                    'delegable' => $unit === null ? ($data['delegable'] ?? false) : null,
                    'min_value' => $unit === null ? ($data['min_value'] ?? null) : null,
                    'max_value' => $unit === null ? ($data['max_value'] ?? null) : null,
                    'reason' => $data['reason'],
                    'author_user_id' => Auth::id(),
                    'effective_from' => now(),
                ]);

                // The confirmed transition removes those local values, so widening the limits later
                // does not silently bring them back.
                foreach ($incompatible as $unitId) {
                    self::create([
                        'tenant_id' => $tenantId,
                        'unit_id' => $unitId,
                        'key' => $key,
                        'version' => (int) self::query()->where('key', $key)->where('unit_id', $unitId)->max('version') + 1,
                        'value' => null,
                        'reason' => "Valor local removido: incompatível com a versão {$version->version} da rede.",
                        'author_user_id' => Auth::id(),
                        'effective_from' => now(),
                    ]);
                }

                AuditEvent::record('policy.published', $tenantId, $version, array_filter([
                    'key' => $key->value,
                    'unit_id' => $unit?->id,
                    'version' => $version->version,
                    'value' => $version->value,
                    'delegable' => $version->delegable,
                    'min_value' => $version->min_value,
                    'max_value' => $version->max_value,
                    'incompatible_unit_ids' => $incompatible,
                ], fn ($value) => $value !== null && $value !== []));

                return $version;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::conflict();
        }
    }

    /**
     * A unit may only use a local value the network delegates, inside the network limits.
     * Removing the local value (back to the inherited one) is always allowed.
     */
    private static function checkLocal(PolicyKey $key, ?int $value): void
    {
        if ($value === null) {
            return;
        }

        $network = self::current($key, null);

        if ($network === null || ! $network->delegable) {
            throw ValidationException::withMessages(['value' => 'A rede não permite que a unidade altere esta regra.']);
        }

        if (! $network->allows($value)) {
            throw ValidationException::withMessages(['value' => 'O valor está fora dos limites definidos pela rede.']);
        }
    }

    /**
     * Units whose local value the new network version would override. Publishing over them needs explicit
     * confirmation; publish() then removes their local values.
     *
     * @param  array{value: int|null, delegable?: bool, min_value?: int|null, max_value?: int|null, confirm_incompatible?: bool}  $data
     * @return list<int>
     */
    private static function checkNetwork(PolicyKey $key, array $data): array
    {
        $candidate = new self([
            'delegable' => $data['delegable'] ?? false,
            'min_value' => $data['min_value'] ?? null,
            'max_value' => $data['max_value'] ?? null,
        ]);

        $incompatible = Unit::query()->orderBy('name')->get()->filter(function (Unit $unit) use ($key, $candidate) {
            $local = self::current($key, $unit->id);

            return $local?->value !== null && ! $candidate->allows($local->value);
        });

        if ($incompatible->isNotEmpty() && ! ($data['confirm_incompatible'] ?? false)) {
            throw ValidationException::withMessages([
                'confirm_incompatible' => 'Estas unidades passarão a usar o valor da rede: '.$incompatible->pluck('name')->join(', ').'. Confirme para publicar.',
            ]);
        }

        return array_values($incompatible->map(fn (Unit $unit) => $unit->id)->all());
    }

    private static function conflict(): ValidationException
    {
        return ValidationException::withMessages(['expected_version' => 'Outra pessoa publicou uma nova versão desta regra. Recarregue a página e revise antes de publicar.']);
    }
}
