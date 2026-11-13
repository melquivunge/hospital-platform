<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\EmergencyEpisode;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\VisitPass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7b.2: the signed-in portal's live journey — the same screen as the QR, for every arrival of the patient and of
 * the minors linked now, until 12 hours after it ends.
 */
class PortalVisitsController extends Controller
{
    public function __invoke(): Response
    {
        /** @var PatientAccount|null $account */
        $account = Auth::guard('patient')->user();
        abort_if($account === null, 403);

        return Inertia::render('portal/visits', [
            ...PortalHomeController::shell($account),
            'visits' => self::visits($account),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function visits(PatientAccount $account): array
    {
        $whose = self::whose($account);

        $journeys = [
            ...self::appointments(array_keys($whose))->get()
                ->map(fn (Appointment $appointment) => [$appointment->patient_id, PortalJourney::appointment($appointment)]),
            ...self::episodes(array_keys($whose))->get()
                ->map(fn (EmergencyEpisode $episode) => [$episode->patient_id, PortalJourney::episode($episode)]),
        ];

        $visits = [];
        foreach ($journeys as [$patientId, $journey]) {
            if (! VisitPass::expiredAt($journey['endedAt'])) {
                $visits[] = ['who' => $whose[$patientId]] + PortalJourney::present($journey);
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
            + self::episodes($ids)->whereIn('status', EmergencyEpisode::OPEN)->count();
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
     * Each record the account reads, with the ones merged into it, labelled by whose it is (null: the patient's own).
     *
     * @return array<int, string|null>
     */
    private static function whose(PatientAccount $account): array
    {
        $own = $account->effectivePatientId();
        $whose = [];
        foreach (PortalRecord::readable($account) as $kept) {
            $name = $kept === $own ? null : Patient::query()->whereKey($kept)->get(['name', 'social_name'])->map(fn (Patient $p) => $p->social_name ?? $p->name)->first();
            foreach (PortalRecord::recordIds($kept) as $id) {
                $whose[$id] = $name;
            }
        }

        return $whose;
    }
}
