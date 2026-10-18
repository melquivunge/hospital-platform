<?php

namespace App\Imports;

use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\ImportIdentity;
use App\Models\ImportItem;
use App\Models\JobFunction;
use App\Models\Shift;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * CSV of shifts (step 5b). Lines become drafts through Shift::createDraft, so eligibility, overlap and
 * "already started" rules are the same as in the screen; importing never publishes.
 * Professionals are referenced only by their import identity in the same source, never by name.
 */
final class ShiftCsvImporter
{
    public const ENTITY = 'shift';

    public const COLUMNS = ['source_id', 'unit', 'sector', 'function', 'start', 'end', 'professional_source_id'];

    private const DATE_FORMAT = 'Y-m-d\TH:iP';

    /**
     * @param  array<int, array<string, string|null>>  $rows
     * @param  Collection<int, Unit>  $allowedUnits
     */
    public static function preview(ImportBatch $batch, array $rows, Collection $allowedUnits): void
    {
        $units = $allowedUnits->keyBy(fn (Unit $unit) => mb_strtolower($unit->name));
        $functions = JobFunction::query()->get(['id', 'name'])->keyBy(fn (JobFunction $function) => mb_strtolower($function->name));
        $seenIds = [];

        foreach ($rows as $line => $row) {
            [$status, $messages, $row] = self::classify($batch, $row, $units, $functions, $seenIds);

            $batch->items()->create(['tenant_id' => $batch->tenant_id, 'line' => $line, 'data' => $row, 'status' => $status, 'messages' => $messages ?: null]);
        }

        $batch->update(['received' => count($rows)]);
    }

    /**
     * @param  list<int>|null  $allowedUnitIds  revalidated by the job; null means every unit of the tenant
     */
    public static function apply(ImportItem $item, ImportBatch $batch, ?array $allowedUnitIds): void
    {
        try {
            self::applyLocked($item, $batch, $allowedUnitIds);
        } catch (UniqueConstraintViolationException) {
            // Another writer took the same identity meanwhile: everything rolled back, the line is a conflict.
            ImportItem::query()->whereKey($item->id)->where('status', 'ready')->first()
                ?->update(['status' => 'conflict', 'messages' => ['Este identificador foi importado ao mesmo tempo por outro processo.']]);
        }
    }

