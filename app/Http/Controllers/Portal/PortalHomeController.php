<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientAccount;
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
        $patient = Patient::query()->findOrFail($account->effectivePatientId());
        $tenant = app(CurrentTenant::class)->require();

        return Inertia::render('portal/home', [
            'network' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'patient' => ['name' => $patient->social_name ?? $patient->name],
        ]);
    }
}
