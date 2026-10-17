<?php

namespace App\Http\Controllers;

use App\Models\JobFunction;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Qualifications of a professional (step 3b). Requests start pending; the destination unit decides.
 */
class QualificationController extends Controller
{
    public function store(Request $request, Professional $professional): RedirectResponse
    {
        Gate::authorize('request', [Qualification::class, $professional]);

        // Tenant-scoped lookups: ids of another client simply do not exist here.
        $validated = $request->validateWithBag('qualification', [
            'unit_id' => ['bail', 'required', 'integer', fn (string $attribute, mixed $value, Closure $fail) => Unit::query()->whereKey($value)->exists() ? null : $fail('Unidade inválida.')],
            'job_function_id' => ['bail', 'required', 'integer', fn (string $attribute, mixed $value, Closure $fail) => JobFunction::query()->whereKey($value)->exists() ? null : $fail('Função inválida.')],
            'sector_ids' => ['bail', 'required', 'array', 'min:1', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                $ids = (array) $value;

                if (Sector::query()->where('unit_id', $request->integer('unit_id'))->whereKey($ids)->count() !== count($ids)) {
                    $fail('Escolha setores da unidade selecionada.');
                }
            }],
            'sector_ids.*' => ['integer', 'distinct'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ], attributes: ['unit_id' => 'unidade', 'job_function_id' => 'função', 'sector_ids' => 'setores', 'valid_from' => 'início da validade', 'valid_until' => 'fim da validade']);

        try {
            Qualification::request(
                $professional,
                Unit::query()->whereKey($request->integer('unit_id'))->firstOrFail(),
                [
                    'job_function_id' => (int) $validated['job_function_id'],
                    'valid_from' => $validated['valid_from'],
                    'valid_until' => $validated['valid_until'] ?? null,
                ],
                array_values(array_map(intval(...), $validated['sector_ids'])),
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['job_function_id' => 'Já existe uma habilitação ativa para esta função nesta unidade.'])->errorBag('qualification');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Habilitação solicitada. A unidade de destino precisa aprovar.']);

        return to_route('professionals.show', $professional);
    }

    public function transition(Request $request, Qualification $qualification): RedirectResponse
    {
        Gate::authorize('decide', $qualification);

        $validated = $request->validateWithBag("qualification-{$qualification->id}", [
            'status' => ['required', Rule::in([QualificationStatus::Approved->value, QualificationStatus::Suspended->value, QualificationStatus::Revoked->value])],
            'reason' => ['nullable', 'required_unless:status,approved', 'string', 'max:1000'],
        ], attributes: ['reason' => 'motivo']);

        $qualification->transitionTo(QualificationStatus::from($validated['status']), $validated['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Habilitação atualizada.']);

        return to_route('professionals.show', $qualification->professional_id);
    }
}
