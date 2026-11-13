<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\EmergencyEpisode;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\VisitPass;
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
        $own = $account->effectivePatientId();
        // Each record the account reads, with the ones merged into it, labelled by whose it is.
        $whose = [];
        foreach (PortalRecord::readable($account) as $kept) {
            $name = $kept === $own ? null : Patient::query()->whereKey($kept)->get(['name', 'social_name'])->map(fn (Patient $p) => $p->social_name ?? $p->name)->first();
            foreach (PortalRecord::recordIds($kept) as $id) {
                $whose[$id] = $name;
            }
        }
        $ids = array_keys($whose);

        $journeys = [
            ...Appointment::query()->whereIn('patient_id', $ids)->whereIn('status', ['arrived', 'in_progress', 'completed'])
                ->where('arrived_at', '>=', now()->subDay())->orderBy('arrived_at')->get()
                ->map(fn (Appointment $appointment) => [$appointment->patient_id, PortalJourney::appointment($appointment)]),
            ...EmergencyEpisode::query()->whereIn('patient_id', $ids)
                ->where(fn ($query) => $query->whereIn('status', EmergencyEpisode::OPEN)->orWhere('ended_at', '>=', now()->subHours(VisitPass::HOURS_AFTER_END)))
                ->orderBy('arrived_at')->get()
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
}
