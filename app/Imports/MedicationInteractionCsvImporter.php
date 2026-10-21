<?php

namespace App\Imports;

use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Medication;
use App\Models\MedicationInteraction;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CSV of interactions between active ingredients (step 4b.1b). Both ingredients must already be in the
 * catalog (import the medications first). A pair already registered is never changed by an import.
 */
final class MedicationInteractionCsvImporter
{
    public const COLUMNS = ['ingredient_a', 'ingredient_b', 'severity', 'note'];

    /**
     * @param  array<int, array<string, string|null>>  $rows
     * @param  Collection<int, Unit>  $allowedUnits  unused: the rules belong to the network
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
     * @param  list<int>|null  $allowedUnitIds  null only for network admins
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
                    $item->update(['status' => 'conflict', 'messages' => ['Sem permissão para alterar as regras da rede no momento da aplicação.']]);

                    return;
                }
                $data = $item->data;
                if (self::existing($data['ingredient_a'], $data['ingredient_b']) !== null) {
                    $item->update(['status' => 'conflict', 'messages' => ['A interação foi cadastrada depois da prévia. Nada foi alterado.']]);

                    return;
                }
                $interaction = MedicationInteraction::create([
                    'ingredient_a' => $data['ingredient_a'], 'ingredient_b' => $data['ingredient_b'], 'severity' => $data['severity'],
                    'note' => $data['note'], 'created_by_user_id' => $batch->created_by_user_id,
                ]);
                $item->update(['status' => 'created', 'record_id' => $interaction->id]);
                AuditEvent::record('medication_interaction.created', $interaction->tenant_id, $interaction, [
                    'pair' => [$interaction->ingredient_a, $interaction->ingredient_b], 'severity' => $interaction->severity,
                    'import_batch_id' => $batch->id, 'line' => $item->line,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            ImportItem::query()->whereKey($item->id)->where('status', 'ready')->first()
                ?->update(['status' => 'conflict', 'messages' => ['A interação foi cadastrada ao mesmo tempo. Nada foi alterado.']]);
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, true>  $seen
     * @return array{string, list<string>, array<string, mixed>}
     */
    private static function classify(array $row, array &$seen): array
    {
        $pair = [Medication::key((string) $row['ingredient_a']), Medication::key((string) $row['ingredient_b'])];
        sort($pair, SORT_STRING);
        $severity = mb_strtolower(trim((string) $row['severity']));
        $data = [
            'ingredient_a' => $pair[0], 'ingredient_b' => $pair[1],
            // Code or Portuguese label ("grave" or "severe").
            'severity' => array_search($severity, array_map('mb_strtolower', Medication::SEVERITIES), true) ?: $severity,
            'note' => Medication::clean((string) $row['note']),
        ];
        $data['name'] = "{$pair[0]} + {$pair[1]}";
        $text = ['required', 'string', 'max:255', 'not_regex:/^[=+\-@\t\r]/'];
        $validator = Validator::make($data, [
            'ingredient_a' => $text,
            'ingredient_b' => $text,
            'severity' => ['required', Rule::in(array_keys(Medication::SEVERITIES))],
            'note' => ['required', 'string', 'max:500', 'not_regex:/^[=+\-@\t\r]/'],
        ], ['severity.in' => 'severity deve ser leve, moderada, grave ou contraindicada.'], ['note' => 'orientação']);
        if ($validator->fails()) {
            return ['rejected', array_values(array_unique($validator->errors()->all())), $data];
        }
        if ($pair[0] === $pair[1]) {
            return ['rejected', ['Os dois princípios ativos são iguais.'], $data];
        }
        $known = Medication::query()->whereIn('ingredient_key', $pair)->distinct()->pluck('ingredient_key')->all();
        $missing = array_values(array_diff($pair, $known));
        if ($missing !== []) {
            return ['rejected', ['Fora do catálogo: '.implode(', ', $missing).'. Importe os medicamentos antes.'], $data];
        }
        if (isset($seen[$data['name']])) {
            return ['rejected', ['Par repetido no arquivo.'], $data];
        }
        $seen[$data['name']] = true;
        $existing = self::existing($pair[0], $pair[1]);
        if ($existing === null) {
            return ['ready', [], $data];
        }

        return $existing->severity === $data['severity'] && Medication::clean($existing->note) === $data['note']
            ? ['identical', [], $data]
            : ['conflict', ['Interação já cadastrada com gravidade ou orientação diferente. Nada foi alterado.'], $data];
    }

    private static function existing(string $a, string $b): ?MedicationInteraction
    {
        return MedicationInteraction::query()->where('ingredient_a', $a)->where('ingredient_b', $b)->first();
    }
}
