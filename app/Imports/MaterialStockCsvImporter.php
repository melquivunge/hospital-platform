<?php

namespace App\Imports;

use App\Models\Material;
use App\Models\Medication;

/**
 * CSV of the initial balance of materials (step 5b.6b2, ADR-0009): the medication balance import of step 4c.1b
 * with the material found by its name in the catalog. Its source ids are kept apart from the medications'.
 */
final class MaterialStockCsvImporter extends StockCsvImporter
{
    public const ENTITY = 'material_stock_entry';

    public const COLUMNS = ['source_id', 'unit', 'material_name', 'lot_code', 'expires_on', 'quantity', 'manufacturer'];

    protected const INACTIVE = 'O material foi desativado depois da prévia.';

    protected const OUTSIDE = 'Material fora do catálogo ativo (nome como no catálogo).';

    protected static function itemData(array $row): array
    {
        return ['material_name' => Medication::clean((string) $row['material_name'])];
    }

    protected static function itemRules(array $text): array
    {
        return ['material_name' => [...$text, 'max:255']];
    }

    protected static function itemName(array $data): string
    {
        return (string) $data['material_name'];
    }

    protected static function catalogItem(array $data): ?Material
    {
        return MaterialCsvImporter::existing(['name' => $data['material_name']]);
    }

    protected static function activeItem(array $data): ?Material
    {
        return Material::query()->where('active', true)->whereKey((int) ($data['material_id'] ?? 0))->first();
    }
}
