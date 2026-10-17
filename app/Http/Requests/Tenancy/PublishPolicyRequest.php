<?php

namespace App\Http\Requests\Tenancy;

use App\Governance\PolicyKey;
use App\Models\PolicyVersion;
use App\Models\Unit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Network version when the route has no unit; local value otherwise. Limits are checked again,
 * under lock, in PolicyVersion::publish().
 */
class PublishPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unit = $this->route('unit');

        return $unit instanceof Unit
            ? $this->user()->can('publishLocal', [PolicyVersion::class, $unit])
            : $this->user()->can('publishNetwork', PolicyVersion::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var PolicyKey $key */
        $key = $this->route('key');
        [$min, $max] = $key->bounds();
        $value = ['integer', "between:$min,$max"];
        $base = [
            'expected_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ];

        if ($this->route('unit') instanceof Unit) {
            // Empty value removes the local value: the unit goes back to the inherited one.
            return [...$base, 'value' => ['present', 'nullable', ...$value]];
        }

        return [
            ...$base,
            'value' => ['required', ...$value],
            'delegable' => ['boolean'],
            'min_value' => ['nullable', ...$value, 'lte:value'],
            'max_value' => ['nullable', ...$value, 'gte:value'],
            'confirm_incompatible' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'value' => 'valor',
            'min_value' => 'limite mínimo',
            'max_value' => 'limite máximo',
            'reason' => 'motivo',
        ];
    }
}
