<?php

namespace App\Clinical;

use App\Models\Professional;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;
use SensitiveParameter;

/**
 * The test signer (development and CI only; refused in production): a local test authority issues each linked
 * professional a certificate whose holder is "NAME:CPF", as on an ICP-Brasil e-CPF, and signs with it. Its
 * signatures have no legal value.
 *
 * ponytail: the test signer keeps the professional's key in the (encrypted) credential and returns a detached
 * CMS signature. A real provider keeps the key and returns PAdES; its adapter extracts what the validator needs.
 */
class TestPrescriptionSigner implements PrescriptionSigner
{
    /** On the local disk; the trust anchor of the test signatures. */
    public const AUTHORITY = 'icp-test/ca.pem';

    private const AUTHORITY_KEY = 'icp-test/ca.key';

    private const OPTIONS = ['digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

    public function provider(): string
    {
        return 'test';
    }

    public function qualified(): bool
    {
        return false;
    }

    public function link(Professional $professional): string
    {
        [$authority, $authorityKey] = $this->authority();
        $key = openssl_pkey_new(self::OPTIONS) ?: throw new RuntimeException('Test key not created.');
        // A common name holds at most 64 characters: the name is cut before the CPF, as certificate authorities do.
        $csr = openssl_csr_new(['commonName' => mb_substr(mb_strtoupper($professional->name), 0, 52).':'.$professional->cpf], $key, self::OPTIONS);
        $certificate = $csr === false || $csr === true ? false
            : openssl_csr_sign($csr, $authority, $authorityKey, 365, self::OPTIONS + ['x509_extensions' => 'usr_cert'], random_int(1, PHP_INT_MAX));
        if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($key, $keyPem)) {
            throw new RuntimeException('Test certificate not issued.');
        }

        return json_encode(['certificate' => $certificatePem, 'key' => $keyPem], JSON_THROW_ON_ERROR);
    }

    public function sign(#[SensitiveParameter] string $document, #[SensitiveParameter] string $credential): string
    {
        /** @var array{certificate: string, key: string} $account */
        $account = json_decode($credential, true, 512, JSON_THROW_ON_ERROR);
        $input = tempnam(sys_get_temp_dir(), 'icp');
        $output = tempnam(sys_get_temp_dir(), 'icp');
        try {
            file_put_contents($input, $document);
            if (! openssl_cms_sign($input, $output, $account['certificate'], $account['key'], [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER)) {
                throw new RuntimeException('Test signature failed.');
            }

            return (string) file_get_contents($output);
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    /**
     * The test authority, created once (under a lock: two first signatures must not create two authorities).
     *
     * @return array{OpenSSLCertificate, OpenSSLAsymmetricKey}
     */
    private function authority(): array
    {
        $disk = Storage::disk('local');
        Cache::lock('icp-test-authority', 10)->block(10, function () use ($disk) {
            if ($disk->exists(self::AUTHORITY) && $disk->exists(self::AUTHORITY_KEY)) {
                return;
            }
            $key = openssl_pkey_new(self::OPTIONS) ?: throw new RuntimeException('Test authority key not created.');
            $csr = openssl_csr_new(['commonName' => 'AC de teste (sem validade jurídica)'], $key, self::OPTIONS);
            $certificate = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 3650, self::OPTIONS + ['x509_extensions' => 'v3_ca'], 1);
            if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($key, $keyPem)) {
                throw new RuntimeException('Test authority not created.');
            }
            $disk->put(self::AUTHORITY_KEY, $keyPem);
            $disk->put(self::AUTHORITY, $certificatePem);
        });
        $certificate = openssl_x509_read((string) $disk->get(self::AUTHORITY));
        $key = openssl_pkey_get_private((string) $disk->get(self::AUTHORITY_KEY));
        if ($certificate === false || $key === false) {
            throw new RuntimeException('Test authority unreadable.');
        }

        return [$certificate, $key];
    }
}
