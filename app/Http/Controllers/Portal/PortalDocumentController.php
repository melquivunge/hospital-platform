<?php

namespace App\Http\Controllers\Portal;

use App\Clinical\ClinicalAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ClinicalDocument;
use App\Models\PatientAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step 7a.4: a document released to the patient, for the patient or the guardian of a linked minor; anything not
 * released (or revoked, or withdrawn), or anyone else's, answers 404.
 */
class PortalDocumentController extends Controller
{
    public function __invoke(string $portal, int $document): StreamedResponse
    {
        /** @var PatientAccount|null $account */
        $account = Auth::guard('patient')->user();
        abort_if($account === null, 403);
        $found = ClinicalDocument::query()->whereKey($document)->firstOrFail();

        $kept = collect(PortalRecord::readable($account))->first(fn (int $id) => in_array($found->patient_id, PortalRecord::recordIds($id), true));
        abort_if($kept === null || ! PortalRecord::releasedDocuments([$found->patient_id])->contains('id', $found->id), 404);
        abort_unless(str_starts_with($found->path, "clinical/{$found->tenant_id}/") && ! str_contains($found->path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($found->path), 404);

        ClinicalAccess::recordPortalRead($account, $kept);
        AuditEvent::record('patient_portal.document_downloaded', $found->tenant_id, $found, byStaff: false);

        return Storage::disk('local')->download($found->path, $found->original_name, [
            'Content-Type' => $found->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
