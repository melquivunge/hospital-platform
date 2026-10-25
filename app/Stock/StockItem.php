<?php

namespace App\Stock;

use App\Models\Material;
use App\Models\Medication;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What a lot, a minimum or an inventory line holds (step 5b.6b, ADR-0009): a medication of the catalog or a
 * material, never both (CHECK in the database). The key "medication:5" or "material:3" groups rows by item.
 */
final class StockItem
{
    /**
     * The key of the item behind a row with medication_id / material_id (a model or a query row).
     */
    public static function key(object $row): string
    {
        $medication = $row->medication_id ?? null;

        return $medication !== null ? "medication:{$medication}" : 'material:'.($row->material_id ?? 0);
    }

    /**
     * @return array{medication_id: int|null, material_id: int|null}
     */
    public static function columns(Medication|Material $item): array
    {
        return ['medication_id' => $item instanceof Medication ? $item->id : null, 'material_id' => $item instanceof Material ? $item->id : null];
    }

    /**
     * The id that is set, for audit context: ['medication_id' => 5] or ['material_id' => 3].
     *
     * @return array<string, int>
     */
    public static function ids(object $row): array
    {
        $medication = $row->medication_id ?? null;

        return $medication !== null ? ['medication_id' => (int) $medication] : ['material_id' => (int) ($row->material_id ?? 0)];
    }

    /**
     * Name, unit of stock and controlled flag of the items behind the rows, by key, in two queries.
     *
     * @param  iterable<object>  $rows
     * @return Collection<string, array{name: string, stockUnit: string, controlled: bool, material: bool}>
     */
    public static function describe(iterable $rows): Collection
    {
        $rows = collect($rows);
        $medications = Medication::query()->whereKey($rows->pluck('medication_id')->filter()->unique()->values()->all())->get()->toBase()
            ->mapWithKeys(fn (Medication $medication) => ["medication:{$medication->id}" => self::row($medication)]);
        $materials = Material::query()->whereKey($rows->pluck('material_id')->filter()->unique()->values()->all())->get()->toBase()
            ->mapWithKeys(fn (Material $material) => ["material:{$material->id}" => self::row($material)]);

        return $medications->merge($materials);
    }

    /**
     * The active catalog for the forms: medications, then materials, each by name.
     *
     * @return list<array{key: string, name: string, stockUnit: string}>
     */
    public static function catalog(): array
    {
        $medications = Medication::query()->where('active', true)->orderBy('ingredient_key')->orderBy('form')->orderBy('strength')->get();
        $materials = Material::query()->where('active', true)->orderBy('name_key')->get();

        return array_values($medications->concat($materials)->map(fn (Medication|Material $item) => ['key' => self::key((object) self::columns($item)), 'name' => self::label($item), 'stockUnit' => $item->stock_unit])->all());
    }

    /**
     * Validation of the item a form chose: a medication or a material.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'medication_id' => ['nullable', 'integer', 'required_without:material_id', 'prohibits:material_id'],
            'material_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * The active item a form chose, or a validation error on the bag.
     *
     * @param  array<string, mixed>  $data  validated with rules()
     */
    public static function chosen(array $data, string $bag): Medication|Material
    {
        $item = isset($data['material_id'])
            ? Material::query()->where('active', true)->whereKey((int) $data['material_id'])->first()
            : Medication::query()->where('active', true)->whereKey((int) $data['medication_id'])->first();

        return $item ?? throw ValidationException::withMessages([isset($data['material_id']) ? 'material_id' : 'medication_id' => 'Escolha um medicamento ou material ativo do catálogo.'])->errorBag($bag);
    }

    public static function label(Medication|Material $item): string
    {
        return $item instanceof Medication ? "{$item->active_ingredient} {$item->strength} ({$item->form})" : $item->name;
    }

    /**
     * @return array{name: string, stockUnit: string, controlled: bool, material: bool}
     */
    private static function row(Medication|Material $item): array
    {
        return ['name' => self::label($item), 'stockUnit' => $item->stock_unit, 'controlled' => $item instanceof Medication && $item->controlled, 'material' => $item instanceof Material];
    }
}
