<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Exam;
use App\Models\ExamAnalyte;
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
    private const RELEASED_DAYS = 30;

    private const RELEASED_LIMIT = 50;

    public function index(Request $request): Response
    {
        $scopes = ClinicalAccess::examScopes(UnitPolicy::currentMembership($request->user()));
        $units = Unit::query()->whereKey(array_merge(...array_values($scopes ?: [[]])))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', (int) $request->query('unit')) ?? $units->first();
        $types = self::typesIn($scopes, $unit->id);

        // Step 5.4: an order leaves the queue when every item of the user's types has its result released.
        $open = fn ($items) => $items->whereIn('exam_type', $types)->whereDoesntHave('results');
        $orders = ExamOrder::query()->where('unit_id', $unit->id)->where('status', 'requested')->whereHas('items', $open)
            ->with(['items' => fn ($items) => $items->whereIn('exam_type', $types)->withCount('results')])
            ->orderByRaw("priority = 'urgent' DESC")->orderBy('created_at')->get();
        // The ones released in the last days, to rectify.
        $released = ExamOrder::query()->where('unit_id', $unit->id)->where('status', 'requested')->whereDoesntHave('items', $open)
            ->whereHas('items', fn ($items) => $items->whereIn('exam_type', $types)->whereHas('results', fn ($results) => $results->where('released_at', '>=', now()->subDays(self::RELEASED_DAYS))))
            ->with(['items' => fn ($items) => $items->whereIn('exam_type', $types)])->orderByDesc('id')->limit(self::RELEASED_LIMIT)->get();
        $places = self::places($orders->concat($released));
        $patients = Patient::query()->whereKey($orders->concat($released)->pluck('patient_id')->unique()->all())->get(['id', 'name', 'social_name'])->keyBy('id');
        $patientName = fn (ExamOrder $order) => ($patient = $patients->get($order->patient_id)) === null ? null : ($patient->social_name ?? $patient->name);
        // The network admin's targets, on the exam catalog page.
        $tenant = app(CurrentTenant::class)->require();
        $targets = ['urgent' => $tenant->exam_target_urgent_minutes, 'routine' => $tenant->exam_target_routine_minutes];

        return Inertia::render('exam-queue/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'types' => array_intersect_key(Exam::TYPES, array_flip($types)),
            'priorities' => ExamOrder::PRIORITIES,
            'orders' => $orders->map(function (ExamOrder $order) use ($patientName, $places, $targets) {
                $waiting = (int) $order->created_at->diffInMinutes(now());
                $pending = $order->items->whereNull('collected_at')->isNotEmpty();

                return [
                    'id' => $order->id,
                    'patient' => $patientName($order),
                    'place' => $places[$order->id],
                    'priority' => $order->priority,
                    'waitingMinutes' => $waiting,
                    'late' => $pending && $waiting > $targets[$order->priority],
                    'items' => $order->items->map(fn (ExamOrderItem $item) => ['id' => $item->id, 'name' => $item->exam_name, 'type' => $item->exam_type, 'collected' => $item->collected_at !== null, 'released' => $item->results_count > 0])->values(),
                ];
            })->values(),
            'released' => $released->map(fn (ExamOrder $order) => [
                'id' => $order->id,
                'patient' => $patientName($order),
                'place' => $places[$order->id],
                'items' => $order->items->pluck('exam_name')->values(),
            ])->values(),
            'releasedDays' => self::RELEASED_DAYS,
        ]);
    }

    public function show(Request $request, ExamOrder $order): Response
    {
        $types = self::access($request->user(), $order);
        $items = $order->items()->whereIn('exam_type', $types)->with('results.values')->get();
        // ADR-0008: the record while an item of the user's types waits for its result; after that, only the order
        // and the results, to rectify them (step 5.4).
        $open = $order->status === 'requested' && $items->contains(fn (ExamOrderItem $item) => $item->results->isEmpty());
        abort_unless($open || $items->contains(fn (ExamOrderItem $item) => $item->results->isNotEmpty()), 403);
        $patient = Patient::query()->findOrFail($order->patient_id);
        ClinicalAccess::recordRead($request->user(), $patient, $order);
        Inertia::encryptHistory();
        $timezone = Unit::query()->whereKey($order->unit_id)->value('timezone') ?? 'UTC';
        $ids = ClinicalAccess::recordIds($patient);
        $results = $items->flatMap->results;
        $names = User::query()->whereKey(array_filter([$order->author_user_id, ...$items->pluck('collected_by_user_id')->all(), ...$results->pluck('released_by_user_id')->all()]))->pluck('name', 'id');
        $crm = ClinicalAccess::professional($request->user())?->council === 'CRM';
        $analytes = ExamAnalyte::query()->whereIn('exam_id', $items->where('exam_type', 'laboratory')->pluck('exam_id')->all())->where('active', true)->orderBy('position')->get()->groupBy('exam_id');

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
                'results' => ExamResultController::versions($item->results, $names, $timezone),
                'nextVersion' => $item->results->count() + 1,
                // Imaging reports only by a professional with CRM (decision of 24/10/2026).
                'canSign' => $order->status === 'requested' && $item->collected_at !== null && ($item->exam_type === 'laboratory' || $crm),
                'analytes' => ($analytes->get($item->exam_id) ?? collect())->map(fn (ExamAnalyte $analyte) => [
                    'id' => $analyte->id, 'name' => $analyte->name, 'unit' => $analyte->unit,
                ])->values(),
            ])->values(),
            'types' => Exam::TYPES,
            'priorities' => ExamOrder::PRIORITIES,
            'patient' => VisitController::patientHeader($patient),
            'open' => $open,
            'allergies' => $open ? PatientAllergyController::listing($ids, $timezone) : [],
            'record' => $open ? VisitController::signedRecord($ids, $timezone) : [],
            // ADR-0008: the prescriptions of the order's encounter, read-only for the exam team.
            'prescriptions' => $open ? self::readOnlyPrescriptions($request->user(), $order, $timezone) : null,
            'documents' => $open ? ClinicalDocumentController::listing($ids, $timezone, $request->user()->id) : [],
            'examResults' => $open ? ExamResultController::listing($ids, $timezone) : [],
        ]);
    }

    public function collect(Request $request, ExamOrderItem $item): RedirectResponse
    {
        DB::transaction(function () use ($request, $item) {
            // The order first, as its cancellation does: one of the two wins.
            $order = ExamOrder::query()->lockForUpdate()->findOrFail($item->exam_order_id);
            $locked = ExamOrderItem::query()->lockForUpdate()->findOrFail($item->id);
            abort_unless(in_array($locked->exam_type, self::access($request->user(), $order), true), 403);
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
     * The exam types the user executes on this order in its unit; otherwise 403 (ADR-0008).
     *
     * @return list<string>
     */
    private static function access(User $user, ExamOrder $order): array
    {
        $types = array_values(array_intersect(self::typesIn(ClinicalAccess::examScopes(UnitPolicy::currentMembership($user)), $order->unit_id), $order->items()->pluck('exam_type')->unique()->all()));
        abort_if($types === [], 403);

        return $types;
    }

    /**
     * @param  array<string, list<int>>  $scopes
     * @return list<string>
     */
    public static function typesIn(array $scopes, int $unitId): array
    {
        return array_keys(array_filter($scopes, fn (array $units) => in_array($unitId, $units, true)));
    }

    /**
     * Where each order's patient is: the bed of the admission, or the kind of visit.
     *
     * @param  iterable<ExamOrder>  $orders
     * @return array<int, string>
     */
    public static function places(iterable $orders): array
    {
        $orders = collect($orders);
        $admissions = Admission::query()->whereKey($orders->pluck('admission_id')->filter()->all())->with(['bed:id,code,room,sector_id', 'bed.sector:id,name'])->get()->keyBy('id');
        $visits = Visit::query()->whereKey($orders->pluck('visit_id')->filter()->all())->get(['id', 'emergency_episode_id'])->keyBy('id');

        return $orders->mapWithKeys(fn (ExamOrder $order) => [$order->id => $order->admission_id !== null
            ? (WardController::bedLabel($admissions[$order->admission_id]) ?? 'Internação')
            : ($visits->get($order->visit_id)?->emergency_episode_id !== null ? 'Urgência' : 'Ambulatório')])->all();
    }
}
