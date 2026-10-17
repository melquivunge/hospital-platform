<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Models\AuditEvent;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * Accounts linked to an institution are deactivated by an administrator, never self-deleted:
     * audit trails must keep their author (V1-14).
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $user = $this->user();

                if ($user->memberships()->exists() || AuditEvent::where('actor_user_id', $user->id)->exists()) {
                    $validator->errors()->add('password', 'Conta vinculada a uma instituição. Solicite a desativação ao administrador.');
                }
            },
        ];
    }
}