    /**
     * @param  list<int>|null  $allowedUnitIds
     */
    private static function applyLocked(ImportItem $item, ImportBatch $batch, ?array $allowedUnitIds): void
    {
        DB::transaction(function () use ($item, $batch, $allowedUnitIds) {
            $item = ImportItem::query()->lockForUpdate()->whereKey($item->id)->firstOrFail();

            if ($item->status !== 'ready') {
                return;
            }

            $data = $item->data;
            $unit = Unit::query()->whereKey($data['unit_id'] ?? 0)->first();

            if ($unit === null || ($allowedUnitIds !== null && ! in_array($unit->id, $allowedUnitIds, true))) {
                $item->update(['status' => 'conflict', 'messages' => ['Sem permissão para importar nesta unidade no momento da aplicação.']]);

                return;
            }

            if (self::identity($batch, (string) $data['source_id']) !== null) {
                $item->update(['status' => 'conflict', 'messages' => ['Os dados mudaram depois da prévia. Importe o arquivo de novo.']]);

                return;
            }

            try {
                // Same rules as the screen: eligibility under lock, overlap constraint, no past shifts.
                $shift = Shift::createDraft($unit, [
                    'sector_id' => (int) $data['sector_id'],
                    'job_function_id' => (int) $data['job_function_id'],
                    'professional_id' => $data['professional_id'] === null ? null : (int) $data['professional_id'],
                    'starts_at' => CarbonImmutable::parse((string) $data['starts_at']),
                    'ends_at' => CarbonImmutable::parse((string) $data['ends_at']),
                ]);
            } catch (ValidationException $exception) {
                $item->update(['status' => 'conflict', 'messages' => array_values($exception->validator->errors()->all())]);

                return;
            }

            ImportIdentity::create(['source_system' => $batch->source_system, 'entity_type' => self::ENTITY, 'original_id' => $data['source_id'], 'record_id' => $shift->id]);
            $item->update(['status' => 'created', 'record_id' => $shift->id]);
            AuditEvent::record('shift.imported', $shift->tenant_id, $shift, ['import_batch_id' => $batch->id, 'line' => $item->line]);
        });
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  Collection<string, Unit>  $units
     * @param  Collection<string, JobFunction>  $functions
     * @param  array<string, true>  $seenIds
     * @return array{string, list<string>, array<string, string|null>}
     */
    private static function classify(ImportBatch $batch, array $row, Collection $units, Collection $functions, array &$seenIds): array
    {
        $validator = Validator::make($row, [
            'source_id' => ['required', 'string', 'max:100', 'not_regex:/^[=+\-@\t\r]/'],
            'unit' => ['required', 'string'],
            'sector' => ['required', 'string'],
            'function' => ['required', 'string'],
            // Full date and time with offset (PRODUCT.md): 2026-11-10T07:00-03:00.
            'start' => ['required', 'date_format:'.self::DATE_FORMAT],
            'end' => ['required', 'date_format:'.self::DATE_FORMAT],
            'professional_source_id' => ['nullable', 'string', 'max:100'],
        ], attributes: ['unit' => 'unidade', 'sector' => 'setor', 'function' => 'função', 'start' => 'início', 'end' => 'fim', 'professional_source_id' => 'profissional']);

        if ($validator->fails()) {
            return ['rejected', array_values($validator->errors()->all()), $row];
        }

        $sourceId = (string) $row['source_id'];

        if (isset($seenIds[$sourceId])) {
            return ['rejected', ['source_id repetido no arquivo.'], $row];
        }

        $seenIds[$sourceId] = true;
        $unit = $units->get(mb_strtolower((string) $row['unit']));
        $sector = $unit?->sectors()->whereRaw('lower(name) = lower(?)', [$row['sector']])->first();
        $function = $functions->get(mb_strtolower((string) $row['function']));
        $professionalId = null;
        $pending = [];

        if ($unit === null) {
            $pending[] = 'Pendente: unidade não encontrada ou sem permissão para importar nela.';
        } elseif ($sector === null) {
            $pending[] = 'Pendente: setor não encontrado nesta unidade.';
        }

        if ($function === null) {
            $pending[] = 'Pendente: função não encontrada.';
        }

        if ($row['professional_source_id'] !== null) {
            // Only an identity from the same source links a person; a name never does.
            $professionalId = ImportIdentity::query()
                ->where('source_system', $batch->source_system)
                ->where('entity_type', ProfessionalCsvImporter::ENTITY)
                ->where('original_id', $row['professional_source_id'])
                ->value('record_id');

            if ($professionalId === null) {
                $pending[] = 'Pendente: profissional não importado por esta origem. Importe os profissionais antes.';
            }
        }

        if ($pending !== []) {
            return ['rejected', $pending, $row];
        }

        $start = CarbonImmutable::createFromFormat(self::DATE_FORMAT, (string) $row['start']) ?: null;
        $end = CarbonImmutable::createFromFormat(self::DATE_FORMAT, (string) $row['end']) ?: null;

        if ($start === null || $end === null || $start >= $end) {
            return ['rejected', ['O fim precisa ser posterior ao início.'], $row];
        }

        $row = [
            ...$row,
            'unit_id' => (string) $unit?->id,
            'sector_id' => (string) $sector?->id,
            'job_function_id' => (string) $function?->id,
            'professional_id' => $professionalId === null ? null : (string) $professionalId,
            'starts_at' => $start->utc()->toIso8601String(),
            'ends_at' => $end->utc()->toIso8601String(),
        ];

        $identity = self::identity($batch, $sourceId);

        if ($identity !== null) {
            $existing = Shift::query()->whereKey($identity->record_id)->first();
            $same = $existing !== null
                && (string) $existing->unit_id === $row['unit_id']
                && (string) $existing->sector_id === $row['sector_id']
                && (string) $existing->job_function_id === $row['job_function_id']
                && (string) $existing->professional_id === (string) $row['professional_id']
                && $existing->starts_at->equalTo($start)
                && $existing->ends_at->equalTo($end);

            return $same
                ? ['identical', [], $row]
                : ['conflict', ['Já importado com dados diferentes. Nada foi alterado; plantões publicados mudam só pelos fluxos aprovados.'], $row];
        }

        return ['ready', [], $row];
    }

    private static function identity(ImportBatch $batch, string $sourceId): ?ImportIdentity
    {
        return ImportIdentity::query()
            ->where('source_system', $batch->source_system)
            ->where('entity_type', self::ENTITY)
            ->where('original_id', $sourceId)
            ->first();
    }
}
