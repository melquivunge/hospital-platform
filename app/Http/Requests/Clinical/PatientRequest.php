<?php

namespace App\Http\Requests\Clinical;

use App\Clinical\Ethnicity;
use App\Clinical\PatientIdentity;
use App\Models\Patient;
use App\Policies\PatientPolicy;
use App\Policies\UnitPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient
            ? $this->user()->can('update', $patient)
            : $this->user()->can('create', Patient::class);
    }

    protected function prepareForValidation(): void
    {
        $text = fn (string $key) => is_string($this->input($key)) && trim($this->input($key)) !== '' ? trim($this->input($key)) : null;

        $this->merge([
            'cpf' => is_string($this->input('cpf')) ? PatientIdentity::digits($this->input('cpf')) : $this->input('cpf'),
            'cns' => is_string($this->input('cns')) ? PatientIdentity::digits($this->input('cns')) : $this->input('cns'),
            'email' => $text('email') === null ? null : mb_strtolower($text('email')),
            ...collect(['social_name', 'mother_name', 'phone', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'birth_date', 'sex', 'race_color', 'ethnicity'])
                ->mapWithKeys(fn (string $key) => [$key => $text($key)])->all(),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Patient|null $patient */
        $patient = $this->route('patient');
        $unique = fn (string $field, string $message) => function (string $attribute, mixed $value, Closure $fail) use ($patient, $field, $message) {
            if (Patient::query()->where($field, $value)->when($patient, fn ($query) => $query->whereKeyNot($patient->id))->exists()) {
                $fail($message);
            }
        };

        return [
            'registered_unit_id' => ['bail', 'required', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                $membership = UnitPolicy::currentMembership($this->user());

                if ($membership === null || ! in_array((int) $value, PatientPolicy::registeringUnitIds($membership), true)) {
                    $fail('Escolha uma unidade em que você atende ou recepciona.');
                }
            }],
            'name' => ['required', 'string', 'max:255'],
            'social_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'sex' => ['nullable', Rule::in(Patient::SEXES)],
            // Unique in the whole network: one person, one registration (accepted: a duplicate message tells
            // that the document is registered, no data is returned).
            'cpf' => ['bail', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! PatientIdentity::validCpf((string) $value)) {
                    $fail('CPF inválido.');
                }
            }, $unique('cpf', 'Já existe um paciente com este CPF. Busque o cadastro existente.')],
            'cns' => ['bail', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! PatientIdentity::validCns((string) $value)) {
                    $fail('Cartão Nacional de Saúde inválido.');
                }
            }, $unique('cns', 'Já existe um paciente com este Cartão Nacional de Saúde. Busque o cadastro existente.')],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ()+\-]+$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'guardian_name' => ['nullable', 'required_with:guardian_relationship', 'string', 'max:255'],
            'guardian_relationship' => ['nullable', 'required_with:guardian_name', 'string', 'max:60'],
            'guardian_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ()+\-]+$/'],
            // Step 6b.6a1: self-declared race/color; the ethnicity only (and always) for 05, indigenous.
            'race_color' => ['nullable', Rule::in(array_keys(Patient::RACE_COLORS))],
            'ethnicity' => ['exclude_unless:race_color,05', 'required', Rule::in(array_keys(Ethnicity::all()))],
            'confirm_new' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'registered_unit_id' => 'unidade',
            'name' => 'nome',
            'social_name' => 'nome social',
            'birth_date' => 'data de nascimento',
            'sex' => 'sexo',
            'mother_name' => 'nome da mãe',
            'phone' => 'telefone',
            'guardian_name' => 'nome do responsável',
            'guardian_relationship' => 'parentesco do responsável',
            'guardian_phone' => 'telefone do responsável',
            'race_color' => 'raça/cor',
            'ethnicity' => 'etnia',
        ];
    }
}
