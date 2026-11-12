<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Step 7a.1a (contract, decision 4): the reception checks the document at the counter and sends the
 * portal invitation to the e-mail on file. A minor reaches the portal through the guardian (7a.2).
 */
class PatientPortalInviteController extends Controller
{
    public const ADULT_AGE = 18;

    public function store(Request $request, Patient $patient): RedirectResponse
    {
        Gate::authorize('update', $patient);
        $request->validate(['document_checked' => ['accepted']], ['document_checked.accepted' => 'Confira o documento do paciente antes de convidar.']);

        $problem = match (true) {
            $patient->merged_into_id !== null => 'Este cadastro foi unificado. Convide pelo cadastro principal.',
            $patient->status !== 'complete' => 'Complete o cadastro (documento, data de nascimento e sexo) antes de convidar.',
            $patient->email === null => 'Cadastre o e-mail do paciente antes de convidar.',
            $patient->birth_date !== null && $patient->birth_date->age < self::ADULT_AGE => 'Paciente menor de idade: o acesso será pelo responsável legal.',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['portal' => $problem]);
        }

        try {
            $account = DB::transaction(function () use ($request, $patient) {
                $account = PatientAccount::query()->where('patient_id', $patient->id)->lockForUpdate()->first();

                if ($account?->password !== null) {
                    throw ValidationException::withMessages(['portal' => 'Este paciente já tem acesso ao portal. Ele pode usar "Esqueci minha senha".']);
                }

                // A pending invitation is renewed with the current e-mail on file.
                $account ??= new PatientAccount;
                $account->forceFill(['tenant_id' => $patient->tenant_id, 'patient_id' => $patient->id, 'email' => $patient->email, 'invited_by_user_id' => $request->user()?->id])->save();

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['portal' => 'Este e-mail já é usado no portal por outro paciente desta instituição.']);
        }

        PortalAuthController::sendLink($account, invitation: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Convite enviado para o e-mail do cadastro. O link vale por 72 horas.']);

        return back();
    }
}
