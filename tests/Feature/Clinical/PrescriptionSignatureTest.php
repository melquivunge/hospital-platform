<?php

namespace Tests\Feature\Clinical;

use App\Clinical\PrescriptionCertification;
use App\Clinical\TestPrescriptionSigner;
use App\Models\AuditEvent;
use App\Models\EmergencyEpisode;
use App\Models\Medication;
use App\Models\Prescription;
use App\Models\PrescriptionSignature;
use App\Models\SigningAccount;
use App\Models\Visit;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Step 4b.5 (ADR-0006): the doctor links the (test) certificate signer once; a prescription signed with the
 * password is then signed digitally, validated (signature, chain, holder's CPF) and kept with its document.
 */
class PrescriptionSignatureTest extends ClinicalTestCase
{
    private const CPF = '52998224725';

    private Visit $visit;

    private Medication $medication;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.icp.signer' => 'test', 'services.icp.trust_anchors' => Storage::disk('local')->path(TestPrescriptionSigner::AUTHORITY)]);
        app(CurrentTenant::class)->set($this->unit->tenant);
        $this->doctor->forceFill(['council' => 'CRM', 'council_number' => '123456', 'council_uf' => 'SP', 'cpf' => self::CPF])->save();
        $this->medication = Medication::query()->create([
            'active_ingredient' => 'Dipirona', 'form' => 'Comprimido', 'strength' => '500 mg', 'dose_unit' => 'mg', 'routes' => ['oral'],
            'controlled' => false, 'high_alert' => false, 'max_single_dose' => null, 'max_daily_dose' => null,
        ]);
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->reception->user)->post(route('emergency.arrive', $this->unit), ['patient_id' => $this->patient->id])->assertSessionHasNoErrors();
        $episode = EmergencyEpisode::withoutTenantScope()->sole();
        $this->actingAs($this->doctorAccount->user)->post(route('emergency.triage', $episode), ['level' => 2, 'complaint' => 'Dor.']);
        $this->post(route('emergency.call', $episode))->assertSessionHasNoErrors();
        $this->visit = Visit::withoutTenantScope()->sole();
    }

    private function signed(): Prescription
    {
        $this->actingAs($this->doctorAccount->user)->post(route('visits.prescriptions.start', $this->visit))->assertRedirect();
        $prescription = Prescription::withoutTenantScope()->where('status', 'draft')->sole();
        $this->post(route('prescriptions.items.store', $prescription), [
            'medication_id' => $this->medication->id, 'dose' => '500', 'route' => 'oral', 'schedule' => 'interval', 'frequency_hours' => 6, 'prn' => '0',
        ])->assertSessionHasNoErrors();
        $this->post(route('prescriptions.sign', $prescription), ['password' => 'password'])->assertRedirect(route('visits.show', $this->visit));

        return $prescription->refresh();
    }

    public function test_a_linked_doctor_signs_with_the_certificate_and_the_signature_is_validated_and_kept(): void
    {
        $this->actingAs($this->doctorAccount->user)->get(route('certificate.edit'))->assertInertia(fn (Assert $page) => $page->component('settings/certificate')
            ->where('provider.label', 'certificado de teste (sem validade jurídica)')->where('provider.qualified', false)
            ->where('professional.hasCpf', true)->where('linkedAt', null));
        $this->post(route('certificate.link'))->assertSessionHasNoErrors();
        // The credential is encrypted at rest.
        $raw = (string) DB::table('signing_accounts')->value('credential');
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->assertSame(1, AuditEvent::where('action', 'signing_account.linked')->count());

        $prescription = $this->signed();
        $signature = PrescriptionSignature::withoutTenantScope()->sole();
        $this->assertSame([$prescription->id, 'test', false, self::CPF, 'password'], [$signature->prescription_id, $signature->provider, $signature->qualified, $signature->signer_cpf, $prescription->signature_method]);
        $this->assertStringStartsWith(mb_substr(mb_strtoupper($this->doctor->name), 0, 20), $signature->signer_name);
        $document = (string) Storage::disk('local')->get($signature->document_path);
        $p7s = (string) Storage::disk('local')->get($signature->signature_path);
        $this->assertStringStartsWith('%PDF', $document);
        $this->assertSame(hash('sha256', $document), $signature->document_sha256);
        $this->assertStringStartsWith("clinical/{$prescription->tenant_id}/", $signature->document_path);
        // The validation stands on its own: the kept document verifies, a changed byte does not.
        $this->assertSame(self::CPF, PrescriptionCertification::validate($document, $p7s)['cpf'] ?? null);
        $this->assertNull(PrescriptionCertification::validate($document.' ', $p7s));
        $this->assertEquals(['provider' => 'test', 'qualified' => false], AuditEvent::where('action', 'prescription.certified')->sole()->context);

        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.certificate.label', 'certificado de teste (sem validade jurídica)')
            ->where('prescriptions.active.certificate.holder', $signature->signer_name)
            ->where('prescriptions.active.canCertify', false));
        $this->post(route('prescriptions.certify', $prescription))->assertSessionHasErrors(['certificate' => 'Esta prescrição já foi assinada com certificado.']);

        // Downloads: with clinical access only, each one logged.
        $this->get(route('prescriptions.signature', [$prescription, 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('prescriptions.signature', [$prescription, 'p7s']))->assertOk();
        $this->get('/prescriptions/'.$prescription->id.'/signature/key')->assertNotFound();
        $this->assertSame(2, AuditEvent::where('action', 'prescription.signature_downloaded')->count());
        $this->actingAs($this->reception->user)->get(route('prescriptions.signature', [$prescription, 'pdf']))->assertForbidden();

        // The database refuses any change to a kept signature.
        app(CurrentTenant::class)->set($this->unit->tenant);
        foreach ([
            fn () => DB::table('prescription_signatures')->update(['signer_cpf' => '11144477735']),
            fn () => DB::table('prescription_signatures')->delete(),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('A prescription signature changed.');
            } catch (QueryException $exception) {
                $this->assertSame('P0001', $exception->getCode());
            }
        }
        app(CurrentTenant::class)->set(null);
    }

    public function test_without_a_link_the_password_signature_stands_and_the_author_signs_digitally_later(): void
    {
        $prescription = $this->signed();
        $this->assertSame(0, PrescriptionSignature::withoutTenantScope()->count());
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page
            ->where('prescriptions.active.certificate', null)->where('prescriptions.active.canCertify', false));
        $this->post(route('prescriptions.certify', $prescription))->assertSessionHasErrors('certificate');

        $this->post(route('certificate.link'))->assertSessionHasNoErrors();
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('prescriptions.active.canCertify', true));
        // Only the author signs the prescription digitally.
        $this->actingAs($this->nurseAccount->user)->post(route('prescriptions.certify', $prescription))->assertForbidden();
        $this->actingAs($this->doctorAccount->user)->post(route('prescriptions.certify', $prescription))->assertSessionHasNoErrors();
        $this->assertSame(1, PrescriptionSignature::withoutTenantScope()->count());

        // Unlinking deletes the credential.
        $this->delete(route('certificate.unlink'))->assertSessionHasNoErrors();
        $this->assertSame(0, SigningAccount::withoutTenantScope()->count());
    }

    public function test_a_holder_with_another_cpf_is_refused_and_losing_access_deletes_the_credential(): void
    {
        $this->actingAs($this->doctorAccount->user)->post(route('certificate.link'))->assertSessionHasNoErrors();
        // The administration corrects the CPF after the certificate was issued: the holder no longer matches.
        $this->doctor->forceFill(['cpf' => '11144477735'])->saveQuietly();
        $files = count(Storage::disk('local')->allFiles('clinical'));
        $prescription = $this->signed();
        $this->assertSame(0, PrescriptionSignature::withoutTenantScope()->count());
        $this->assertSame($files, count(Storage::disk('local')->allFiles('clinical')));
        $this->assertEquals(['reason' => 'cpf_mismatch'], AuditEvent::where('action', 'prescription.certificate_rejected')->sole()->context);
        $this->post(route('prescriptions.certify', $prescription))->assertSessionHasErrors(['certificate' => 'O CPF do certificado não é o do seu cadastro de profissional. A prescrição continua assinada com senha.']);

        // Without a CPF there is nothing to link.
        $this->doctor->forceFill(['cpf' => null])->saveQuietly();
        $this->post(route('certificate.link'))->assertSessionHasErrors('certificate');

        $this->doctor->forceFill(['cpf' => self::CPF])->saveQuietly();
        $this->post(route('certificate.link'))->assertSessionHasNoErrors();
        $this->assertSame(1, SigningAccount::withoutTenantScope()->count());
        $this->doctorAccount->revoke();
        $this->assertSame(0, SigningAccount::withoutTenantScope()->count());
    }

    public function test_only_the_configured_anchors_are_trusted(): void
    {
        // The test authority exists once someone linked.
        (new TestPrescriptionSigner)->link($this->doctor);
        // A decoy authority placed in the system CA directory issues a certificate with the doctor's CPF.
        $options = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $decoyKey = openssl_pkey_new($options);
        $decoy = openssl_csr_sign(openssl_csr_new(['commonName' => 'Decoy CA'], $decoyKey, $options), null, $decoyKey, 30, $options + ['x509_extensions' => 'v3_ca'], 7);
        $key = openssl_pkey_new($options);
        $leaf = openssl_csr_sign(openssl_csr_new(['commonName' => 'INTRUSO:'.self::CPF], $key, $options), $decoy, $decoyKey, 30, $options + ['x509_extensions' => 'usr_cert'], 8);
        openssl_x509_export($decoy, $decoyPem);
        openssl_x509_export($leaf, $leafPem);
        openssl_pkey_export($key, $keyPem);
        $store = sys_get_temp_dir().'/decoy-store-'.getmypid();
        @mkdir($store);
        file_put_contents($store.'/'.openssl_x509_parse($decoyPem)['hash'].'.0', $decoyPem);
        $document = '%PDF-1.4 prescrição falsa';
        $signature = (new TestPrescriptionSigner)->sign($document, json_encode(['certificate' => $leafPem, 'key' => $keyPem]));
        $previous = getenv('SSL_CERT_DIR');
        putenv("SSL_CERT_DIR={$store}");
        try {
            $this->assertNull(PrescriptionCertification::validate($document, $signature));
        } finally {
            putenv($previous === false ? 'SSL_CERT_DIR' : "SSL_CERT_DIR={$previous}");
            array_map('unlink', glob($store.'/*') ?: []);
            @rmdir($store);
        }
    }

    public function test_an_unexpected_failure_after_the_password_keeps_the_prescription_signed(): void
    {
        $this->actingAs($this->doctorAccount->user)->post(route('certificate.link'))->assertSessionHasNoErrors();
        // The stored credential is unusable (a broken provider answer): the password signature stands.
        app(CurrentTenant::class)->set($this->unit->tenant);
        SigningAccount::query()->sole()->forceFill(['credential' => '{"certificate":"x","key":"y"}'])->save();
        app(CurrentTenant::class)->set(null);
        $prescription = $this->signed();
        $this->assertSame(['signed', 0], [$prescription->status, PrescriptionSignature::withoutTenantScope()->count()]);
        $this->get(route('visits.show', $this->visit))->assertInertia(fn (Assert $page) => $page->where('prescriptions.active.canCertify', true));
    }

    public function test_the_professional_cpf_is_checked(): void
    {
        $update = fn (string $cpf) => $this->actingAs($this->manager->user)->put(route('professionals.update', $this->nurse), [
            'name' => $this->nurse->name, 'base_unit_id' => $this->nurse->base_unit_id, 'cpf' => $cpf,
        ]);
        $update('111.111.111-11')->assertSessionHasErrors(['cpf' => 'CPF inválido.']);
        $update('529.982.247-25')->assertSessionHasErrors(['cpf' => 'Já existe um profissional com este CPF.']);
        $update('111.444.777-35')->assertSessionHasNoErrors();
        $this->assertSame('11144477735', $this->nurse->refresh()->cpf);
    }
}
