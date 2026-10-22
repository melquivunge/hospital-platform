<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The digital signature of a signed prescription (step 4b.5, ADR-0006): the canonical PDF, the signature over
 * it and who the certificate belongs to. Only a signature that passed validation is stored. Never changed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $prescription_id
 * @property string $provider
 * @property bool $qualified
 * @property string $document_path
 * @property string $document_sha256
 * @property string $signature_path
 * @property string $signer_name
 * @property string $signer_cpf
 * @property string $certificate_serial
 * @property string $certificate_issuer
 * @property int $signed_by_user_id
 * @property Carbon $signed_at
 */
#[Fillable(['prescription_id', 'provider', 'qualified', 'document_path', 'document_sha256', 'signature_path', 'signer_name', 'signer_cpf', 'certificate_serial', 'certificate_issuer', 'signed_by_user_id', 'signed_at'])]
class PrescriptionSignature extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['qualified' => 'boolean', 'signed_at' => 'datetime'];
    }
}
