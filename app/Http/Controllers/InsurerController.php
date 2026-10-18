<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Insurer;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Network insurers (step 3.5). No delete: deactivating hides an insurer from new arrivals only.
 */
class InsurerController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage', Insurer::class);

        return Inertia::render('insurers/index', [
            'insurers' => Insurer::query()->orderByDesc('active')->orderBy('name')->get(['id', 'name', 'ans_code', 'active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Insurer::class);
        $validated = $request->validateWithBag('newInsurer', self::rules(null), [], self::attributes());

        self::saving(fn () => DB::transaction(function () use ($validated) {
            $insurer = Insurer::create($validated);
            AuditEvent::record('insurer.created', $insurer->tenant_id, $insurer);
        }), 'newInsurer');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Convênio cadastrado.']);

        return to_route('insurers.index');
    }

    public function update(Request $request, Insurer $insurer): RedirectResponse
    {
        Gate::authorize('manage', Insurer::class);
        $bag = "insurer-{$insurer->id}";
        $validated = $request->validateWithBag($bag, [...self::rules($insurer), 'active' => ['required', 'boolean']], [], self::attributes());

        self::saving(fn () => DB::transaction(function () use ($validated, $insurer) {
            $insurer->fill($validated);
            $changed = array_values(array_diff(array_keys($insurer->getDirty()), ['updated_at']));
            if ($changed !== []) {
                $insurer->save();
                AuditEvent::record('insurer.updated', $insurer->tenant_id, $insurer, ['fields' => $changed]);
            }
        }), $bag);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Convênio atualizado.']);

        return to_route('insurers.index');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function rules(?Insurer $current): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($current) {
                if (Insurer::query()->whereRaw('lower(name) = lower(?)', [$value])->when($current, fn ($query) => $query->whereKeyNot($current->id))->exists()) {
                    $fail('Já existe um convênio com este nome.');
                }
            }],
            'ans_code' => ['nullable', 'regex:/^\d{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(): array
    {
        return ['name' => 'nome', 'ans_code' => 'registro ANS'];
    }

    private static function saving(callable $save, string $bag): void
    {
        try {
            $save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe um convênio com este nome.'])->errorBag($bag);
        }
    }
}
