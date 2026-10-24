<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Exam;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\JobFunction;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use App\Professionals\QualificationStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exam orders (step 5.2, delivery 5): the professional who attends the open visit or the active admission, with a
 * function that attends patients and a qualification in the unit (decision 2), orders exams of the catalog with a
 * priority and the clinical indication, signed with the password. Its author cancels it with a reason. Clinical:
 * the page records a read; the audit keeps ids and counts only.
 */
class ExamOrderController extends Controller
{
    public function forAdmission(Request $request, Admission $admission): Response
    {
        return $this->page($request, $admission, null);
    }

    public function forVisit(Request $request, Visit $visit): Response
    {
        return $this->page($request, null, $visit);
    }

    public function storeForAdmission(Request $request, Admission $admission): RedirectResponse
    {
        return $this->store($request, $admission, null);
    }

    public function storeForVisit(Request $request, Visit $visit): RedirectResponse
    {
        return $this->store($request, null, $visit);
    }

    public function cancel(Request $request, ExamOrder $order): RedirectResponse
    {
        // Only its author; also after the encounter closed, so an order never stays open by mistake (ADR-0008).
        abort_unless($order->author_user_id === $request->user()->id, 403);
        $bag = "cancel{$order->id}";
        $data = $request->validateWithBag($bag, ['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        DB::transaction(function () use ($request, $order, $data, $bag) {
            $locked = ExamOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'requested') {
                throw ValidationException::withMessages(['reason' => 'Este pedido não pode mais ser cancelado.'])->errorBag($bag);
            }
            // Step 5.3: only before anything is collected; the collection locks the order first too.
            if ($locked->items()->whereNotNull('collected_at')->exists()) {
                throw ValidationException::withMessages(['reason' => 'Este pedido já tem exame coletado ou realizado e não pode mais ser cancelado.'])->errorBag($bag);
            }
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $request->user()->id, 'cancellation_reason' => trim($data['reason'])]);
            AuditEvent::record('exam_order.cancelled', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pedido de exames cancelado.']);

        return back();
    }

