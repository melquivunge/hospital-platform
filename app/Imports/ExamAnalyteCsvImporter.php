<?php

namespace App\Imports;

use App\Models\AuditEvent;
use App\Models\Exam;
use App\Models\ExamAnalyte;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Medication;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * CSV of the analytes of laboratory exams already in the catalog (step 5.1b), one line per analyte, after the
 * exams CSV. An analyte is identified by exam and name (removed ones included, as in the unique index); an
 * existing one is never changed: "identical" or "conflict". Network-wide: only network admins.
 */
final class ExamAnalyteCsvImporter
{
    public const COLUMNS = ['exam_name', 'analyte', 'analyte_unit', 'reference_min', 'reference_max', 'reference_text'];

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
                $data = $item->data;
                $exam = Exam::query()->where('type', 'laboratory')->where('active', true)->whereKey((int) $data['exam_id'])->lockForUpdate()->first();
                $refusal = match (true) {
                    $allowedUnitIds !== null => 'Sem permissão para alterar o catálogo da rede no momento da aplicação.',
                    $exam === null => 'O exame foi desativado depois da prévia.',
                    self::existing($exam, (string) $data['analyte']) !== null => 'O analito foi cadastrado depois da prévia. Nada foi alterado.',
                    default => null,
                };
                if ($refusal !== null) {
                    $item->update(['status' => 'conflict', 'messages' => [$refusal]]);

                    return;
                }
                $analyte = ExamAnalyte::create([
                    'exam_id' => $exam->id, 'name' => $data['analyte'], 'unit' => $data['analyte_unit'], 'reference_min' => $data['reference_min'],
                    'reference_max' => $data['reference_max'], 'reference_text' => $data['reference_text'], 'position' => (int) $exam->analytes()->max('position') + 1,
                ]);
                $item->update(['status' => 'created', 'record_id' => $analyte->id]);
                AuditEvent::record('exam.updated', $exam->tenant_id, $exam, ['fields' => [], 'analytes_changed' => true, 'active' => $exam->active, 'import_batch_id' => $batch->id, 'line' => $item->line]);
            });
        } catch (UniqueConstraintViolationException) {
            ImportItem::query()->whereKey($item->id)->where('status', 'ready')->first()
                ?->update(['status' => 'conflict', 'messages' => ['O analito foi cadastrado ao mesmo tempo. Nada foi alterado.']]);
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, true>  $seen
     * @return array{string, list<string>, array<string, mixed>}
     */
    private static function classify(array $row, array &$seen): array
    {
        $optional = fn (?string $value) => trim((string) $value) === '' ? null : Medication::clean((string) $value);
        $data = [
            'exam_name' => Medication::clean((string) $row['exam_name']),
            'analyte' => Medication::clean((string) $row['analyte']),
            'analyte_unit' => $optional($row['analyte_unit']),
            'reference_min' => CsvValues::decimal($row['reference_min']),
            'reference_max' => CsvValues::decimal($row['reference_max']),
            'reference_text' => $optional($row['reference_text']),
        ];
        $data['name'] = "{$data['exam_name']} · {$data['analyte']}";
        $text = ['required', 'string', 'not_regex:/^[=+\-@\t\r]/'];
        $validator = Validator::make($data, [
            'exam_name' => [...$text, 'max:255'],
            'analyte' => [...$text, 'max:100'],
            'analyte_unit' => ['nullable', 'string', 'max:30', 'not_regex:/^[=+@\t\r]/'],
            'reference_min' => ['nullable', 'numeric', 'between:-9999999999,9999999999'],
            'reference_max' => ['nullable', 'numeric', 'between:-9999999999,9999999999'],
            'reference_text' => ['nullable', 'string', 'max:255', 'not_regex:/^[=+@\t\r]/'],
        ], [], ['exam_name' => 'nome do exame', 'analyte' => 'analito', 'analyte_unit' => 'unidade', 'reference_min' => 'mínimo da referência', 'reference_max' => 'máximo da referência', 'reference_text' => 'referência em texto']);
        foreach (['reference_min', 'reference_max'] as $field) {
            if (CsvValues::ambiguous($row[$field])) {
                $validator->after(fn ($validator) => $validator->errors()->add($field, "{$field} \"{$row[$field]}\" é ambíguo (milhar ou decimal?). Escreva sem separador de milhar, por exemplo 4000 ou 1,5."));
            }
        }
        if ($validator->fails()) {
            return ['rejected', array_values(array_unique($validator->errors()->all())), $data];
        }
        if ($data['reference_min'] !== null && $data['reference_max'] !== null && (float) $data['reference_max'] < (float) $data['reference_min']) {
            return ['rejected', ['O máximo da referência não pode ser menor que o mínimo.'], $data];
        }
        $exam = Exam::query()->where('type', 'laboratory')->where('active', true)->where('name_key', Medication::key($data['exam_name']))->first();
        if ($exam === null) {
            return ['rejected', ['Exame de laboratório fora do catálogo ativo. Importe ou cadastre o exame antes.'], $data];
        }
        $data['exam_id'] = $exam->id;
        $key = $exam->id.'|'.Medication::key($data['analyte']);
        if (isset($seen[$key])) {
            return ['rejected', ['Analito repetido no arquivo para este exame.'], $data];
        }
        $seen[$key] = true;
        $existing = self::existing($exam, $data['analyte']);
        if ($existing === null) {
            return ['ready', [], $data];
        }
        $number = fn (mixed $value) => $value === null ? null : (float) $value;
        $differences = array_keys(array_filter([
            'unidade' => $existing->unit !== $data['analyte_unit'],
            'mínimo' => $number($existing->reference_min) !== $number($data['reference_min']),
            'máximo' => $number($existing->reference_max) !== $number($data['reference_max']),
            'referência em texto' => $existing->reference_text !== $data['reference_text'],
            'ativo' => ! $existing->active,
        ]));

        return $differences === []
            ? ['identical', [], $data]
            : ['conflict', ['Analito já cadastrado com valores diferentes ('.implode(', ', $differences).'). Nada foi alterado.'], $data];
    }

    private static function existing(Exam $exam, string $name): ?ExamAnalyte
    {
        return ExamAnalyte::query()->where('exam_id', $exam->id)->whereRaw('lower(name) = lower(?)', [$name])->first();
    }
}
