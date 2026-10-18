<?php

namespace App\Imports;

use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\ImportIdentity;
use App\Models\ImportItem;
use App\Models\Professional;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CSV of professionals (step 5a). Preview writes only import items; professionals are created after
 * confirmation, one line per transaction, so a failure leaves every line accounted for and resumable.
 */
final class ProfessionalCsvImporter
{
    public const ENTITY = 'professional';

    public const COLUMNS = ['source_id', 'name', 'base_unit', 'council', 'council_number', 'council_uf', 'email', 'phone'];

    /**
     * Classifies every row without touching professionals: ready, identical, conflict or rejected.
     *
     * @param  array<int, array<string, string|null>>  $rows
     * @param  Collection<int, Unit>  $allowedUnits  units the importer may use as base unit
     */
    public static function preview(ImportBatch $batch, array $rows, Collection $allowedUnits): void
    {
        $units = $allowedUnits->groupBy(fn (Unit $unit) => mb_strtolower($unit->name));
        $seenIds = [];
        $seenRegistrations = [];

        foreach ($rows as $line => $row) {
            $row = self::normalize($row);
            [$status, $messages] = self::classify($batch, $row, $units, $seenIds, $seenRegistrations);

            $batch->items()->create(['tenant_id' => $batch->tenant_id, 'line' => $line, 'data' => $row, 'status' => $status, 'messages' => $messages ?: null]);
        }

        $batch->update(['received' => count($rows)]);
    }

    /**
     * Creates the professional of one ready item. Safe to run twice: the item row is locked and only
     * a still-ready item is applied.
     */
    /**
     * @param  list<int>|null  $allowedUnitIds  revalidated by the job; null means every unit of the tenant
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
                $unit = Unit::query()->whereKey($data['base_unit_id'] ?? 0)->first();

                if ($unit === null || ($allowedUnitIds !== null && ! in_array($unit->id, $allowedUnitIds, true))) {
                    $item->update(['status' => 'conflict', 'messages' => ['Sem permissão para importar nesta unidade no momento da aplicação.']]);

                    return;
                }

                if (self::identity($batch, (string) $data['source_id']) !== null) {
                    $item->update(['status' => 'conflict', 'messages' => ['Os dados mudaram depois da prévia. Importe o arquivo de novo.']]);

                    return;
                }

                $professional = Professional::create([
                    'base_unit_id' => $unit->id,
                    'name' => $data['name'],
                    'council' => $data['council'],
                    'council_number' => $data['council_number'],
                    'council_uf' => $data['council_uf'],
                    'external_id' => $data['source_id'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                ]);
                ImportIdentity::create(['source_system' => $batch->source_system, 'entity_type' => self::ENTITY, 'original_id' => $data['source_id'], 'record_id' => $professional->id]);
                $item->update(['status' => 'created', 'record_id' => $professional->id]);

                AuditEvent::record('professional.created', $professional->tenant_id, $professional, [
                    'base_unit_id' => $unit->id,
                    'import_batch_id' => $batch->id,
                    'line' => $item->line,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A manual registration or another import took the same identifiers meanwhile.
            ImportItem::query()->whereKey($item->id)->where('status', 'ready')->first()
                ?->update(['status' => 'conflict', 'messages' => ['Já existe um profissional com estes identificadores.']]);
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, string|null>
     */
    private static function normalize(array $row): array
    {
        foreach (['council', 'council_number', 'council_uf'] as $field) {
            $row[$field] = $row[$field] === null ? null : mb_strtoupper($row[$field]);
        }

        $row['email'] = $row['email'] === null ? null : mb_strtolower($row['email']);

        return $row;
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  Collection<string, Collection<int, Unit>>  $units
     * @param  array<string, true>  $seenIds
     * @param  array<string, true>  $seenRegistrations
     * @return array{string, list<string>}
     */
    private static function classify(ImportBatch $batch, array &$row, Collection $units, array &$seenIds, array &$seenRegistrations): array
    {
        $validator = Validator::make($row, [
            // Values starting like a spreadsheet formula are rejected (they would reach future exports).
            'source_id' => ['required', 'string', 'max:100', 'not_regex:/^[=+\-@\t\r]/'],
            'name' => ['required', 'string', 'max:255', 'not_regex:/^[=+\-@\t\r]/'],
            'base_unit' => ['required', 'string'],
            'council' => ['nullable', 'required_with:council_number,council_uf', 'regex:/^[A-Z]{2,10}$/'],
            'council_number' => ['nullable', 'required_with:council,council_uf', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-\/]+$/'],
            'council_uf' => ['nullable', 'required_with:council,council_number', Rule::in(Unit::UFS)],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ()+\-]+$/'],
        ], attributes: [
            'source_id' => 'source_id', 'name' => 'nome', 'base_unit' => 'unidade-base', 'council' => 'conselho',
            'council_number' => 'número no conselho', 'council_uf' => 'UF do conselho', 'phone' => 'telefone',
        ]);

        if ($validator->fails()) {
            return ['rejected', array_values($validator->errors()->all())];
        }

        // Names of units are unique per tenant (case-insensitive); no name means no association.
        $matches = $units->get(mb_strtolower((string) $row['base_unit']));

        if ($matches === null || $matches->count() !== 1) {
            return ['rejected', ['Unidade-base não encontrada ou sem permissão para importar nela.']];
        }

        $row['base_unit_id'] = (string) $matches->first()?->id;
        $sourceId = (string) $row['source_id'];
        $registration = $row['council'] === null ? null : "{$row['council']}|{$row['council_number']}|{$row['council_uf']}";

        if (isset($seenIds[$sourceId])) {
            return ['rejected', ['source_id repetido no arquivo.']];
        }

        $seenIds[$sourceId] = true;

        if ($registration !== null && isset($seenRegistrations[$registration])) {
            return ['rejected', ['Registro no conselho repetido no arquivo.']];
        }

        if ($registration !== null) {
            $seenRegistrations[$registration] = true;
        }

        $identity = self::identity($batch, $sourceId);

        if ($identity !== null) {
            $existing = Professional::query()->whereKey($identity->record_id)->first();
            $differences = $existing === null ? ['registro'] : self::differences($existing, $row);

            return $differences === []
                ? ['identical', []]
                : ['conflict', ['Já importado com dados diferentes ('.implode(', ', $differences).'). Nada foi alterado.']];
        }

        $manual = Professional::query()
            ->where(fn ($query) => $query
                ->where('external_id', $sourceId)
                ->when($registration !== null, fn ($query) => $query->orWhere(fn ($query) => $query
                    ->where('council', $row['council'])
                    ->where('council_number', $row['council_number'])
                    ->where('council_uf', $row['council_uf']))))
            ->exists();

        if ($manual) {
            return ['conflict', ['Já existe um profissional com este identificador ou registro, cadastrado fora desta origem. Nada foi associado automaticamente.']];
        }

        return ['ready', []];
    }

    /**
     * @param  array<string, string|null>  $row
     * @return list<string>
     */
    private static function differences(Professional $existing, array $row): array
    {
        $fields = [
            'nome' => [$existing->name, $row['name']],
            'unidade-base' => [(string) $existing->base_unit_id, $row['base_unit_id']],
            'registro' => [
                "{$existing->council}|{$existing->council_number}|{$existing->council_uf}",
                "{$row['council']}|{$row['council_number']}|{$row['council_uf']}",
            ],
            'e-mail' => [$existing->email, $row['email']],
            'telefone' => [$existing->phone, $row['phone']],
        ];

        return array_keys(array_filter($fields, fn (array $pair) => (string) $pair[0] !== (string) $pair[1]));
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
