<?php

namespace App\Http\Requests\Tenancy;

use App\Models\Membership;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class InvitePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Membership::class);
    }

    protected function prepareForValidation(): void
    {
        // Identity is global and case-insensitive.
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            ...GrantRoleRequest::roleRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'role' => 'papel', 'unit_id' => 'unidade'];
    }
}
