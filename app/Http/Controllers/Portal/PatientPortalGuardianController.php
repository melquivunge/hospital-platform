<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientAccountLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Step 7a.2 (contract, decision 5): the reception checks the guardianship document and links the guardian's
 * portal account to the minor, or ends the link. The guardian must already have an active portal account here.
 */
class PatientPortalGuardianController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        Gate::authorize('update', $patient);
        $data = $request->validate(
            ['email' => ['required', 'string', 'email', 'max:255'], 'document_checked' => ['accepted']],
            ['document_checked.accepted' => 'Confira o documento que prova a guarda antes de vincular.'],
        );

        $problem = match (true) {
            $patient->merged_into_id !== null => 'Este cadastro foi unificado. Vincule pelo cadastro principal.',
            ! PatientAccountLink::isMinor($patient) => 'Só um paciente menor de idade, com data de nascimento no cadastro, recebe um responsável no portal.',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['guardian' => $problem]);
        }

        $account = PatientAccount::query()->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->whereNotNull('password')->first();

        if ($account === null) {
            throw ValidationException::withMessages(['email' => 'Nenhum acesso ativo ao portal com este e-mail. O responsável precisa ter o próprio acesso antes.']);
        }

        if ($account->patient_id === $patient->id) {
            throw ValidationException::withMessages(['email' => 'Este é o acesso do próprio paciente.']);
        }

        // The guardian is an adult: a minor's own account never becomes someone's guardian.
        if (PatientAccountLink::isMinor(Patient::query()->findOrFail($account->effectivePatientId()))) {
            throw ValidationException::withMessages(['email' => 'Este acesso é de um paciente menor de idade e não pode ser responsável.']);
        }

        DB::transaction(function () use ($request, $patient, $account) {
            // One act at a time per minor: the latest row decides.
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();

            if (in_array($account->id, PatientAccountLink::guardiansOf($patient), true)) {
                throw ValidationException::withMessages(['email' => 'Este responsável já está vinculado.']);
            }

            self::record($request, $patient, $account, 'linked');
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Responsável vinculado. O paciente aparece na aba "Filhos menores" do portal dele.']);

        return back();
    }

    public function destroy(Request $request, Patient $patient, PatientAccount $account): RedirectResponse
    {
        Gate::authorize('update', $patient);

        DB::transaction(function () use ($request, $patient, $account) {
            Patient::query()->whereKey($patient->id)->lockForUpdate()->first();
            abort_unless(in_array($account->id, PatientAccountLink::guardiansOf($patient), true), 404);
            self::record($request, $patient, $account, 'ended');
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vínculo encerrado. O responsável deixa de ver este paciente no portal.']);

        return back();
    }

    private static function record(Request $request, Patient $patient, PatientAccount $account, string $event): void
    {
        $link = new PatientAccountLink;
        $link->forceFill([
            'tenant_id' => $patient->tenant_id,
            'account_id' => $account->id,
            'patient_id' => $patient->id,
            'event' => $event,
            'actor_user_id' => $request->user()?->id,
        ])->save();
        AuditEvent::record($event === 'linked' ? 'patient_portal.guardian_linked' : 'patient_portal.guardian_ended', $patient->tenant_id, $link, ['account_id' => $account->id, 'patient_id' => $patient->id]);
    }
}
