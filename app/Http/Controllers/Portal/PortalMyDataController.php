<?php

namespace App\Http\Controllers\Portal;

use App\Clinical\ClinicalAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientDataRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 7a.3b (contract, LGPD art. 18): the patient reads the registration data and asks the reception to correct
 * it. Nothing is changed from the portal: the reception checks the document and edits the record.
 */
class PortalMyDataController extends Controller
{
    public function show(): Response
    {
        $account = self::account();
        $patient = Patient::query()->findOrFail($account->effectivePatientId());
        ClinicalAccess::recordPortalRead($account, $patient->id);

        return Inertia::render('portal/my-data', [
            ...PortalHomeController::shell($account),
            'data' => [
                'name' => $patient->name,
                'socialName' => $patient->social_name,
                'birthDate' => $patient->birth_date?->toDateString(),
                'cpf' => $patient->cpf,
                'cns' => $patient->cns,
                'motherName' => $patient->mother_name,
                'phone' => $patient->phone,
                'email' => $patient->email,
                'address' => trim(implode(', ', array_filter([
                    trim(($patient->address_street_type ?? '').' '.($patient->address_street ?? '')),
                    $patient->address_number, $patient->address_complement, $patient->address_district, $patient->address_zip,
                ], fn ($part) => filled($part)))) ?: null,
            ],
            'requests' => PatientDataRequest::query()->where('account_id', $account->id)->latest('id')->limit(10)->get()
                ->map(fn (PatientDataRequest $request) => [
                    'id' => $request->id,
                    'message' => $request->message,
                    'createdAt' => $request->created_at->toIso8601String(),
                    'resolved' => $request->resolved_at !== null,
                ])->values(),
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = self::account();
        $message = trim((string) $request->validate(
            ['message' => ['required', 'string', 'max:1000']],
            ['message.required' => 'Conte o que precisa ser corrigido.', 'message.max' => 'Use no máximo 1000 caracteres.'],
        )['message']);

        DB::transaction(function () use ($account, $message) {
            PatientAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            if (PatientDataRequest::query()->where('account_id', $account->id)->whereNull('resolved_at')->count() >= PatientDataRequest::OPEN_LIMIT) {
                throw ValidationException::withMessages(['message' => 'Você já tem pedidos em aberto. Aguarde a recepção responder.']);
            }

            $created = new PatientDataRequest;
            $created->forceFill(['tenant_id' => $account->tenant_id, 'patient_id' => $account->effectivePatientId(), 'account_id' => $account->id, 'message' => $message])->save();
            AuditEvent::record('patient_portal.correction_requested', $account->tenant_id, $created, byStaff: false);
        });

        return to_route('portal.my-data', ['portal' => $request->route('portal')])
            ->with('status', 'Pedido enviado. A recepção confere e corrige o cadastro; leve um documento na próxima visita.');
    }

    private static function account(): PatientAccount
    {
        /** @var PatientAccount|null $account */
        $account = Auth::guard('patient')->user();
        abort_if($account === null, 403);

        return $account;
    }
}
