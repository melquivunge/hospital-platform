<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Exam;
use App\Models\ExamAnalyte;
use App\Models\Medication;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The network exam catalog (step 5.1a, delivery 5): laboratory exams with their analytes (unit and reference
 * range) and imaging exams, kept by the network admin. Deactivated, never deleted; the type never changes, since
 * orders and results will refer to it. No patient data.
 */
class ExamController extends Controller
{
    private const LIMIT = 100;

    public function index(Request $request): Response
    {
        Gate::authorize('manage', Exam::class);
        // A non-text q (?q[]=x) is ignored rather than breaking the page.
        $search = is_string($q = $request->query('q')) ? Medication::key(mb_substr($q, 0, 255)) : '';

        return Inertia::render('exams/index', [
            'q' => $search,
            'total' => Exam::query()->count(),
            'limit' => self::LIMIT,
            'exams' => Exam::query()->with(['analytes' => fn ($query) => $query->where('active', true)])
                ->when($search !== '', fn (Builder $query) => $query->where('name_key', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orderByDesc('active')->orderBy('type')->orderBy('name_key')->limit(self::LIMIT)->get()
                ->map(fn (Exam $exam) => [
                    ...$exam->only('id', 'type', 'name', 'specimen', 'preparation', 'code', 'active'),
                    'analytes' => $exam->analytes->map->only('id', 'name', 'unit', 'reference_min', 'reference_max', 'reference_text')->values(),
                ])->values(),
            'types' => Exam::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Exam::class);
        $data = self::validated($request, 'newExam');

        self::unique(fn () => DB::transaction(function () use ($data) {
            $exam = Exam::create(collect($data)->except('analytes')->all());
            self::syncAnalytes($exam, $data['analytes'], 'newExam');
            AuditEvent::record('exam.created', $exam->tenant_id, $exam, ['type' => $exam->type, 'analytes' => count($data['analytes'])]);
        }), 'newExam');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Exame cadastrado.']);

        return back();
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        Gate::authorize('manage', Exam::class);
        $bag = "exam-{$exam->id}";
        $data = self::validated($request, $bag, $exam);

        self::unique(fn () => DB::transaction(function () use ($exam, $data, $bag) {
            $exam->fill(collect($data)->except('analytes')->all());
            $changed = array_values(array_diff(array_keys($exam->getDirty()), ['updated_at', 'name_key']));
            $exam->save();
            $analytes = self::syncAnalytes($exam, $data['analytes'], $bag);
            if ($changed !== [] || $analytes) {
                AuditEvent::record('exam.updated', $exam->tenant_id, $exam, ['fields' => $changed, 'analytes_changed' => $analytes, 'active' => $exam->active]);
            }
        }), $bag);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Exame atualizado.']);

        return back();
    }

    /**
     * The analytes of a laboratory exam, in the order given: an id updates that analyte of this exam, no id
     * creates one (or reactivates the removed analyte of the same name: results refer to it), and one left out is
     * deactivated. Names are unique per exam, removed analytes included. Whether anything changed.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function syncAnalytes(Exam $exam, array $rows, string $bag): bool
    {
        $existing = $exam->analytes()->lockForUpdate()->get()->keyBy('id');
        $byName = $existing->keyBy(fn (ExamAnalyte $analyte) => Medication::key($analyte->name));
        $listed = array_map('intval', array_filter(array_column($rows, 'id')));
        foreach ($rows as $position => $row) {
            $holder = $byName->get(Medication::key((string) $row['name']));
            if (! isset($row['id']) && $holder !== null && ! in_array($holder->id, $listed, true)) {
                // A removed (or left out) analyte of this name comes back instead of a duplicate.
                $rows[$position]['id'] = $holder->id;
                $listed[] = $holder->id;
            } elseif (isset($row['id']) && $holder !== null && $holder->id !== (int) $row['id'] && ! in_array($holder->id, $listed, true)) {
                throw ValidationException::withMessages(["analytes.{$position}.name" => 'Este nome é de um analito removido. Adicione-o como novo analito para reativá-lo.'])->errorBag($bag);
            }
        }
        $changed = false;
        foreach ($existing as $analyte) {
            if (! in_array($analyte->id, $listed, true) && $analyte->active) {
                $analyte->update(['active' => false]);
                $changed = true;
            }
        }
        // Names moving between listed analytes (a swap) pass through a provisional name first.
        foreach ($rows as $row) {
            $analyte = isset($row['id']) ? $existing->get((int) $row['id']) : null;
            if ($analyte !== null && Medication::key($analyte->name) !== Medication::key((string) $row['name'])) {
                $analyte->forceFill(['name' => "~{$analyte->id}"])->save();
            }
        }
        $text = fn (mixed $value) => $value === null ? null : Medication::clean((string) $value);
        foreach ($rows as $position => $row) {
            $values = ['name' => Medication::clean((string) $row['name']), 'unit' => $text($row['unit'] ?? null), 'reference_min' => $row['reference_min'] ?? null,
                'reference_max' => $row['reference_max'] ?? null, 'reference_text' => $text($row['reference_text'] ?? null), 'position' => $position + 1, 'active' => true];
            $analyte = isset($row['id']) ? $existing->get((int) $row['id']) : null;
            if ($analyte === null) {
                ExamAnalyte::create(['exam_id' => $exam->id, ...$values]);
                $changed = true;
            } else {
                $before = [Medication::key((string) $analyte->getOriginal('name')), $analyte->getOriginal('active')];
                $analyte->fill($values);
                $changed = $changed || $analyte->isDirty(['unit', 'reference_min', 'reference_max', 'reference_text', 'position', 'active']) || $before[0] !== Medication::key($values['name']) || ! $before[1];
                $analyte->save();
            }
        }

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function validated(Request $request, string $bag, ?Exam $exam = null): array
    {
        $type = $exam === null ? $request->input('type') : $exam->type;
        $data = $request->validateWithBag($bag, [
            ...($exam === null ? ['type' => ['required', Rule::in(array_keys(Exam::TYPES))]] : []),
            'name' => ['required', 'string', 'max:255'],
            'specimen' => ['required', 'string', 'max:100'],
            'preparation' => ['nullable', 'string', 'max:1000'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/'],
            ...($exam !== null ? ['active' => ['required', 'boolean']] : []),
            // Imaging exams have no analytes: the report is text.
            'analytes' => [$type === 'laboratory' ? 'nullable' : 'prohibited', 'array', 'max:50'],
            'analytes.*.id' => ['nullable', 'integer', 'distinct', Rule::in($exam?->analytes()->pluck('id')->all() ?? [])],
            'analytes.*.name' => ['bail', 'required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                $names = array_map(fn ($row) => is_array($row) && is_string($row['name'] ?? null) ? Medication::key($row['name']) : '', (array) $request->input('analytes', []));
                if (count(array_keys($names, Medication::key((string) $value), true)) > 1) {
                    $fail('Cada analito aparece uma vez.');
                }
            }],
            'analytes.*.unit' => ['nullable', 'string', 'max:30'],
            'analytes.*.reference_min' => ['nullable', 'numeric', 'between:-9999999999,9999999999'],
            'analytes.*.reference_max' => ['nullable', 'numeric', 'between:-9999999999,9999999999', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                $min = $request->input(str_replace('reference_max', 'reference_min', $attribute));
                if (is_numeric($min) && (float) $value < (float) $min) {
                    $fail('O máximo da referência não pode ser menor que o mínimo.');
                }
            }],
            'analytes.*.reference_text' => ['nullable', 'string', 'max:255'],
        ], ['analytes.prohibited' => 'Exame de imagem não tem analitos: o laudo é em texto.'], [
            'type' => 'tipo', 'name' => 'nome', 'specimen' => $type === 'imaging' ? 'região' : 'material', 'preparation' => 'preparo', 'code' => 'código TUSS/SIGTAP',
            'analytes.*.name' => 'nome do analito', 'analytes.*.unit' => 'unidade', 'analytes.*.reference_min' => 'mínimo da referência', 'analytes.*.reference_max' => 'máximo da referência',
            'analytes.*.reference_text' => 'referência em texto',
        ]);

        return [...$data, 'name' => Medication::clean($data['name']), 'specimen' => Medication::clean($data['specimen']),
            'preparation' => isset($data['preparation']) ? Medication::clean($data['preparation']) : null, 'analytes' => array_values($data['analytes'] ?? [])];
    }

    private static function unique(callable $save, string $bag): void
    {
        try {
            $save();
        } catch (UniqueConstraintViolationException $exception) {
            // Analyte names are resolved before saving; a collision there is a concurrent save of the same exam.
            throw ValidationException::withMessages(str_contains($exception->getMessage(), 'exam_analytes')
                ? ['analytes' => 'Os analitos foram alterados ao mesmo tempo por outra pessoa. Recarregue a página e tente de novo.']
                : ['name' => 'Já existe um exame deste tipo com este nome.'])->errorBag($bag);
        }
    }
}