    /**
     * A professional who attends patients and holds an approved qualification valid today in the unit.
     */
    public static function orderer(User $user, int $unitId): ?Professional
    {
        $professional = ClinicalAccess::professional($user);
        if ($professional === null) {
            return null;
        }
        $today = now(Unit::query()->whereKey($unitId)->value('timezone') ?? 'UTC')->toDateString();

        return Qualification::query()->where('professional_id', $professional->id)->where('unit_id', $unitId)->where('status', QualificationStatus::Approved)
            ->whereIn('job_function_id', JobFunction::query()->where('attends_patients', true)->select('id'))
            ->whereDate('valid_from', '<=', $today)->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today))
            ->exists() ? $professional : null;
    }

    private function page(Request $request, ?Admission $admission, ?Visit $visit): Response
    {
        [$patient] = $this->writer($request, $admission, $visit);
        ClinicalAccess::recordRead($request->user(), $patient, $admission ?? $visit);
        $orders = ExamOrder::query()->with('items')->where($admission !== null ? 'admission_id' : 'visit_id', $admission->id ?? $visit?->id)->orderByDesc('id')->get();
        $names = User::query()->whereKey($orders->pluck('author_user_id')->unique()->all())->pluck('name', 'id');
        $timezone = Unit::query()->whereKey($admission->unit_id ?? $visit?->unit_id)->value('timezone') ?? 'UTC';

        return Inertia::render('exam-orders/index', [
            'patient' => ['name' => $patient->name],
            'encounter' => $admission !== null ? ['type' => 'admission', 'id' => $admission->id] : ['type' => 'visit', 'id' => $visit?->id],
            'orders' => $orders->map(fn (ExamOrder $order) => [
                'id' => $order->id,
                'priority' => $order->priority,
                'indication' => $order->indication,
                'status' => $order->status,
                'author' => $names->get($order->author_user_id),
                'createdAt' => $order->created_at->timezone($timezone)->format('d/m/Y H:i'),
                'cancellationReason' => $order->cancellation_reason,
                'items' => $order->items->map(fn (ExamOrderItem $item) => ['id' => $item->id, 'name' => $item->exam_name, 'type' => $item->exam_type])->values(),
                'canCancel' => $order->status === 'requested' && $order->author_user_id === $request->user()->id,
            ])->values(),
            'catalog' => Exam::query()->where('active', true)->orderBy('type')->orderBy('name_key')->get(['id', 'name', 'type', 'preparation'])->values(),
            'types' => Exam::TYPES,
            'priorities' => ExamOrder::PRIORITIES,
        ]);
    }

    private function store(Request $request, ?Admission $admission, ?Visit $visit): RedirectResponse
    {
        [$patient, $professional] = $this->writer($request, $admission, $visit);
        $data = $request->validateWithBag('order', [
            'exam_ids' => ['required', 'array', 'min:1', 'max:50'],
            'exam_ids.*' => ['integer', 'distinct', Rule::exists('exams', 'id')->where('active', true)->where('tenant_id', $patient->tenant_id)],
            'priority' => ['required', Rule::in(array_keys(ExamOrder::PRIORITIES))],
            'indication' => ['required', 'string', 'max:1000'],
            'password' => ['required', 'string'],
        ], ['exam_ids.required' => 'Escolha ao menos um exame.'], ['exam_ids' => 'exames', 'priority' => 'prioridade', 'indication' => 'indicação clínica', 'password' => 'senha']);
        /** @var Admission|Visit $encounter */
        $encounter = $admission ?? $visit;
        try {
            VisitController::confirmPassword($request, $data['password'], 'exam_order.sign_failed', $encounter, 'Senha incorreta. O pedido não foi feito.');
        } catch (ValidationException $e) {
            throw $e->errorBag('order');
        }

        DB::transaction(function () use ($request, $admission, $visit, $patient, $professional, $data) {
            // A close or a discharge racing the order waits on this lock, then the access is checked again (ADR-0008).
            ($admission !== null ? Admission::query() : Visit::query())->lockForUpdate()->findOrFail($admission->id ?? $visit?->id);
            $this->writer($request, $admission?->fresh(), $visit?->fresh());
            $order = ExamOrder::query()->create([
                'patient_id' => $patient->merged_into_id ?? $patient->id, 'unit_id' => $admission->unit_id ?? $visit?->unit_id,
                'admission_id' => $admission?->id, 'visit_id' => $visit?->id, 'author_user_id' => $request->user()->id, 'professional_id' => $professional->id,
                'priority' => $data['priority'], 'indication' => trim($data['indication']), 'signature_method' => 'password', 'created_at' => now(),
            ]);
            foreach (Exam::query()->whereKey($data['exam_ids'])->orderBy('type')->orderBy('name_key')->get() as $exam) {
                $order->items()->create(['tenant_id' => $order->tenant_id, 'exam_id' => $exam->id, 'exam_name' => $exam->name, 'exam_type' => $exam->type]);
            }
            AuditEvent::record('exam_order.created', $order->tenant_id, $order, ['unit_id' => $order->unit_id, 'items' => count($data['exam_ids']), 'priority' => $order->priority]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pedido de exames feito.']);

        return back();
    }

    /**
     * The patient and the professional, if the user attends this open encounter and may order; otherwise 403.
     *
     * @return array{Patient, Professional}
     */
    private function writer(Request $request, ?Admission $admission, ?Visit $visit): array
    {
        $patient = Patient::query()->findOrFail($admission->patient_id ?? $visit?->patient_id);
        $allowed = $admission !== null
            ? ClinicalAccess::activeAdmission($request->user(), $patient)?->id === $admission->id
            : $visit !== null && $visit->closed_at === null && $visit->professional_id === ClinicalAccess::professional($request->user())?->id;
        abort_unless($allowed, 403);

        return [$patient, self::orderer($request->user(), (int) ($admission->unit_id ?? $visit?->unit_id)) ?? abort(403)];
    }
}
