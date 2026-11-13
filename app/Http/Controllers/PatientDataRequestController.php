<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\PatientDataRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7a.3b: the reception's list of correction requests from the portal; it edits the record and marks the
 * request resolved.
 */
class PatientDataRequestController extends Controller
{
    private const LIMIT = 100;

    public function index(): Response
    {
        Gate::authorize('viewAny', Patient::class);

        return Inertia::render('patients/data-requests', [
            'requests' => PatientDataRequest::query()->whereNull('resolved_at')->with('patient:id,name,social_name,merged_into_id')->orderBy('id')->limit(self::LIMIT)->get()
                ->map(fn (PatientDataRequest $request) => [
                    'id' => $request->id,
                    // A record merged after the request opens the kept one.
                    'patientId' => $request->patient === null ? $request->patient_id : ($request->patient->merged_into_id ?? $request->patient_id),
                    'patient' => $request->patient === null ? null : ($request->patient->social_name ?? $request->patient->name),
                    'message' => $request->message,
                    'createdAt' => $request->created_at->toIso8601String(),
                ])->values(),
        ]);
    }

    public function resolve(Request $request, PatientDataRequest $dataRequest): RedirectResponse
    {
        Gate::authorize('update', $dataRequest->patient()->firstOrFail());

        DB::transaction(function () use ($request, $dataRequest) {
            $locked = PatientDataRequest::query()->whereKey($dataRequest->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->resolved_at !== null, 404);
            $locked->forceFill(['resolved_at' => now(), 'resolved_by_user_id' => $request->user()?->id])->save();
            AuditEvent::record('patient_portal.correction_resolved', $locked->tenant_id, $locked);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pedido marcado como resolvido.']);

        return back();
    }
}
