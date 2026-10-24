<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Exam;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\Patient;
use App\Models\Unit;
use App\Models\User;
use App\Models\Visit;
use App\Policies\UnitPolicy;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The exam queues and the execution (step 5.3, ADR-0008). The Laboratory and the Imaging roles see the open
 * orders of their type in their unit, urgent first and then by waiting time, without a read logged. Opening an
 * order reads the patient's record (reason "exam", one row per opening) and shows only the items of the user's
 * types; each item is collected (laboratory) or performed (imaging) once, with when and who.
 */
class ExamQueueController extends Controller
{
    public function index(Request $request): Response
    {
        $scopes = ClinicalAccess::examScopes(UnitPolicy::currentMembership($request->user()));
        $units = Unit::query()->whereKey(array_merge(...array_values($scopes ?: [[]])))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', (int) $request->query('unit')) ?? $units->first();
        $types = self::typesIn($scopes, $unit->id);

        $orders = ExamOrder::query()->where('unit_id', $unit->id)->where('status', 'requested')
            ->whereHas('items', fn ($items) => $items->whereIn('exam_type', $types))
            ->with(['items' => fn ($items) => $items->whereIn('exam_type', $types)])
            ->orderByRaw("priority = 'urgent' DESC")->orderBy('created_at')->get();
        $places = self::places($orders);
        $patients = Patient::query()->whereKey($orders->pluck('patient_id')->unique()->all())->get(['id', 'name', 'social_name'])->keyBy('id');
        // The network admin's targets, on the exam catalog page.
        $tenant = app(CurrentTenant::class)->require();
        $targets = ['urgent' => $tenant->exam_target_urgent_minutes, 'routine' => $tenant->exam_target_routine_minutes];

        return Inertia::render('exam-queue/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'types' => array_intersect_key(Exam::TYPES, array_flip($types)),
            'priorities' => ExamOrder::PRIORITIES,
            'orders' => $orders->map(function (ExamOrder $order) use ($patients, $places, $targets) {
                $waiting = (int) $order->created_at->diffInMinutes(now());
                $pending = $order->items->whereNull('collected_at')->isNotEmpty();

                return [
                    'id' => $order->id,
                    'patient' => ($patient = $patients->get($order->patient_id)) === null ? null : ($patient->social_name ?? $patient->name),
                    'place' => $places[$order->id],
                    'priority' => $order->priority,
                    'waitingMinutes' => $waiting,
                    'late' => $pending && $waiting > $targets[$order->priority],
                    'items' => $order->items->map(fn (ExamOrderItem $item) => ['id' => $item->id, 'name' => $item->exam_name, 'type' => $item->exam_type, 'collected' => $item->collected_at !== null])->values(),
                ];
            })->values(),
        ]);
    }

    public function show(Request $request, ExamOrder $order): Response
    {
        $types = self::access($request->user(), $order);
        $patient = Patient::query()->findOrFail($order->patient_id);
        ClinicalAccess::recordRead($request->user(), $patient, $order);
        Inertia::encryptHistory();
        $timezone = Unit::query()->whereKey($order->unit_id)->value('timezone') ?? 'UTC';
        $ids = ClinicalAccess::recordIds($patient);
        $items = $order->items()->whereIn('exam_type', $types)->get();
        $names = User::query()->whereKey(array_filter([$order->author_user_id, ...$items->pluck('collected_by_user_id')->all()]))->pluck('name', 'id');

        return Inertia::render('exam-queue/show', [
            'order' => [
                'id' => $order->id,
                'priority' => $order->priority,
                'indication' => $order->indication,
                'author' => $names->get($order->author_user_id),
                'createdAt' => $order->created_at->setTimezone($timezone)->format('d/m/Y H:i'),
                'place' => self::places([$order])[$order->id],
                'unitId' => $order->unit_id,
            ],
            'items' => $items->map(fn (ExamOrderItem $item) => [
                'id' => $item->id,
                'name' => $item->exam_name,
                'type' => $item->exam_type,
                'collectedAt' => $item->collected_at?->setTimezone($timezone)->format('d/m/Y H:i'),
                'collectedBy' => $names->get($item->collected_by_user_id),
            ])->values(),
            'types' => Exam::TYPES,
            'priorities' => ExamOrder::PRIORITIES,
            'patient' => VisitController::patientHeader($patient),
            'allergies' => PatientAllergyController::listing($ids, $timezone),
            'record' => VisitController::signedRecord($ids, $timezone),
            // ADR-0008: the prescriptions of the order's encounter, read-only for the exam team.
            'prescriptions' => self::readOnlyPrescriptions($request->user(), $order, $timezone),
            'documents' => ClinicalDocumentController::listing($ids, $timezone, $request->user()->id),
        ]);
    }

    public function collect(Request $request, ExamOrderItem $item): RedirectResponse
    {
        DB::transaction(function () use ($request, $item) {
            // The order first, as its cancellation does: one of the two wins.
            $order = ExamOrder::query()->lockForUpdate()->findOrFail($item->exam_order_id);
            $locked = ExamOrderItem::query()->lockForUpdate()->findOrFail($item->id);
            abort_unless(in_array($locked->exam_type, self::access($request->user(), $order, open: false), true), 403);
            if ($order->status !== 'requested') {
                throw ValidationException::withMessages(['collect' => 'Este pedido foi cancelado.']);
            }
            if ($locked->collected_at !== null) {
                throw ValidationException::withMessages(['collect' => $locked->exam_type === 'imaging' ? 'Este exame já foi realizado.' : 'Este exame já foi coletado.']);
            }
            $locked->update(['collected_at' => now(), 'collected_by_user_id' => $request->user()->id]);
            AuditEvent::record('exam_order_item.collected', $order->tenant_id, $locked, ['unit_id' => $order->unit_id, 'exam_order_id' => $order->id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $item->exam_type === 'imaging' ? 'Realização registrada.' : 'Coleta registrada.']);

        return back();
    }

    /**
     * The prescriptions of the order's encounter with every action off, even for a user who also prescribes.
     *
     * @return array<string, mixed>
     */
    private static function readOnlyPrescriptions(User $user, ExamOrder $order, string $timezone): array
    {
        $panel = PrescriptionController::panel($user, $order->admission_id === null ? null : Admission::query()->findOrFail($order->admission_id),
            $order->visit_id === null ? null : Visit::query()->findOrFail($order->visit_id), $timezone);
        $off = fn (?array $prescription) => $prescription === null ? null : [...$prescription, 'canCertify' => false];

        return [...$panel, 'active' => $off($panel['active']), 'previous' => array_map($off, $panel['previous']), 'draftId' => null, 'canPrescribe' => false, 'canSuspend' => false, 'administration' => null];
    }

    /**
     * The exam types the user executes on this order in its unit, while it is open unless told otherwise; otherwise
     * 403 (ADR-0008).
     *
     * @return list<string>
     */
    private static function access(User $user, ExamOrder $order, bool $open = true): array
    {
        abort_unless(! $open || $order->status === 'requested', 403);
        $types = array_values(array_intersect(self::typesIn(ClinicalAccess::examScopes(UnitPolicy::currentMembership($user)), $order->unit_id), $order->items()->pluck('exam_type')->unique()->all()));
        abort_if($types === [], 403);

        return $types;
    }

    /**
     * @param  array<string, list<int>>  $scopes
     * @return list<string>
     */
    private static function typesIn(array $scopes, int $unitId): array
    {
        return array_keys(array_filter($scopes, fn (array $units) => in_array($unitId, $units, true)));
    }

    /**
     * Where each order's patient is: the bed of the admission, or the kind of visit.
     *
     * @param  iterable<ExamOrder>  $orders
     * @return array<int, string>
     */
    private static function places(iterable $orders): array
    {
        $orders = collect($orders);
        $admissions = Admission::query()->whereKey($orders->pluck('admission_id')->filter()->all())->with(['bed:id,code,room,sector_id', 'bed.sector:id,name'])->get()->keyBy('id');
        $visits = Visit::query()->whereKey($orders->pluck('visit_id')->filter()->all())->get(['id', 'emergency_episode_id'])->keyBy('id');

        return $orders->mapWithKeys(fn (ExamOrder $order) => [$order->id => $order->admission_id !== null
            ? (WardController::bedLabel($admissions[$order->admission_id]) ?? 'Internação')
            : ($visits->get($order->visit_id)?->emergency_episode_id !== null ? 'Urgência' : 'Ambulatório')])->all();
    }
}
