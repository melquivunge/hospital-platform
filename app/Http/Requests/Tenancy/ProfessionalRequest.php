<?php

namespace App\Http\Requests\Tenancy;

use App\Models\Professional;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfessionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $professional = $this->route('professional');

        return $professional instanceof Professional
            ? $this->user()->can('update', $professional)
            : $this->user()->can('create', Professional::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'council' => is_string($this->input('council')) ? mb_strtoupper(trim($this->input('council'))) : $this->input('council'),
            'council_number' => is_string($this->input('council_number')) ? mb_strtoupper(trim($this->input('council_number'))) : $this->input('council_number'),
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Professional|null $professional */
        $professional = $this->route('professional');
        $baseChanges = $professional !== null && (string) $professional->base_unit_id !== (string) $this->input('base_unit_id');

        // Duplicate checks cover the whole tenant on purpose: identifiers must be unique in the network,
        // so a manager learns that a registration exists elsewhere (accepted, no data is returned).
        return [
            'name' => ['required', 'string', 'max:255'],
            // The member may only use a unit they manage as base unit (network admins: any unit of the tenant).
            'base_unit_id' => ['bail', 'required', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                $membership = UnitPolicy::currentMembership($this->user());

                if ($membership === null || ! Professional::manageableUnits($membership)->whereKey($value)->exists()) {
                    $fail('Escolha uma unidade que você administra.');
                }
            }],
            'base_unit_reason' => [Rule::requiredIf($baseChanges), 'nullable', 'string', 'max:1000'],
            'council' => ['nullable', 'required_with:council_number,council_uf', 'regex:/^[A-Z]{2,10}$/'],
            'council_number' => ['nullable', 'required_with:council,council_uf', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-\/]+$/'],
            'council_uf' => ['bail', 'nullable', 'required_with:council,council_number', Rule::in(Unit::UFS), function (string $attribute, mixed $value, Closure $fail) use ($professional) {
                $taken = Professional::query()
                    ->where('council', $this->input('council'))
                    ->where('council_number', $this->input('council_number'))
                    ->where('council_uf', $value)
                    ->when($professional, fn ($query) => $query->whereKeyNot($professional->id))
                    ->exists();

                if ($taken) {
                    $fail('Já existe um profissional com este registro no conselho.');
                }
            }],
            'external_id' => ['bail', 'nullable', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($professional) {
                $taken = Professional::query()
                    ->where('external_id', $value)
                    ->when($professional, fn ($query) => $query->whereKeyNot($professional->id))
                    ->exists();

                if ($taken) {
                    $fail('Já existe um profissional com este identificador externo.');
                }
            }],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ()+\-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'base_unit_id' => 'unidade-base',
            'base_unit_reason' => 'motivo da troca de unidade-base',
            'council' => 'conselho',
            'council_number' => 'número no conselho',
            'council_uf' => 'UF do conselho',
            'external_id' => 'identificador externo',
            'phone' => 'telefone',
        ];
    }
}
