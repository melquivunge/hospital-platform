<?php

namespace App\Http\Controllers\Portal;

use App\Clinical\ClinicalAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ExamResult;
use App\Models\PatientAccount;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step 7a.3: the PDF of a result, for its patient or the guardian of a linked minor; anyone else's answers 404.
 */
class PortalResultPdfController extends Controller
{
    public function __invoke(string $portal, int $result): StreamedResponse
    {
        /** @var PatientAccount|null $account */
        $account = Auth::guard('patient')->user();
        abort_if($account === null, 403);
        $found = ExamResult::query()->whereKey($result)->firstOrFail();

        // Whose record it belongs to now (after a merge, the kept one), among those the account reads.
        $kept = collect(PortalRecord::readable($account))->first(fn (int $id) => in_array($found->patient_id, PortalRecord::recordIds($id), true));
        abort_if($kept === null || $found->attachment_path === null, 404);
        // Only the current version: a rectified result is no longer shown.
        abort_if(ExamResult::query()->where('exam_order_item_id', $found->exam_order_item_id)->where('version', '>', $found->version)->exists(), 404);
        abort_unless(str_starts_with($found->attachment_path, "clinical/{$found->tenant_id}/") && ! str_contains($found->attachment_path, '..'), 404);
        abort_unless(Storage::disk('local')->exists($found->attachment_path), 404);

        ClinicalAccess::recordPortalRead($account, $kept);
        AuditEvent::record('patient_portal.result_downloaded', $found->tenant_id, $found, byStaff: false);

        return Storage::disk('local')->download($found->attachment_path, (string) $found->attachment_name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
