<?php

namespace App\Imports;

use App\Http\Controllers\PharmacyController;
use App\Http\Controllers\StockController;
use App\Models\ImportBatch;
use App\Models\ImportIdentity;
use App\Models\ImportItem;
use App\Models\Material;
use App\Models\Medication;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Stock\StockItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * CSV of the initial stock balance (step 4c.1b, ADR-0007): each line is a lot entry into one of the units of the
 * importer's Pharmacy role, by the same rule as the screen (StockController::enter). The medication is found by its
 * presentation in the catalog. A line already imported from the same source into the same unit is "identical"
 * (the same lot, expiry, manufacturer and quantity) or a "conflict" (different content): reprocessing a file never
 * enters the same balance twice. Source ids are per unit: ERPs often restart them in each hospital. The material
 * balance (step 5b.6b2) extends it, finding the item by the material's name instead.
 */
class StockCsvImporter
{
    public const ENTITY = 'stock_entry';

    public const COLUMNS = ['source_id', 'unit', 'active_ingredient', 'form', 'strength', 'lot_code', 'expires_on', 'quantity', 'manufacturer'];

    /**
     * @param  array<int, array<string, string|null>>  $rows
     * @param  Collection<int, Unit>  $allowedUnits  the units of the importer's Pharmacy role
     */
    public static function preview(ImportBatch $batch, array $rows, Collection $allowedUnits): void
    {
        // Two units whose names differ only in case are ambiguous: neither is matched (null).
        $units = Unit::query()->whereKey($allowedUnits->pluck('id')->all())->get(['id', 'name', 'timezone'])
            ->groupBy(fn (Unit $unit) => mb_strtolower($unit->name))->map(fn (Collection $same) => $same->count() === 1 ? $same->first() : null);
        $seen = [];
        foreach ($rows as $line => $row) {
            [$status, $messages, $data] = static::classify($batch, $row, $units, $seen);
            $batch->items()->create(['tenant_id' => $batch->tenant_id, 'line' => $line, 'data' => $data, 'status' => $status, 'messages' => $messages ?: null]);
        }
        $batch->update(['received' => count($rows)]);
    }

