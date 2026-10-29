<?php

namespace App\Http\Requests\Tenancy;

use App\Clinical\AihCodes;
use App\Models\Sector;
use App\Models\Unit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Step 6b.6b2b1: the SUS bed specialty of the official table (the AIH takes the stay's sector's).
            'sus_bed_specialty' => ['nullable', Rule::in(array_map('strval', array_keys(AihCodes::bedSpecialties())))],
        ];
    }
}
