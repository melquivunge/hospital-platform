<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\ExamAnalyte;
use App\Models\ExamCriticalAlert;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\ExamResult;
use App\Models\ExamResultValue;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\User;
use App\Models\Visit;
use App\Policies\UnitPolicy;
use Carbon\CarbonInterface;
use finfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Exam results (step 5.4, ADR-0008). The exam team of the item's type in the order's unit releases the result of a
 * collected or performed item, signed with the password: values per analyte (laboratory), a report and a PDF. An
 * imaging report is released only by a professional with CRM (decision of 24/10/2026). A released result never
 * changes; a rectification is the next version, with its reason. The orderer is told without clinical data.
 */
class ExamResultController extends Controller
{
    public function release(Request $request, ExamOrderItem $item): RedirectResponse
    {
        $order = ExamOrder::query()->findOrFail($item->exam_order_id);
        self::releaser($request->user(), $order, $item);
        $bag = "result{$item->id}";
        $data = $request->validateWithBag($bag, [
            'version' => ['required', 'integer', 'min:1', 'max:1000'],
            'reason' => ['nullable', 'required_unless:version,1', 'string', 'max:500'],
            'report' => ['nullable', 'string', 'max:10000'],
            'values' => ['nullable', 'array', 'max:100'],
            'values.*' => ['nullable', 'string', 'max:100'],
            'file' => ['nullable', 'file', 'max:'.ExamResult::MAX_KILOBYTES, 'extensions:pdf'],
            'remove_attachment' => ['nullable', 'boolean'],
            'critical' => ['nullable', 'boolean'],
            'password' => ['required', 'string'],
        ], [
            'reason.required_unless' => 'Informe o motivo da retificação.',
            'file.uploaded' => 'O arquivo não chegou inteiro. Envie um PDF de até 10 MB.',
            'file.max' => 'O arquivo passa de 10 MB.',
        ], ['reason' => 'motivo', 'report' => $item->exam_type === 'imaging' ? 'laudo' : 'observações', 'file' => 'arquivo', 'password' => 'senha']);

        $values = $item->exam_type === 'laboratory' ? self::values($item, $data['values'] ?? [], $bag) : [];
        $report = trim((string) ($data['report'] ?? '')) ?: null;
        $file = $request->file('file');
        $file = $file instanceof UploadedFile ? $file : null;
        if ($file !== null && (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) !== 'application/pdf') {
            throw ValidationException::withMessages(['file' => 'O conteúdo do arquivo não é um PDF válido.'])->errorBag($bag);
        }
        // A rectification keeps the current PDF unless a new one replaces it or it is removed (a wrong file).
        $previous = $item->results()->latest('version')->first();
        $keep = $file === null && $previous?->attachment_path !== null && ! ($data['remove_attachment'] ?? false);
        if ($values === [] && $report === null && $file === null && ! $keep) {
            throw ValidationException::withMessages(['report' => $item->exam_type === 'imaging' ? 'Escreva o laudo ou anexe o PDF.' : 'Informe ao menos um valor, as observações ou o PDF.'])->errorBag($bag);
        }
        try {
            VisitController::confirmPassword($request, $data['password'], 'exam_result.sign_failed', $item, 'Senha incorreta. O resultado não foi liberado.');
        } catch (ValidationException $e) {
            throw $e->errorBag($bag);
        }

        $path = null;
        if ($file !== null) {
            $path = $file->storeAs("clinical/{$order->tenant_id}/exam-results", Str::random(40).'.pdf', 'local');
            if ($path === false) {
                throw new RuntimeException('Exam result attachment could not be stored.');
            }
        }
        try {
            $version = DB::transaction(function () use ($request, $order, $item, $data, $values, $report, $file, $path, $keep, $bag) {
                // The order, then the item, as the collection and the cancellation: one release per version wins.
                $order = ExamOrder::query()->lockForUpdate()->findOrFail($order->id);
                $item = ExamOrderItem::query()->lockForUpdate()->findOrFail($item->id);
                $professional = self::releaser($request->user(), $order, $item);
                if ($order->status !== 'requested') {
                    throw ValidationException::withMessages(['report' => 'Este pedido foi cancelado.'])->errorBag($bag);
                }
                if ($item->collected_at === null) {
                    throw ValidationException::withMessages(['report' => $item->exam_type === 'imaging' ? 'Registre a realização antes do laudo.' : 'Registre a coleta antes do resultado.'])->errorBag($bag);
                }
                $next = (int) $item->results()->max('version') + 1;
                if ((int) $data['version'] !== $next) {
                    throw ValidationException::withMessages(['report' => $next === 2 && (int) $data['version'] === 1
                        ? 'Este resultado já foi liberado por outra pessoa. Confira antes de retificar.'
                        : 'O resultado mudou enquanto você editava. Confira a versão atual.'])->errorBag($bag);
                }
                // The version check above guarantees the kept PDF is the current version's.
                $kept = $keep ? $item->results()->where('version', $next - 1)->first() : null;
                $result = ExamResult::query()->create([
                    'patient_id' => $order->patient_id, 'exam_order_id' => $order->id, 'exam_order_item_id' => $item->id, 'version' => $next,
                    'rectification_reason' => $next > 1 ? trim((string) $data['reason']) : null, 'report' => $report,
                    'attachment_name' => $file === null ? $kept?->attachment_name : ClinicalDocumentController::cleanName($file->getClientOriginalName()),
                    'attachment_path' => $path ?? $kept?->attachment_path, 'attachment_size' => $file?->getSize() ?? $kept?->attachment_size,
                    'attachment_sha256' => $file === null ? $kept?->attachment_sha256 : hash_file('sha256', $file->getRealPath()),
                    'released_by_user_id' => $request->user()->id, 'professional_id' => $professional?->id,
                    'signature_method' => 'password', 'released_at' => now(), 'value_count' => count($values),
                    'critical' => (bool) ($data['critical'] ?? false),
                ]);
                // Step 5.5: a critical result waits for the orderer's acknowledgement.
                if ($result->critical) {
                    $result->criticalAlert()->create(['tenant_id' => $result->tenant_id, 'unit_id' => $order->unit_id, 'created_at' => now()]);
                }
                foreach ($values as $value) {
                    $result->values()->create(['tenant_id' => $result->tenant_id, ...$value]);
                }
                AuditEvent::record('exam_result.released', $order->tenant_id, $result, [
                    'unit_id' => $order->unit_id, 'exam_order_id' => $order->id, 'version' => $next, 'critical' => $result->critical,
                    'author_user_id' => $order->author_user_id, 'patient_id' => $order->patient_id,
                ]);

                return $next;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $version > 1 ? 'Resultado retificado.' : 'Resultado liberado.']);

        return back();
    }

    /**
     * Step 5.5: the acknowledgement of a critical result, once, by whoever ordered it or attends the patient (an
     * open visit or the admission's team).
     */
    public function acknowledge(Request $request, ExamResult $result): RedirectResponse
    {
        $order = ExamOrder::query()->findOrFail($result->exam_order_id);
        abort_unless(self::acknowledger($request->user(), $order), 403);
        abort_unless($result->critical, 404);
        DB::transaction(function () use ($request, $result, $order) {
            // This version's alert and the open ones of earlier versions of the same item: whoever acknowledges the
            // current result has seen the item's history, so a rectified critical never escalates on its own.
            $alerts = ExamCriticalAlert::query()->whereIn('exam_result_id', ExamResult::query()->where('exam_order_item_id', $result->exam_order_item_id)
                ->where('version', '<=', $result->version)->select('id'))->orderBy('id')->lockForUpdate()->get();
            $own = $alerts->firstWhere('exam_result_id', $result->id) ?? abort(404);
            if ($own->acknowledged_at !== null) {
                throw ValidationException::withMessages(["acknowledge{$result->id}" => 'A ciência deste resultado já foi registrada.']);
            }
            $events = collect();
            foreach ($alerts->whereNull('acknowledged_at') as $alert) {
                /** @var ExamCriticalAlert $alert */
                $alert->update(['acknowledged_at' => now(), 'acknowledged_by_user_id' => $request->user()->id]);
                $subject = $alert->exam_result_id === $result->id ? $result : ExamResult::query()->findOrFail($alert->exam_result_id);
                // One event per result: the inbox delivery reads it to send late notices already read.
                AuditEvent::record('exam_result.critical_acknowledged', $result->tenant_id, $subject, ['unit_id' => $order->unit_id, 'escalated' => $alert->escalated_at !== null]);
                $events = $events->concat(AuditEvent::query()->where(fn ($query) => $query
                    ->where(fn ($release) => $release->where('action', 'exam_result.released')->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->id))
                    ->orWhere(fn ($escalation) => $escalation->where('action', 'exam_result.critical_unacknowledged')->where('subject_type', $alert->getMorphClass())->where('subject_id', $alert->id)))
                    ->pluck('id'));
            }
            // Resolved: the urgent notices of these results (the orderer's and the managers') stop calling.
            DB::table('user_notifications')->whereIn('audit_event_id', $events->all())->where('urgent', true)->whereNull('read_at')->update(['read_at' => now()]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ciência registrada.']);

        return back();
    }

    /**
     * Whoever ordered the exam, or attends the patient now (step 5.5).
     */
    public static function acknowledger(User $user, ExamOrder $order): bool
    {
        if ($order->author_user_id === $user->id) {
            return true;
        }
        $patient = Patient::query()->findOrFail($order->patient_id);

        return ClinicalAccess::activeVisit($user, $patient) !== null || ClinicalAccess::activeAdmission($user, $patient) !== null;
    }

    public function download(Request $request, ExamResult $result): StreamedResponse
    {
        $patient = Patient::query()->findOrFail($result->patient_id);
        $order = ExamOrder::query()->findOrFail($result->exam_order_id);
        $item = ExamOrderItem::query()->findOrFail($result->exam_order_item_id);
        // Whoever reads the record; and the team of the item's type in the unit, which may rectify it (ADR-0008).
        $basis = ClinicalAccess::activeVisit($request->user(), $patient) ?? ClinicalAccess::activeAdmission($request->user(), $patient)
            ?? ClinicalAccess::activeEmergency($request->user(), $patient) ?? ClinicalAccess::activeExamOrder($request->user(), $patient)
            ?? (in_array($item->exam_type, ExamQueueController::typesIn(ClinicalAccess::examScopes(UnitPolicy::currentMembership($request->user())), $order->unit_id), true) ? $order : abort(403));
        abort_if($result->attachment_path === null, 404);
        abort_unless(str_starts_with($result->attachment_path, "clinical/{$result->tenant_id}/") && ! str_contains($result->attachment_path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($result->attachment_path), 404);

        ClinicalAccess::recordRead($request->user(), $patient, $basis);
        AuditEvent::record('exam_result.downloaded', $result->tenant_id, $result, ['reason' => match (true) {
            $basis instanceof Visit => 'visit',
            $basis instanceof Admission => 'admission',
            $basis instanceof ExamOrder => 'exam',
            default => 'emergency',
        }]);

        return Storage::disk('local')->download($result->attachment_path, (string) $result->attachment_name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * The released results of a record, the latest version of each item first, newest first, with the previous
     * versions. Callers must have checked ClinicalAccess; `$attends` says the user attends the patient now, so they
     * may acknowledge a critical result (step 5.5), as the orderer always may.
     *
     * @param  list<int>  $patientIds
     * @return list<array<string, mixed>>
     */
    public static function listing(array $patientIds, string $timezone, ?User $user = null, bool $attends = false): array
    {
        $results = ExamResult::query()->whereIn('patient_id', $patientIds)->with(['values', 'criticalAlert'])->get();
        $items = ExamOrderItem::query()->whereKey($results->pluck('exam_order_item_id')->unique()->all())->get()->keyBy('id');
        $orders = ExamOrder::query()->whereKey($results->pluck('exam_order_id')->unique()->all())->get()->keyBy('id');
        $names = self::names($results, $orders->pluck('author_user_id')->all());

        return array_values($results->groupBy('exam_order_item_id')
            ->map(function (Collection $versions, int $itemId) use ($items, $orders, $names, $timezone, $user, $attends) {
                $order = $orders[$items[$itemId]->exam_order_id];

                return [
                    'id' => $itemId,
                    'name' => $items[$itemId]->exam_name,
                    'type' => $items[$itemId]->exam_type,
                    'orderedAt' => $order->created_at->setTimezone($timezone)->format('d/m/Y H:i'),
                    'orderedBy' => $names->get($order->author_user_id),
                    'versions' => self::versions($versions, $names, $timezone, $attends || ($user !== null && $order->author_user_id === $user->id)),
                    'releasedAt' => $versions->max('released_at'),
                ];
            })
            ->sortByDesc('releasedAt')->map(fn (array $row) => array_diff_key($row, ['releasedAt' => true]))->all());
    }

    /**
     * Names of who released, acknowledged and ordered.
     *
     * @param  Collection<int, ExamResult>  $results  with criticalAlert loaded
     * @param  array<int, mixed>  $more  more user ids
     * @return Collection<int, string>
     */
    public static function names(Collection $results, array $more = []): Collection
    {
        return User::query()->whereKey([...$results->pluck('released_by_user_id')->all(), ...$results->pluck('criticalAlert.acknowledged_by_user_id')->filter()->all(), ...$more])->pluck('name', 'id');
    }

    /**
     * The versions of one item's result, newest first.
     *
     * @param  Collection<int, ExamResult>  $versions  with values and criticalAlert loaded
     * @param  Collection<int, string>  $names  user id => name
     * @return list<array<string, mixed>>
     */
    public static function versions(Collection $versions, Collection $names, string $timezone, bool $canAcknowledge = false): array
    {
        $when = fn (?CarbonInterface $at) => $at?->setTimezone($timezone)->format('d/m/Y H:i');

        return array_values($versions->sortByDesc('version')->map(fn (ExamResult $result) => [
            'id' => $result->id,
            'version' => $result->version,
            'releasedAt' => $when($result->released_at),
            'releasedBy' => $names->get($result->released_by_user_id),
            'reason' => $result->rectification_reason,
            'report' => $result->report,
            'critical' => $result->critical,
            'acknowledgedAt' => $when($result->criticalAlert?->acknowledged_at),
            'acknowledgedBy' => $names->get($result->criticalAlert->acknowledged_by_user_id ?? 0),
            'canAcknowledge' => $canAcknowledge && $result->criticalAlert !== null && $result->criticalAlert->acknowledged_at === null,
            'attachment' => $result->attachment_path === null ? null : ['name' => $result->attachment_name, 'sizeKb' => (int) ceil((int) $result->attachment_size / 1024)],
            'values' => $result->values->map(fn (ExamResultValue $value) => [
                'analyteId' => $value->exam_analyte_id,
                'name' => $value->analyte_name,
                'value' => $value->value_text ?? self::number($value->value_numeric),
                'unit' => $value->unit,
                'reference' => $value->reference_text ?? self::range($value->reference_min, $value->reference_max),
                'flag' => $value->flag,
            ])->values()->all(),
        ])->all());
    }

    /**
     * The professional who signs, if the user may release this item's result: the team of its type in the order's
     * unit, and for imaging a professional with CRM; otherwise 403.
     */
    public static function releaser(User $user, ExamOrder $order, ExamOrderItem $item): ?Professional
    {
        abort_unless(in_array($item->exam_type, ExamQueueController::typesIn(ClinicalAccess::examScopes(UnitPolicy::currentMembership($user)), $order->unit_id), true), 403);
        $professional = ClinicalAccess::professional($user);
        abort_if($item->exam_type === 'imaging' && $professional?->council !== 'CRM', 403);

        return $professional;
    }

    /**
     * The analytes typed for this laboratory item, with the reference of the catalog as released: a number is
     * compared with the range (low, high); anything else is kept as text ("Negativo"). Blank ones are skipped.
     *
     * @param  array<array-key, mixed>  $typed  analyte id => value
     * @return list<array<string, mixed>>
     */
    private static function values(ExamOrderItem $item, array $typed, string $bag): array
    {
        $analytes = ExamAnalyte::query()->where('exam_id', $item->exam_id)->where('active', true)->orderBy('position')->get()->keyBy('id');
        $values = [];
        foreach ($typed as $id => $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $analyte = $analytes->get((int) $id) ?? throw ValidationException::withMessages(['report' => 'Um dos valores não pertence a este exame.'])->errorBag($bag);
            // ponytail: compared as floats; exact for the 14 significant digits the column holds.
            $numeric = preg_match('/^[-+]?\d{1,10}([.,]\d{1,4})?$/', $raw) === 1 ? str_replace(',', '.', $raw) : null;
            $values[] = [
                'exam_analyte_id' => $analyte->id, 'analyte_name' => $analyte->name, 'unit' => $analyte->unit,
                'reference_min' => $analyte->reference_min, 'reference_max' => $analyte->reference_max, 'reference_text' => $analyte->reference_text,
                'value_numeric' => $numeric, 'value_text' => $numeric === null ? $raw : null,
                'flag' => match (true) {
                    $numeric === null => null,
                    $analyte->reference_min !== null && (float) $numeric < (float) $analyte->reference_min => 'low',
                    $analyte->reference_max !== null && (float) $numeric > (float) $analyte->reference_max => 'high',
                    default => null,
                },
            ];
        }

        return $values;
    }

    private static function number(?string $value): ?string
    {
        return $value === null ? null : str_replace('.', ',', str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value);
    }

    private static function range(?string $min, ?string $max): ?string
    {
        return match (true) {
            $min !== null && $max !== null => self::number($min).' a '.self::number($max),
            $min !== null => '≥ '.self::number($min),
            $max !== null => '≤ '.self::number($max),
            default => null,
        };
    }
}
