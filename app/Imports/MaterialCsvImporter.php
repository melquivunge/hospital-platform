<?php

namespace App\Imports;

use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Material;
use App\Models\Medication;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CSV of the surgical materials (step 5b.6a), one line per material, identified by name as in the catalog's unique
 * index. An existing material is never changed by an import: same values are "identical", different values a
 * "conflict". Network-wide: only network admins.
 */
final class MaterialCsvImporter
{
    public const COLUMNS = ['material_name', 'stock_unit', 'code'];

    /**
     * @param  array<int, array<string, string|null>>  $rows
     * @param  Collection<int, Unit>  $allowedUnits  unused: the catalog belongs to the network
     */
    public static function preview(ImportBatch $batch, array $rows, Collection $allowedUnits): void
    {
        $seen = [];
        foreach ($rows as $line => $row) {
            [$status, $messages, $data] = self::classify($row, $seen);
            $batch->items()->create(['tenant_id' => $batch->tenant_id, 'line' => $line, 'data' => $data, 'status' => $status, 'messages' => $messages ?: null]);
        }
        $batch->update(['received' => count($rows)]);
    }

    /**
     * @param  list<int>|null  $allowedUnitIds  null only for network admins, the only ones who keep the catalog
     */
    public static function apply(ImportItem $item, ImportBatch $batch, ?array $allowedUnitIds): void
    {
        try {
            DB::transaction(function () use ($item, $batch, $allowedUnitIds) {
                $item = ImportItem::query()->lockForUpdate()->whereKey($item->id)->firstOrFail();
                if ($item->status !== 'ready') {
                    return;
                }
                if ($allowedUnitIds !== null) {
                    $item->update(['status' => 'conflict', 'messages' => ['Sem permissão para alterar o catálogo da rede no momento da aplicação.']]);

                    return;
                }
                $data = $item->data;
                if (self::existing($data) !== null) {
                    $item->update(['status' => 'conflict', 'messages' => ['O material foi cadastrado depois da prévia. Nada foi alterado.']]);

                    return;
                }
                $material = Material::create(['name' => $data['name'], 'stock_unit' => $data['stock_unit'], 'code' => $data['code']]);
                $item->update(['status' => 'created', 'record_id' => $material->id]);
                AuditEvent::record('material.created', $material->tenant_id, $material, ['stock_unit' => $material->stock_unit, 'import_batch_id' => $batch->id, 'line' => $item->line]);
            });
        } catch (UniqueConstraintViolationException) {
            ImportItem::query()->whereKey($item->id)->where('status', 'ready')->first()
                ?->update(['status' => 'conflict', 'messages' => ['O material foi cadastrado ao mesmo tempo. Nada foi alterado.']]);
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, true>  $seen
     * @return array{string, list<string>, array<string, mixed>}
     */
    private static function classify(array $row, array &$seen): array
    {
        $code = trim((string) $row['code']);
        $data = [
            'name' => Medication::clean((string) $row['material_name']),
            'stock_unit' => mb_strtolower(trim((string) $row['stock_unit'])),
            'code' => $code === '' ? null : $code,
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255', 'not_regex:/^[=+\-@\t\r]/'],
            'stock_unit' => ['required', Rule::in(Material::STOCK_UNITS)],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[0-9A-Za-z.\/-]+$/', 'not_regex:/^[=+\-@]/'],
        ], ['stock_unit.in' => 'stock_unit deve ser um destes: '.implode(', ', Material::STOCK_UNITS).'.'], ['name' => 'nome do material', 'stock_unit' => 'unidade de estoque', 'code' => 'código']);
        if ($validator->fails()) {
            return ['rejected', array_values(array_unique($validator->errors()->all())), $data];
        }
        $key = Medication::key($data['name']);
        if (isset($seen[$key])) {
            return ['rejected', ['Material repetido no arquivo.'], $data];
        }
        $seen[$key] = true;
        $existing = self::existing($data);
        if ($existing === null) {
            return ['ready', [], $data];
        }
        $differences = array_keys(array_filter([
            'unidade de estoque' => $existing->stock_unit !== $data['stock_unit'],
            'código' => $existing->code !== $data['code'],
        ]));

        return $differences === []
            ? ['identical', [], $data]
            : ['conflict', ['Material já cadastrado com valores diferentes ('.implode(', ', $differences).'). Nada foi alterado.'], $data];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function existing(array $data): ?Material
    {
        return Material::query()->where('name_key', Medication::key((string) $data['name']))->first();
    }
}
