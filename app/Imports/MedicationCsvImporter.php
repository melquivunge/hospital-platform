<?php

namespace App\Imports;

use App\Models\AuditEvent;
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
 * CSV of the medication catalog (step 4b.1b). A presentation is identified by active ingredient, form and
 * strength (as in the catalog's unique index). An existing presentation is never changed by an import:
 * same values are "identical", different values a "conflict". Network-wide: only network admins.
 */
final class MedicationCsvImporter
{
    public const COLUMNS = ['active_ingredient', 'form', 'strength', 'dose_unit', 'routes', 'controlled', 'high_alert', 'max_single_dose', 'max_daily_dose'];

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
                    $item->update(['status' => 'conflict', 'messages' => ['A apresentação foi cadastrada depois da prévia. Nada foi alterado.']]);

                    return;
                }
                $medication = Medication::create(array_intersect_key($data, array_flip(self::COLUMNS)))->refresh();
                $item->update(['status' => 'created', 'record_id' => $medication->id]);
                AuditEvent::record('medication.created', $medication->tenant_id, $medication, [
                    ...$medication->only('controlled', 'high_alert', 'max_single_dose', 'max_daily_dose', 'active'),
                    'import_batch_id' => $batch->id, 'line' => $item->line,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            ImportItem::query()->whereKey($item->id)->where('status', 'ready')->first()
                ?->update(['status' => 'conflict', 'messages' => ['A apresentação foi cadastrada ao mesmo tempo. Nada foi alterado.']]);
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, true>  $seen
     * @return array{string, list<string>, array<string, mixed>}
     */
    private static function classify(array $row, array &$seen): array
    {
        $data = [
            'active_ingredient' => Medication::clean((string) $row['active_ingredient']),
            'form' => Medication::clean((string) $row['form']),
            'strength' => Medication::clean((string) $row['strength']),
            // Canonical spelling ("ml" → "mL", "MG" → "mg").
            'dose_unit' => collect(Medication::DOSE_UNITS)->first(fn (string $unit) => mb_strtolower($unit) === mb_strtolower(trim((string) $row['dose_unit']))) ?? $row['dose_unit'],
            'routes' => array_values(array_filter(array_map(fn (string $route) => mb_strtolower(trim($route)), explode('|', (string) $row['routes'])))),
            'controlled' => CsvValues::flag($row['controlled']),
            'high_alert' => CsvValues::flag($row['high_alert']),
            'max_single_dose' => CsvValues::decimal($row['max_single_dose']),
            'max_daily_dose' => CsvValues::decimal($row['max_daily_dose']),
        ];
        $data['name'] = "{$data['active_ingredient']} {$data['strength']} ({$data['form']})";
        $text = ['required', 'string', 'not_regex:/^[=+\-@\t\r]/'];
        $validator = Validator::make($data, [
            'active_ingredient' => [...$text, 'max:255'],
            'form' => [...$text, 'max:100'],
            'strength' => [...$text, 'max:100'],
            'dose_unit' => ['required', Rule::in(Medication::DOSE_UNITS)],
            'routes' => ['required', 'array', 'min:1'],
            'routes.*' => ['distinct', Rule::in(array_keys(Medication::ROUTES))],
            'controlled' => ['required', 'boolean'],
            'high_alert' => ['required', 'boolean'],
            'max_single_dose' => ['nullable', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'max_daily_dose' => ['nullable', 'decimal:0,3', 'gt:0', 'max:999999999'],
        ], [
            'controlled.required' => 'controlled deve ser "sim", "não" ou vazio.',
            'high_alert.required' => 'high_alert deve ser "sim", "não" ou vazio.',
            'max_single_dose.decimal' => 'max_single_dose deve ser um número com até 3 casas decimais.',
            'max_daily_dose.decimal' => 'max_daily_dose deve ser um número com até 3 casas decimais.',
        ], ['active_ingredient' => 'princípio ativo', 'form' => 'forma', 'strength' => 'concentração', 'dose_unit' => 'unidade de dose', 'routes' => 'vias']);
        foreach (['max_single_dose', 'max_daily_dose'] as $field) {
            if (CsvValues::ambiguous($row[$field])) {
                $validator->after(fn ($validator) => $validator->errors()->add($field, "{$field} \"{$row[$field]}\" é ambíguo (milhar ou decimal?). Escreva sem separador de milhar, por exemplo 1000 ou 1,5."));
            }
        }
        if ($validator->fails()) {
            return ['rejected', array_values(array_unique($validator->errors()->all())), $data];
        }
        if ($data['max_single_dose'] !== null && $data['max_daily_dose'] !== null && (float) $data['max_daily_dose'] < (float) $data['max_single_dose']) {
            return ['rejected', ['A dose máxima por dia não pode ser menor que a dose máxima por administração.'], $data];
        }
        $key = self::presentation($data);
        if (isset($seen[$key])) {
            return ['rejected', ['Apresentação repetida no arquivo.'], $data];
        }
        $seen[$key] = true;
        $existing = self::existing($data);
        if ($existing === null) {
            return ['ready', [], $data];
        }
        $differences = self::differences($existing, $data);

        return $differences === []
            ? ['identical', [], $data]
            : ['conflict', ['Apresentação já cadastrada com valores diferentes ('.implode(', ', $differences).'). Nada foi alterado.'], $data];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function presentation(array $data): string
    {
        return Medication::key((string) $data['active_ingredient']).'|'.mb_strtolower((string) $data['form']).'|'.mb_strtolower((string) $data['strength']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function existing(array $data): ?Medication
    {
        return Medication::query()->where('ingredient_key', Medication::key((string) $data['active_ingredient']))
            ->whereRaw('lower(form) = lower(?)', [$data['form']])->whereRaw('lower(strength) = lower(?)', [$data['strength']])->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function differences(Medication $existing, array $data): array
    {
        $dose = fn (mixed $value) => $value === null ? null : (float) $value;
        $routes = $data['routes'];
        sort($routes);
        $current = $existing->routes;
        sort($current);

        return array_keys(array_filter([
            'unidade' => $existing->dose_unit !== $data['dose_unit'],
            'vias' => $current !== $routes,
            'controlado' => $existing->controlled !== $data['controlled'],
            'alto alerta' => $existing->high_alert !== $data['high_alert'],
            'dose máxima por administração' => $dose($existing->max_single_dose) !== $dose($data['max_single_dose']),
            'dose máxima por dia' => $dose($existing->max_daily_dose) !== $dose($data['max_daily_dose']),
        ]));
    }
}
