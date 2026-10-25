<?php

namespace App\Imports;

use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Medication;
use App\Models\SurgicalProcedure;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CSV of the surgical procedures (step 5b.1b), one line per procedure, identified by name as in the catalog's
 * unique index. An existing procedure is never changed by an import: same values are "identical", different
 * values a "conflict". Network-wide: only network admins. Operating rooms are few and kept on the screen.
 */
final class SurgicalProcedureCsvImporter
{
    public const COLUMNS = ['procedure_name', 'duration_minutes', 'size', 'code'];

    /** pequeno / médio / grande, in Portuguese or English. */
    public const SIZES = ['pequeno' => 'small', 'small' => 'small', 'medio' => 'medium', 'médio' => 'medium', 'medium' => 'medium', 'grande' => 'large', 'large' => 'large'];

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
                    $item->update(['status' => 'conflict', 'messages' => ['O procedimento foi cadastrado depois da prévia. Nada foi alterado.']]);

                    return;
                }
                $procedure = SurgicalProcedure::create(['name' => $data['name'], 'duration_minutes' => $data['duration_minutes'], 'size' => $data['size'], 'code' => $data['code']]);
                $item->update(['status' => 'created', 'record_id' => $procedure->id]);
                AuditEvent::record('surgical_procedure.created', $procedure->tenant_id, $procedure, ['size' => $procedure->size, 'import_batch_id' => $batch->id, 'line' => $item->line]);
            });
        } catch (UniqueConstraintViolationException) {
            ImportItem::query()->whereKey($item->id)->where('status', 'ready')->first()
                ?->update(['status' => 'conflict', 'messages' => ['O procedimento foi cadastrado ao mesmo tempo. Nada foi alterado.']]);
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, true>  $seen
     * @return array{string, list<string>, array<string, mixed>}
     */
    private static function classify(array $row, array &$seen): array
    {
        $duration = trim((string) $row['duration_minutes']);
        $minutes = filter_var($duration, FILTER_VALIDATE_INT);
        $code = trim((string) $row['code']);
        $data = [
            'name' => Medication::clean((string) $row['procedure_name']),
            'duration_minutes' => $minutes === false ? $duration : $minutes,
            'size' => self::SIZES[mb_strtolower(trim((string) $row['size']))] ?? null,
            'code' => $code === '' ? null : $code,
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255', 'not_regex:/^[=+\-@\t\r]/'],
            'duration_minutes' => ['required', 'integer', 'between:5,1440'],
            'size' => ['required', Rule::in(array_values(self::SIZES))],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/', 'not_regex:/^[=+\-@]/'],
        ], ['size.required' => 'size deve ser "pequeno", "médio" ou "grande".'], ['name' => 'nome do procedimento', 'duration_minutes' => 'duração prevista', 'code' => 'código TUSS/SIGTAP']);
        if ($validator->fails()) {
            return ['rejected', array_values(array_unique($validator->errors()->all())), $data];
        }
        $key = Medication::key($data['name']);
        if (isset($seen[$key])) {
            return ['rejected', ['Procedimento repetido no arquivo.'], $data];
        }
        $seen[$key] = true;
        $existing = self::existing($data);
        if ($existing === null) {
            return ['ready', [], $data];
        }
        $differences = array_keys(array_filter([
            'duração' => $existing->duration_minutes !== $data['duration_minutes'],
            'porte' => $existing->size !== $data['size'],
            'código' => $existing->code !== $data['code'],
        ]));

        return $differences === []
            ? ['identical', [], $data]
            : ['conflict', ['Procedimento já cadastrado com valores diferentes ('.implode(', ', $differences).'). Nada foi alterado.'], $data];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function existing(array $data): ?SurgicalProcedure
    {
        return SurgicalProcedure::query()->where('name_key', Medication::key((string) $data['name']))->first();
    }
}
