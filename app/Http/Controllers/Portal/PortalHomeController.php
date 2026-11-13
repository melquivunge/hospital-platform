<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7a.1a: the portal's start page. Shows only how to address the patient; the content comes in 7a.3.
 */
class PortalHomeController extends Controller
{
    public function __invoke(): Response
    {
        /** @var PatientAccount|null $account */
        $account = Auth::guard('patient')->user();
        abort_if($account === null, 403);

        return Inertia::render('portal/home', [
            ...self::shell($account),
            // Step 7a.3: the patient's own results and appointments (the kept record after a merge).
            ...PortalRecord::content($account, $account->effectivePatientId()),
            // Step 7b.2: a way to the live journey while an arrival is on.
            'visits' => PortalVisitsController::ongoing($account),
            // Step 7a.2: the "Filhos menores" tab, only the minors linked now.
            'children' => Patient::query()->whereIn('id', PatientAccountLink::minorsOf($account))->orderBy('name')->get()
                ->map(fn (Patient $child) => ['id' => $child->id, 'name' => $child->social_name ?? $child->name])->values(),
        ]);
    }

    /**
     * What every signed-in portal page shows around its content: the network and how to address the patient.
     *
     * @return array{network: array{name: string, slug: string}, patient: array{name: string}, unread: int}
     */
    public static function shell(PatientAccount $account): array
    {
        $patient = Patient::query()->findOrFail($account->effectivePatientId());

        // Step 7b.3: the unread notices, for the bell in the header.
        return ['network' => self::network(), 'patient' => ['name' => $patient->social_name ?? $patient->name], 'unread' => PortalNotificationController::unread($account)];
    }

    /**
     * @return array{name: string, slug: string}
     */
    public static function network(): array
    {
        $tenant = app(CurrentTenant::class)->require();

        return ['name' => $tenant->name, 'slug' => $tenant->slug];
    }
}
