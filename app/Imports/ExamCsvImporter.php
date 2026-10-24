<?php

namespace App\Imports;

use App\Models\AuditEvent;
use App\Models\Exam;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Medication;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CSV of the exam catalog (step 5.1b), one line per exam; the analytes of laboratory exams come in their own CSV
 * (ExamAnalyteCsvImporter). An exam is identified by type and name, as in the catalog's unique index. An existing
 * exam is never changed by an import: same values are "identical", different values a "conflict". Network-wide:
 * only network admins.
 */
final class ExamCsvImporter
{
    public const COLUMNS = ['exam_type', 'exam_name', 'specimen', 'preparation', 'code'];

    /** laboratório / imagem, in Portuguese or English. */
    public const TYPES = ['laboratorio' => 'laboratory', 'laboratório' => 'laboratory', 'laboratory' => 'laboratory', 'imagem' => 'imaging', 'imaging' => 'imaging'];

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
                    $item->update(['status' => 'conflict', 'messages' => ['O exame foi cadastrado depois da prévia. Nada foi alterado.']]);

                    return;
                }
                $exam = Exam::create(['type' => $data['type'], 'name' => $data['exam_name'], 'specimen' => $data['specimen'], 'preparation' => $data['preparation'], 'code' => $data['code']]);
                $item->update(['status' => 'created', 'record_id' => $exam->id]);
                AuditEvent::record('exam.created', $exam->tenant_id, $exam, ['type' => $exam->type, 'analytes' => 0, 'import_batch_id' => $batch->id, 'line' => $item->line]);
            });
        } catch (UniqueConstraintViolationException) {
            ImportItem::query()->whereKey($item->id)->where('status', 'ready')->first()
                ?->update(['status' => 'conflict', 'messages' => ['O exame foi cadastrado ao mesmo tempo. Nada foi alterado.']]);
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
            'type' => self::TYPES[mb_strtolower(trim((string) $row['exam_type']))] ?? null,
            'exam_name' => Medication::clean((string) $row['exam_name']),
            'specimen' => Medication::clean((string) $row['specimen']),
            'preparation' => $optional($row['preparation']),
            'code' => $optional($row['code']),
        ];
        $data['name'] = $data['exam_name'];
        $text = ['required', 'string', 'not_regex:/^[=+\-@\t\r]/'];
        $validator = Validator::make($data, [
            'type' => ['required', Rule::in(array_values(self::TYPES))],
            'exam_name' => [...$text, 'max:255'],
            'specimen' => [...$text, 'max:100'],
            'preparation' => ['nullable', 'string', 'max:1000', 'not_regex:/^[=+\-@\t\r]/'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/'],
        ], ['type.required' => 'exam_type deve ser "laboratório" ou "imagem".'], ['exam_name' => 'nome do exame', 'specimen' => 'material ou região', 'preparation' => 'preparo', 'code' => 'código TUSS/SIGTAP']);
        if ($validator->fails()) {
            return ['rejected', array_values(array_unique($validator->errors()->all())), $data];
        }
        $key = $data['type'].'|'.Medication::key($data['exam_name']);
        if (isset($seen[$key])) {
            return ['rejected', ['Exame repetido no arquivo.'], $data];
        }
        $seen[$key] = true;
        $existing = self::existing($data);
        if ($existing === null) {
            return ['ready', [], $data];
        }
        $differences = array_keys(array_filter([
            'material ou região' => mb_strtolower($existing->specimen) !== mb_strtolower($data['specimen']),
            'preparo' => $existing->preparation !== $data['preparation'],
            'código' => $existing->code !== $data['code'],
        ]));

        return $differences === []
            ? ['identical', [], $data]
            : ['conflict', ['Exame já cadastrado com valores diferentes ('.implode(', ', $differences).'). Nada foi alterado.'], $data];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function existing(array $data): ?Exam
    {
        return Exam::query()->where('type', $data['type'])->where('name_key', Medication::key((string) $data['exam_name']))->first();
    }
}
