<?php

namespace App\Jobs;

use App\Http\Controllers\ImportController;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\ImportBatchPolicy;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;

/**
 * Applies the ready lines of a confirmed batch. Resumable: running it again only touches lines that
 * are still ready, so a crash or retry never duplicates records. One job per batch at a time.
 */
class ApplyImportBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tenantId,
        public int $batchId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function handle(CurrentTenant $currentTenant): void
    {
        try {
            // Outside HTTP: tenant and actor come from the batch, and the creator's permission is checked
            // again now; losing the importer role after the preview stops the remaining lines.
            $currentTenant->set(Tenant::query()->findOrFail($this->tenantId));
            $batch = ImportBatch::query()->whereKey($this->batchId)->firstOrFail();
            $creator = User::query()->findOrFail($batch->created_by_user_id);
            Auth::setUser($creator);

            $membership = $creator->deactivated_at === null ? $creator->activeMembershipIn($batch->tenant_id) : null;
            $allowed = $membership === null ? [] : ImportBatchPolicy::importableUnitIds($membership);

            $importer = ImportController::importer($batch->type);
            $batch->items()->where('status', 'ready')->orderBy('id')
                ->each(fn (ImportItem $item) => $importer::apply($item, $batch, $allowed));

            if (! $batch->items()->where('status', 'ready')->exists()) {
                $batch->update(['status' => 'applied']);
                AuditEvent::record('import.applied', $batch->tenant_id, $batch, $batch->counts());
            }
        } finally {
            Auth::forgetUser();
            $currentTenant->set(null);
        }
    }
}
