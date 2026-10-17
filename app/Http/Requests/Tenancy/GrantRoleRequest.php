<?php

namespace App\Http\Requests\Tenancy;

use App\Models\Membership;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $membership = $this->route('membership');

        return $membership instanceof Membership && $this->user()->can('update', $membership);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::roleRules();
    }

    /**
     * Role plus unit, with the unit required, forbidden or optional as the role's scope demands.
     *
     * @return array<string, array<mixed>>
     */
    public static function roleRules(): array
    {
        $unitScoped = array_map(fn (Role $role) => $role->value, array_filter(Role::cases(), fn (Role $role) => $role->requiresUnit()));

        return [
            'role' => ['required', Rule::enum(Role::class)],
            'unit_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => in_array(request()->input('role'), $unitScoped, true)),
                Rule::prohibitedIf(fn () => request()->input('role') === Role::NetworkAdmin->value),
                Rule::exists('units', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['role' => 'papel', 'unit_id' => 'unidade'];
    }
}
