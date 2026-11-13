<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7a.2: a minor linked to the guardian's account, with the same content as the patient's own start page
 * (7a.3 fills it). Anyone else's id answers 404.
 */
class PortalChildController extends Controller
{
    public function __invoke(string $portal, int $patient): Response
    {
        /** @var PatientAccount|null $account */
        $account = Auth::guard('patient')->user();
        abort_if($account === null, 403);
        abort_unless(in_array($patient, PatientAccountLink::minorsOf($account), true), 404);
        $child = Patient::query()->findOrFail($patient);

        return Inertia::render('portal/child', [
            ...PortalHomeController::shell($account),
            'child' => ['id' => $child->id, 'name' => $child->social_name ?? $child->name],
        ]);
    }
}
