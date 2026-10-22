<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Clinical\PrescriptionCertification;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionSignature;
use App\Models\SigningAccount;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step 4b.5 (ADR-0006): the person links the certificate signer once (Settings), the author signs a prescription
 * with it (again, when the signature right after the password one failed), and the clinical team downloads the
 * signed document and its signature.
 */
class PrescriptionSignatureController extends Controller
{
    public function store(Request $request, Prescription $prescription): RedirectResponse
    {
        abort_unless($prescription->author_user_id === $request->user()->id && $prescription->status === 'signed', 403);
        $digital = PrescriptionCertification::certify($prescription, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Prescrição assinada com '.PrescriptionCertification::label($digital->provider).'.']);

        return back();
    }

    /**
     * The signed document (pdf) or its detached signature (p7s), with the same access as clinical documents.
     */
    public function download(Request $request, Prescription $prescription, string $part): StreamedResponse
    {
        $signature = PrescriptionSignature::query()->where('prescription_id', $prescription->id)->firstOrFail();
        $path = $part === 'pdf' ? $signature->document_path : $signature->signature_path;
        // Never trust a stored path blindly: it must sit under this client's own prefix.
        abort_unless(str_starts_with($path, "clinical/{$signature->tenant_id}/") && ! str_contains($path, '..'), 404);
        $patient = Patient::query()->findOrFail($prescription->patient_id);
        $basis = ClinicalAccess::activeVisit($request->user(), $patient) ?? ClinicalAccess::activeAdmission($request->user(), $patient)
            ?? ClinicalAccess::activeEmergency($request->user(), $patient) ?? abort(403);
        abort_unless(Storage::disk('local')->exists($path), 404);
        // The PDF served is the one that was signed.
        abort_if($part === 'pdf' && hash('sha256', (string) Storage::disk('local')->get($path)) !== $signature->document_sha256, 409, 'O documento guardado não confere com o assinado.');

        ClinicalAccess::recordRead($request->user(), $patient, $basis);
        AuditEvent::record('prescription.signature_downloaded', $signature->tenant_id, $prescription, ['part' => $part, 'reason' => match (true) {
            $basis instanceof Visit => 'visit',
            $basis instanceof Admission => 'admission',
            default => 'emergency',
        }]);

        return Storage::disk('local')->download($path, "prescricao-{$prescription->id}.{$part}", [
            'Content-Type' => $part === 'pdf' ? 'application/pdf' : 'application/pkcs7-signature',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function edit(Request $request): Response
    {
        $signer = PrescriptionCertification::signer();
        $professional = ClinicalAccess::professional($request->user());
        $account = PrescriptionCertification::account($request->user());

        return Inertia::render('settings/certificate', [
            'provider' => $signer === null ? null : ['label' => PrescriptionCertification::label($signer->provider()), 'qualified' => $signer->qualified()],
            'professional' => $professional === null ? null : ['name' => $professional->name, 'hasCpf' => $professional->cpf !== null],
            'linkedAt' => $account?->created_at->format('d/m/Y'),
        ]);
    }

    public function link(Request $request): RedirectResponse
    {
        $signer = PrescriptionCertification::signer() ?? abort(404);
        $professional = ClinicalAccess::professional($request->user()) ?? abort(403);
        if ($professional->cpf === null) {
            throw ValidationException::withMessages(['certificate' => 'Seu cadastro de profissional não tem CPF. Peça à administração para incluí-lo.']);
        }
        // Issued first, then swapped in one transaction: a failed issue keeps the working link. Linking again replaces
        // the account (a new certificate, or another provider).
        $credential = $signer->link($professional);
        try {
            DB::transaction(function () use ($request, $signer, $credential) {
                SigningAccount::query()->where('user_id', $request->user()->id)->delete();
                SigningAccount::query()->create(['user_id' => $request->user()->id, 'provider' => $signer->provider(), 'credential' => $credential, 'created_at' => now()]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['certificate' => 'O certificado acabou de ser vinculado em outra janela.']);
        }
        AuditEvent::record('signing_account.linked', $professional->tenant_id, $request->user(), ['provider' => $signer->provider()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Certificado digital vinculado.']);

        return back();
    }

    public function unlink(Request $request): RedirectResponse
    {
        if (SigningAccount::query()->where('user_id', $request->user()->id)->delete() > 0) {
            AuditEvent::record('signing_account.unlinked', app(CurrentTenant::class)->id(), $request->user(), ['reason' => 'user']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Certificado digital desvinculado.']);

        return back();
    }
}
