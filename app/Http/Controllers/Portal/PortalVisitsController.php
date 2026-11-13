<?php

namespace App\Http\Controllers\Portal;

use App\Clinical\ClinicalAccess;
use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\EmergencyEpisode;
use App\Models\ExamOrder;
use App\Models\ExamOrderItem;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\VisitPass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7b.2: the signed-in portal's live journey — the same screen as the QR, for the four queues (appointment,
 * emergency, exams, admission) of the patient and of the minors linked now, until 12 hours after each ends.
 */
class PortalVisitsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var PatientAccount|null $account */
        $account = Auth::guard('patient')->user();
        abort_if($account === null, 403);

        return Inertia::render('portal/visits', [
            ...PortalHomeController::shell($account),
            'visits' => self::visits($account, record: ! $request->hasHeader('X-Inertia-Partial-Data')),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function visits(PatientAccount $account, bool $record = false): array
    {
        $whose = self::whose($account);

        $journeys = [
            ...self::appointments(array_keys($whose))->get()
                ->map(fn (Appointment $appointment) => [$appointment->patient_id, PortalJourney::appointment($appointment)]),
            ...self::episodes(array_keys($whose))->get()
                ->map(fn (EmergencyEpisode $episode) => [$episode->patient_id, PortalJourney::episode($episode)]),
            ...self::admissions(array_keys($whose))->get()
                ->map(fn (Admission $admission) => [$admission->patient_id, PortalJourney::admission($admission)]),
            ...self::exams(array_keys($whose))->get()
                ->map(fn (ExamOrderItem $item) => [(int) $item->getAttribute('patient_id'), PortalJourney::exam($item, (int) $item->getAttribute('unit_id'), named: $whose[(int) $item->getAttribute('patient_id')][1] === null)]),
        ];

        $visits = $read = [];
        foreach ($journeys as [$patientId, $journey]) {
            if (VisitPass::expiredAt($journey['endedAt'])) {
                continue;
            }
            [$kept, $who] = $whose[$patientId];
            if (in_array($journey['kind'], ['exam', 'admission'], true)) {
                $read[$kept] = true;
            }
            $visits[] = ['who' => $who] + PortalJourney::present($journey);
        }

        // Exams and the bed are the record: the page view goes to the access trail, the 20 s refresh does not.
        if ($record) {
            foreach (array_keys($read) as $kept) {
                ClinicalAccess::recordPortalRead($account, $kept);
            }
        }

        return $visits;
    }

    /**
     * For the start page: the arrivals going on now, counted without working out the queues.
     */
    public static function ongoing(PatientAccount $account): int
    {
        $ids = array_keys(self::whose($account));

        return self::appointments($ids)->whereIn('status', ['arrived', 'in_progress'])->count()
            + self::episodes($ids)->whereIn('status', EmergencyEpisode::OPEN)->count()
            + self::admissions($ids)->whereIn('status', Admission::ACTIVE)->count()
            + self::exams($ids)->whereDoesntHave('results')->count();
    }

    /**
     * @param  list<int>  $ids
     * @return Builder<Appointment>
     */
    private static function appointments(array $ids): Builder
    {
        return Appointment::query()->whereIn('patient_id', $ids)->whereIn('status', ['arrived', 'in_progress', 'completed'])
            ->where('arrived_at', '>=', now()->subDay())->orderBy('arrived_at');
    }

    /**
     * @param  list<int>  $ids
     * @return Builder<EmergencyEpisode>
     */
    private static function episodes(array $ids): Builder
    {
        return EmergencyEpisode::query()->whereIn('patient_id', $ids)
            ->where(fn (Builder $query) => $query->whereIn('status', EmergencyEpisode::OPEN)->orWhere('ended_at', '>=', now()->subHours(VisitPass::HOURS_AFTER_END)))
            ->orderBy('arrived_at');
    }

    /**
     * @param  list<int>  $ids
     * @return Builder<Admission>
     */
    private static function admissions(array $ids): Builder
    {
        return Admission::query()->whereIn('patient_id', $ids)
            ->where(fn (Builder $query) => $query->whereIn('status', Admission::ACTIVE)->orWhere('discharged_at', '>=', now()->subHours(VisitPass::HOURS_AFTER_END)))
            ->orderBy('requested_at');
    }

    /**
     * The exams of the open orders (an exam never collected leaves after OVERDUE_DAYS, like the manager's overdue list).
     *
     * @param  list<int>  $ids
     * @return Builder<ExamOrderItem>
     */
    private static function exams(array $ids): Builder
    {
        return ExamOrderItem::query()->join('exam_orders', 'exam_orders.id', '=', 'exam_order_items.exam_order_id')
            ->whereIn('exam_orders.patient_id', $ids)->where('exam_orders.status', 'requested')
            // An exam never collected leaves after OVERDUE_DAYS; once collected, it stays until 12 h after the result.
            ->where(fn (Builder $query) => $query->where('exam_orders.created_at', '>=', now()->subDays(ExamOrder::OVERDUE_DAYS))->orWhereNotNull('exam_order_items.collected_at'))
            ->withMin('results', 'released_at')
            ->orderBy('exam_order_items.id')
            ->select(['exam_order_items.*', 'exam_orders.patient_id', 'exam_orders.unit_id']);
    }

    /**
     * Each record the account reads, with the ones merged into it, labelled by whose it is (null: the patient's own).
     *
     * @return array<int, array{int, string|null}>
     */
    private static function whose(PatientAccount $account): array
    {
        $own = $account->effectivePatientId();
        $whose = [];
        foreach (PortalRecord::readable($account) as $kept) {
            $name = $kept === $own ? null : Patient::query()->whereKey($kept)->get(['name', 'social_name'])->map(fn (Patient $p) => $p->social_name ?? $p->name)->first();
            foreach (PortalRecord::recordIds($kept) as $id) {
                $whose[$id] = [$kept, $name];
            }
        }

        return $whose;
    }
}
