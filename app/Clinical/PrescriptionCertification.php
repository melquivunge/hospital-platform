<?php

namespace App\Clinical;

use App\Http\Controllers\PrescriptionController;
use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionSignature;
use App\Models\Professional;
use App\Models\SigningAccount;
use App\Models\Unit;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Step 4b.5 (ADR-0006): the digital signature of a prescription already signed with the password. The canonical
 * PDF is generated, signed by the author's certificate and validated before it is kept: the signature over the
 * PDF, the chain up to the configured trust anchors and the holder's CPF equal to the professional's. Without a
 * signer configured, prescriptions keep the simple electronic signature (the password).
 */
class PrescriptionCertification
{
    public const LABELS = ['test' => 'certificado de teste (sem validade jurídica)'];

    public static function signer(): ?PrescriptionSigner
    {
        $name = strtolower(trim((string) config('services.icp.signer')));
        if ($name !== '' && $name !== 'test') {
            // A misconfigured signer must not take the clinical pages down: the password signature stands.
            report(new RuntimeException('Unknown ICP_SIGNER; certificate signing disabled.'));
        }

        return $name === 'test' ? new TestPrescriptionSigner : null;
    }

    public static function label(string $provider): string
    {
        return self::LABELS[$provider] ?? $provider;
    }

    /**
     * The author's account with the configured signer, if linked.
     */
    public static function account(User $user): ?SigningAccount
    {
        $signer = self::signer();

        return $signer === null ? null : SigningAccount::query()->where('user_id', $user->id)->where('provider', $signer->provider())->first();
    }

    /**
     * Signs the prescription with the author's certificate. Errors are shown in the "certificate" field.
     */
    public static function certify(Prescription $prescription, User $user): PrescriptionSignature
    {
        $signer = self::signer() ?? throw ValidationException::withMessages(['certificate' => 'A assinatura com certificado não está disponível.']);
        $account = self::account($user) ?? throw ValidationException::withMessages(['certificate' => 'Vincule seu certificado digital em Configurações antes de assinar com ele.']);
        $professional = Professional::query()->findOrFail($prescription->professional_id);
        if ($professional->cpf === null) {
            throw ValidationException::withMessages(['certificate' => 'Seu cadastro de profissional não tem CPF. Peça à administração para incluí-lo.']);
        }
        if (PrescriptionSignature::query()->where('prescription_id', $prescription->id)->exists()) {
            throw ValidationException::withMessages(['certificate' => 'Esta prescrição já foi assinada com certificado.']);
        }

        try {
            $document = self::document($prescription, $professional);
            $signature = $signer->sign($document, $account->credential);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['certificate' => 'O assinador não respondeu. A prescrição continua assinada com senha; tente de novo.']);
        }
        $holder = self::validate($document, $signature);
        if ($holder === null || $holder['cpf'] !== $professional->cpf) {
            AuditEvent::record('prescription.certificate_rejected', $prescription->tenant_id, $prescription, ['reason' => $holder === null ? 'invalid' : 'cpf_mismatch']);
            throw ValidationException::withMessages(['certificate' => $holder === null
                ? 'A assinatura recebida não é válida. A prescrição continua assinada com senha.'
                : 'O CPF do certificado não é o do seu cadastro de profissional. A prescrição continua assinada com senha.']);
        }

