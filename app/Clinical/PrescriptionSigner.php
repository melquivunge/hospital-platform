<?php

namespace App\Clinical;

use App\Models\Professional;
use SensitiveParameter;

/**
 * A certificate signer (ADR-0006): a test one for development and CI, one per cloud certificate provider (PSC)
 * once there is a contract. Its first real adapter is written from the provider's official documentation.
 */
interface PrescriptionSigner
{
    /** Stored with each account and signature. */
    public function provider(): string;

    /** True only for an ICP-Brasil qualified signature; the test signer never is. */
    public function qualified(): bool;

    /**
     * Links the professional once; returns the credential to keep (encrypted) for later signatures.
     */
    public function link(Professional $professional): string;

    /**
     * Signs the document; returns a detached CMS signature (DER) over its bytes.
     */
    public function sign(#[SensitiveParameter] string $document, #[SensitiveParameter] string $credential): string;
}
