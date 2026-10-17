<?php

namespace App\Http\Requests\Tenancy;

use App\Models\Sector;
use App\Models\Unit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sector = $this->route('sector');

        return $sector instanceof Sector
            ? $this->user()->can('update', $sector)
            : $this->user()->can('create', [Sector::class, $this->route('unit')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Unit $unit */
        $unit = $this->route('unit');
        /** @var Sector|null $sector */
        $sector = $this->route('sector');

        return [
            'name' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($unit, $sector) {
                $taken = $unit->sectors()
                    ->whereRaw('lower(name) = lower(?)', [$value])
                    ->when($sector, fn ($query) => $query->whereKeyNot($sector->id))
                    ->exists();

                if ($taken) {
                    $fail('Já existe um setor com este nome nesta unidade.');
                }
            }],
        ];
    }
}