    /**
     * @param  list<int>|null  $allowedUnitIds  the creator's Pharmacy units, checked again by the job
     */
    public static function apply(ImportItem $item, ImportBatch $batch, ?array $allowedUnitIds): void
    {
        try {
            DB::transaction(function () use ($item, $batch, $allowedUnitIds) {
                $item = ImportItem::query()->lockForUpdate()->whereKey($item->id)->firstOrFail();
                if ($item->status !== 'ready') {
                    return;
                }
                // The job applies as the batch's creator, whose Pharmacy role is read again for every line:
                // revoking it in the middle of a large file stops the remaining lines.
                $user = Auth::user();
                if (! $user instanceof User) {
                    throw new \LogicException('An import applies as its creator.');
                }
                $current = $user->deactivated_at === null ? PharmacyController::unitIds($user->activeMembershipIn($batch->tenant_id)) : [];
                $data = $item->data;
                $unit = Unit::query()->whereKey((int) $data['unit_id'])->first();
                $stocked = static::activeItem($data);
                $refusal = match (true) {
                    $unit === null || $allowedUnitIds === null || ! in_array($unit->id, $allowedUnitIds, true) || ! in_array($unit->id, $current, true) => 'Sem o papel Farmácia nesta unidade no momento da aplicação.',
                    $stocked === null => static::INACTIVE,
                    $data['expires_on'] < now($unit->timezone ?? 'UTC')->toDateString() => 'O lote venceu depois da prévia.',
                    self::identity($batch, (int) $data['unit_id'], (string) $data['source_id']) !== null => 'Esta linha já foi importada. Nada foi alterado.',
                    default => null,
                };
                if ($refusal !== null) {
                    $item->update(['status' => 'conflict', 'messages' => [$refusal]]);

                    return;
                }
                try {
                    $movement = StockController::enter($unit->id, $stocked, (string) $data['lot_code'], (string) $data['expires_on'], $data['manufacturer'], (int) $data['quantity'], $user,
                        ['import_batch_id' => $batch->id, 'line' => $item->line]);
                } catch (ValidationException $exception) {
                    // A different manufacturer: refused before anything was written.
                    $item->update(['status' => 'conflict', 'messages' => array_values($exception->validator->errors()->all())]);

                    return;
                }
                ImportIdentity::create(['source_system' => $batch->source_system, 'entity_type' => static::ENTITY, 'original_id' => self::originalId((int) $unit->id, (string) $data['source_id']), 'record_id' => $movement->id]);
                $item->update(['status' => 'created', 'record_id' => $movement->id]);
            });
        } catch (UniqueConstraintViolationException) {
            ImportItem::query()->whereKey($item->id)->where('status', 'ready')->first()
                ?->update(['status' => 'conflict', 'messages' => ['Esta linha foi importada ao mesmo tempo por outro processo. Nada foi alterado.']]);
        }
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  Collection<string, Unit>  $units
     * @param  array<string, true>  $seen
     * @return array{string, list<string>, array<string, mixed>}
     */
    protected static function classify(ImportBatch $batch, array $row, Collection $units, array &$seen): array
    {
        $expiry = trim((string) $row['expires_on']);
        // Also the Brazilian 31/05/2027.
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $expiry, $parts) === 1) {
            $expiry = "{$parts[3]}-{$parts[2]}-{$parts[1]}";
        }
        $data = [
            'source_id' => trim((string) $row['source_id']),
            'unit' => trim((string) $row['unit']),
            ...static::itemData($row),
            'lot_code' => StockLot::code((string) $row['lot_code']),
            'expires_on' => $expiry,
            'quantity' => trim((string) $row['quantity']),
            'manufacturer' => trim((string) $row['manufacturer']) === '' ? null : Medication::clean((string) $row['manufacturer']),
        ];
        $data['name'] = static::itemName($data)." · lote {$data['lot_code']}";
        $text = ['required', 'string', 'not_regex:/^[=+\-@\t\r]/'];
        $validator = Validator::make($data, [
            // Stored as "unit:source_id" in the 100 characters of the identity.
            'source_id' => [...$text, 'max:80'],
            'unit' => ['required', 'string'],
            ...static::itemRules($text),
            'lot_code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9.\/\-]*$/'],
            'expires_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->addYears(10)->toDateString()],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'manufacturer' => ['nullable', 'string', 'max:100', 'not_regex:/^[=+\-@\t\r]/'],
        ], [
            'lot_code.regex' => 'O lote aceita letras sem acento, números, ponto, barra e hífen.',
            'expires_on.date_format' => 'A validade deve estar no formato 2027-05-31 ou 31/05/2027.',
        ], ['unit' => 'unidade', 'active_ingredient' => 'princípio ativo', 'form' => 'forma', 'strength' => 'concentração', 'material_name' => 'material', 'lot_code' => 'lote', 'expires_on' => 'validade', 'quantity' => 'quantidade', 'manufacturer' => 'fabricante']);
        if ($validator->fails()) {
            return ['rejected', array_values(array_unique($validator->errors()->all())), $data];
        }
        if (! $units->has($key = mb_strtolower($data['unit']))) {
            return ['rejected', ['Unidade fora das unidades em que você tem o papel Farmácia.'], $data];
        }
        $unit = $units->get($key);
        if ($unit === null) {
            return ['rejected', ['Há mais de uma unidade com este nome. Escreva o nome exatamente como no cadastro.'], $data];
        }
        if ($data['expires_on'] < now($unit->timezone ?? 'UTC')->toDateString()) {
            return ['rejected', ['Lote vencido não entra no estoque.'], $data];
        }
        $item = static::catalogItem($data);
        if ($item === null || ! $item->active) {
            return ['rejected', [static::OUTSIDE], $data];
        }
        if (isset($seen["{$unit->id}:{$data['source_id']}"])) {
            return ['rejected', ['source_id repetido no arquivo para esta unidade.'], $data];
        }
        $seen["{$unit->id}:{$data['source_id']}"] = true;
        $data = [...$data, 'unit_id' => $unit->id, ...array_filter(StockItem::columns($item)), 'quantity' => (int) $data['quantity']];
        $identity = self::identity($batch, $unit->id, (string) $data['source_id']);
        if ($identity !== null) {
            $movement = StockMovement::query()->find($identity->record_id);
            $lot = $movement === null ? null : StockLot::query()->find($movement->stock_lot_id);
            $same = $lot !== null && [StockItem::key($lot), $lot->lot_code, $lot->expires_on->toDateString(), $movement->quantity] === [StockItem::key((object) StockItem::columns($item)), $data['lot_code'], $data['expires_on'], $data['quantity']]
                && ($data['manufacturer'] === null || mb_strtolower((string) $lot->manufacturer) === mb_strtolower((string) $data['manufacturer']));

            return $same ? ['identical', [], $data] : ['conflict', ['Este source_id já foi importado nesta unidade com outros dados. Nada foi alterado; para corrigir o saldo, use o inventário.'], $data];
        }
        $lot = StockLot::query()->where(['unit_id' => $unit->id, ...StockItem::columns($item), 'lot_code' => $data['lot_code'], 'expires_on' => $data['expires_on']])->first();
        if ($lot !== null && $data['manufacturer'] !== null && mb_strtolower((string) $lot->manufacturer) !== mb_strtolower((string) $data['manufacturer'])) {
            return ['conflict', [$lot->manufacturer === null ? 'Este lote já foi cadastrado sem fabricante.' : "Este lote já está cadastrado com o fabricante {$lot->manufacturer}."], $data];
        }

        return ['ready', [], $data];
    }

    /** Refusal of a line whose item left the active catalog after the preview. */
    protected const INACTIVE = 'O medicamento foi desativado depois da prévia.';

    /** Refusal of a line whose item is not in the active catalog. */
    protected const OUTSIDE = 'Medicamento fora do catálogo ativo (princípio ativo, forma e concentração como no catálogo).';

    /**
     * The item's fields of a line, normalized.
     *
     * @param  array<string, string|null>  $row
     * @return array<string, string>
     */
    protected static function itemData(array $row): array
    {
        return [
            'active_ingredient' => Medication::clean((string) $row['active_ingredient']),
            'form' => Medication::clean((string) $row['form']),
            'strength' => Medication::clean((string) $row['strength']),
        ];
    }

    /**
     * @param  list<string>  $text  the rules of a required text that is no formula
     * @return array<string, list<string>>
     */
    protected static function itemRules(array $text): array
    {
        return ['active_ingredient' => [...$text, 'max:255'], 'form' => [...$text, 'max:100'], 'strength' => [...$text, 'max:100']];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function itemName(array $data): string
    {
        return "{$data['active_ingredient']} {$data['strength']} ({$data['form']})";
    }

    /**
     * The catalog item the line names, active or not.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function catalogItem(array $data): Medication|Material|null
    {
        return MedicationCsvImporter::existing($data);
    }

    /**
     * The previewed item, if still active.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function activeItem(array $data): Medication|Material|null
    {
        return Medication::query()->where('active', true)->whereKey((int) ($data['medication_id'] ?? 0))->first();
    }

    private static function identity(ImportBatch $batch, int $unitId, string $sourceId): ?ImportIdentity
    {
        return ImportIdentity::query()->where(['source_system' => $batch->source_system, 'entity_type' => static::ENTITY, 'original_id' => self::originalId($unitId, $sourceId)])->first();
    }

    private static function originalId(int $unitId, string $sourceId): string
    {
        return "{$unitId}:{$sourceId}";
    }
}