        $base = "clinical/{$prescription->tenant_id}/".Str::random(40);
        $disk = Storage::disk('local');
        if (! $disk->put("{$base}.pdf", $document) || ! $disk->put("{$base}.p7s", $signature)) {
            $disk->delete(["{$base}.pdf", "{$base}.p7s"]);
            throw new RuntimeException('Prescription signature could not be stored.');
        }
        try {
            return DB::transaction(function () use ($prescription, $signer, $base, $document, $holder, $user) {
                $stored = PrescriptionSignature::query()->create([
                    'prescription_id' => $prescription->id, 'provider' => $signer->provider(), 'qualified' => $signer->qualified(),
                    'document_path' => "{$base}.pdf", 'document_sha256' => hash('sha256', $document), 'signature_path' => "{$base}.p7s",
                    'signer_name' => $holder['name'], 'signer_cpf' => $holder['cpf'], 'certificate_serial' => $holder['serial'],
                    'certificate_issuer' => $holder['issuer'], 'signed_by_user_id' => $user->id, 'signed_at' => now(),
                ]);
                AuditEvent::record('prescription.certified', $prescription->tenant_id, $prescription, ['provider' => $signer->provider(), 'qualified' => $signer->qualified()]);

                return $stored;
            });
        } catch (Throwable $exception) {
            $disk->delete(["{$base}.pdf", "{$base}.p7s"]);
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['certificate' => 'Esta prescrição já foi assinada com certificado.']);
            }

            throw $exception;
        }
    }

    /**
     * The holder of a valid signature over the document, or null: the signature must match the bytes and its
     * certificate chain up to a trust anchor. The holder comes from the common name "NAME:CPF" (ICP-Brasil e-CPF).
     *
     * @return array{name: string, cpf: string, serial: string, issuer: string}|null
     */
    public static function validate(#[SensitiveParameter] string $document, string $signature): ?array
    {
        $anchors = config('services.icp.trust_anchors');
        if (! is_string($anchors) || ! is_file($anchors)) {
            return null;
        }
        $files = ['document' => tempnam(sys_get_temp_dir(), 'icp'), 'signature' => tempnam(sys_get_temp_dir(), 'icp'), 'signer' => tempnam(sys_get_temp_dir(), 'icp')];
        // Only the configured anchors: with a file alone, OpenSSL also trusts the system CA directory, so an
        // empty directory is passed in its place.
        $noSystemStore = sys_get_temp_dir().'/icp-no-ca-'.getmypid();
        @mkdir($noSystemStore, 0700);
        try {
            file_put_contents($files['document'], $document);
            file_put_contents($files['signature'], $signature);
            if (openssl_cms_verify($files['document'], OPENSSL_CMS_BINARY | OPENSSL_CMS_DETACHED, $files['signer'], [$anchors, $noSystemStore], null, null, null, $files['signature'], OPENSSL_ENCODING_DER) !== true) {
                return null;
            }
            $certificate = openssl_x509_parse((string) file_get_contents($files['signer']));
        } finally {
            array_map(fn (string $file) => @unlink($file), $files);
        }
        // ponytail: the holder comes from the common name, as the test signer issues it. The first real adapter
        // reads the CPF from the ICP-Brasil otherName (OID 2.16.76.1.3.1) and checks key usage and policy OIDs.
        $commonName = $certificate === false ? null : ($certificate['subject']['CN'] ?? null);
        if (! is_string($commonName) || preg_match('/^(.+):(\d{11})$/', $commonName, $holder) !== 1) {
            return null;
        }
        $issuer = $certificate['issuer']['CN'] ?? '';

        return ['name' => $holder[1], 'cpf' => $holder[2], 'serial' => (string) $certificate['serialNumberHex'], 'issuer' => is_string($issuer) ? $issuer : ''];
    }

    /**
     * The canonical document: what was signed with the password, in the unit's time.
     */
    public static function document(Prescription $prescription, Professional $professional): string
    {
        $unit = Unit::query()->with('tenant')->findOrFail($prescription->unit_id);
        $timezone = $unit->timezone ?? 'UTC';
        $patient = Patient::query()->findOrFail($prescription->patient_id);
        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
        $pdf->loadHtml(view('prescriptions.document', [
            'test' => ! (self::signer()?->qualified() ?? false),
            'network' => $unit->tenant->name, 'unit' => $unit->name, 'prescription' => $prescription->id,
            'patient' => $patient->name, 'socialName' => $patient->social_name, 'birthDate' => $patient->birth_date?->format('d/m/Y'),
            'professional' => $professional->name,
            'council' => $professional->council === null ? null : "{$professional->council} {$professional->council_number}/{$professional->council_uf}",
            'signedAt' => $prescription->signed_at?->setTimezone($timezone)->format('d/m/Y H:i'),
            'validUntil' => $prescription->valid_until?->setTimezone($timezone)->format('d/m/Y H:i'),
            'items' => $prescription->items()->orderBy('id')->get()->map(fn (PrescriptionItem $item) => PrescriptionController::describe($item, $timezone))->all(),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }
}
