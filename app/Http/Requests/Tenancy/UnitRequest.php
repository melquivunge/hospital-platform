<?php

namespace App\Http\Requests\Tenancy;

use App\Models\Unit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unit = $this->route('unit');

        return $unit instanceof Unit
            ? $this->user()->can('update', $unit)
            : $this->user()->can('create', Unit::class);
    }

    /**
     * UF and timezone may be filled later; an incomplete unit cannot publish schedules (V1-02).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Unit|null $unit */
        $unit = $this->route('unit');

        return [
            'name' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($unit) {
                // Tenant-scoped query: duplicates only count inside the current client.
                $taken = Unit::query()
                    ->whereRaw('lower(name) = lower(?)', [$value])
                    ->when($unit, fn ($query) => $query->whereKeyNot($unit->id))
                    ->exists();

                if ($taken) {
                    $fail('Já existe uma unidade com este nome.');
                }
            }],
            'uf' => ['nullable', Rule::in(Unit::UFS)],
            'timezone' => ['nullable', 'timezone:per_country,BR'],
            // Step 6.5a: the unit's CNES, for the insurers' guides (TISS).
            'cnes' => ['nullable', 'regex:/^[0-9]{7}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['uf' => 'UF', 'timezone' => 'fuso horário', 'cnes' => 'CNES'];
    }
}
