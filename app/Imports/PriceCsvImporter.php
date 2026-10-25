<?php

namespace App\Imports;

use App\Http\Controllers\BillingPriceController;
use App\Models\BillingItem;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Medication;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * CSV of prices (step 6.1c, ADR-0010): one line per price of a billable item in a table from a day on, by the same
 * rule as the screen (BillingPriceController::enterPrice). The table is found by name, the item by its code (or by
 * its name when it has none). A line whose price already exists is "identical"; one that would start on or before an
 * existing price of the item is a "conflict". Network-wide: only network admins.
 */
final class PriceCsvImporter
{
    public const COLUMNS = ['price_table', 'item_code', 'item_name', 'price', 'valid_from'];

    /**
     * @param  array<int, array<string, string|null>>  $rows
     * @param  Collection<int, Unit>  $allowedUnits  unused: prices belong to the network
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
     * @param  list<int>|null  $allowedUnitIds  null only for network admins, the only ones who keep the prices
     */
    public static function apply(ImportItem $item, ImportBatch $batch, ?array $allowedUnitIds): void
    {
        DB::transaction(function () use ($item, $batch, $allowedUnitIds) {
            $item = ImportItem::query()->lockForUpdate()->whereKey($item->id)->firstOrFail();
            if ($item->status !== 'ready') {
                return;
            }
            $user = Auth::user();
            $data = $item->data;
            $table = PriceTable::query()->where('active', true)->find((int) $data['price_table_id']);
            $billing = BillingItem::query()->where('active', true)->find((int) $data['billing_item_id']);
            $refusal = match (true) {
                $allowedUnitIds !== null || ! $user instanceof User => 'Sem permissão para alterar os preços da rede no momento da aplicação.',
                $table === null || $billing === null => 'A tabela ou o item foi desativado depois da prévia.',
                default => null,
            };
            if ($refusal !== null) {
                $item->update(['status' => 'conflict', 'messages' => [$refusal]]);

                return;
            }
            try {
                $entry = BillingPriceController::enterPrice($table, $billing->id, (int) $data['price_cents'], (string) $data['valid_from'], $user, ['import_batch_id' => $batch->id, 'line' => $item->line]);
            } catch (ValidationException $exception) {
                $item->update(['status' => 'conflict', 'messages' => array_values($exception->validator->errors()->all())]);

                return;
            }
            $item->update(['status' => 'created', 'record_id' => $entry->id]);
        });
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, true>  $seen
     * @return array{string, list<string>, array<string, mixed>}
     */
    private static function classify(array $row, array &$seen): array
    {
        $from = trim((string) $row['valid_from']);
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $from, $parts) === 1) {
            $from = "{$parts[3]}-{$parts[2]}-{$parts[1]}";
        }
        $code = trim((string) $row['item_code']);
        $data = [
            'price_table' => Medication::clean((string) $row['price_table']),
            'item_code' => $code === '' ? null : $code,
            'item_name' => Medication::clean((string) $row['item_name']),
            'price' => trim((string) $row['price']),
            'valid_from' => $from,
        ];
        $data['name'] = "{$data['item_name']} · {$data['price_table']} · {$data['valid_from']}";
        $validator = Validator::make($data, [
            'price_table' => ['required', 'string', 'max:100', 'not_regex:/^[=+\-@\t\r]/'],
            'item_code' => ['nullable', 'string', 'max:20'],
            'item_name' => ['required_without:item_code', 'nullable', 'string', 'max:255', 'not_regex:/^[=+\-@\t\r]/'],
            'price' => ['required', 'string', 'max:20'],
            'valid_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(5)->toDateString()],
        ], ['valid_from.date_format' => 'A vigência deve estar no formato 2027-05-31 ou 31/05/2027.'], ['price_table' => 'tabela', 'item_code' => 'código', 'item_name' => 'item', 'price' => 'preço', 'valid_from' => 'início da vigência']);
        if ($validator->fails()) {
            return ['rejected', array_values(array_unique($validator->errors()->all())), $data];
        }
        $cents = PriceEntry::cents($data['price']);
        if ($cents === null) {
            return ['rejected', ['Escreva o preço em reais, como 1.234,56.'], $data];
        }
        $table = PriceTable::query()->where('active', true)->whereRaw('lower(name) = ?', [mb_strtolower($data['price_table'])])->first();
        if ($table === null) {
            return ['rejected', ['Tabela de preço ativa não encontrada (nome como na tela "Preços").'], $data];
        }
        $items = BillingItem::query()->where('active', true)
            ->when($data['item_code'] !== null, fn ($query) => $query->where('code', $data['item_code']), fn ($query) => $query->whereRaw('lower(name) = ?', [mb_strtolower($data['item_name'])]))->limit(2)->get();
        if ($items->count() !== 1) {
            return ['rejected', [$items->isEmpty() ? 'Item cobrável ativo não encontrado (código ou nome como na tela "Preços").' : 'Há mais de um item com este código ou nome. Use um código único.'], $data];
        }
        $billing = $items->first();
        if (isset($seen["{$table->id}:{$billing->id}:{$data['valid_from']}"])) {
            return ['rejected', ['Preço repetido no arquivo para este item, tabela e data.'], $data];
        }
        $seen["{$table->id}:{$billing->id}:{$data['valid_from']}"] = true;
        $data = [...$data, 'price_table_id' => $table->id, 'billing_item_id' => $billing->id, 'price_cents' => $cents];
        $existing = PriceEntry::query()->where(['price_table_id' => $table->id, 'billing_item_id' => $billing->id])->where('valid_from', '>=', $data['valid_from'])->orderBy('valid_from')->first();
        if ($existing === null) {
            return ['ready', [], $data];
        }

        return $existing->valid_from->toDateString() === $data['valid_from'] && $existing->price_cents === $cents
            ? ['identical', [], $data]
            : ['conflict', ['Já há um preço deste item nesta tabela a partir desta data ou depois. Nada foi alterado.'], $data];
    }
}
