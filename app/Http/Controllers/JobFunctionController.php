<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\JobFunction;
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
 * Network catalog of job functions (step 3b). No delete: qualifications and shifts reference them.
 */
class JobFunctionController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage', JobFunction::class);

        return Inertia::render('job-functions/index', [
            'functions' => JobFunction::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', JobFunction::class);
        $validated = $request->validateWithBag('newFunction', ['name' => self::nameRules(null)]);

        self::saving(fn () => DB::transaction(function () use ($validated) {
            $function = JobFunction::create($validated);
            AuditEvent::record('job_function.created', $function->tenant_id, $function);
        }), 'newFunction');

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Função cadastrada.']);

        return to_route('job-functions.index');
    }

    public function update(Request $request, JobFunction $jobFunction): RedirectResponse
    {
        Gate::authorize('manage', JobFunction::class);
        $bag = "function-{$jobFunction->id}";
        $validated = $request->validateWithBag($bag, ['name' => self::nameRules($jobFunction)]);

        self::saving(fn () => DB::transaction(function () use ($validated, $jobFunction) {
            $jobFunction->fill($validated);

            if ($jobFunction->isDirty()) {
                $jobFunction->save();
                AuditEvent::record('job_function.updated', $jobFunction->tenant_id, $jobFunction);
            }
        }), $bag);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Função atualizada.']);

        return to_route('job-functions.index');
    }

    /**
     * @return list<mixed>
     */
    private static function nameRules(?JobFunction $current): array
    {
        return ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($current) {
            $taken = JobFunction::query()
                ->whereRaw('lower(name) = lower(?)', [$value])
                ->when($current, fn ($query) => $query->whereKeyNot($current->id))
                ->exists();

            if ($taken) {
                $fail('Já existe uma função com este nome.');
            }
        }];
    }

    private static function saving(callable $save, string $bag): void
    {
        try {
            $save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'Já existe uma função com este nome.'])->errorBag($bag);
        }
    }
}
