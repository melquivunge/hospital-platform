<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\ExamCriticalAlert;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Step 5.5 (ADR-0008): a critical result without an acknowledgement 1 hour after its release reaches the unit
 * managers (without one, the network admins), once per result. Scheduled every 5 minutes; the mark is set under
 * the row's lock, so a concurrent run sends nothing. Ids only: no patient, exam or value.
 */
class SendCriticalResultEscalations extends Command
{
    protected $signature = 'exams:critical';

    protected $description = 'Avisa o gestor da unidade sobre resultado crítico sem ciência há 1 hora, uma vez por resultado';

    public function handle(CurrentTenant $currentTenant): int
    {
        $previous = $currentTenant->get();
        $sent = 0;
        try {
            foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
                $currentTenant->set($tenant);
                $due = ExamCriticalAlert::query()->whereNull('acknowledged_at')->whereNull('escalated_at')
                    ->where('created_at', '<=', now()->subMinutes(ExamCriticalAlert::ESCALATE_MINUTES))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id);
                foreach ($due as $id) {
                    try {
                        $sent += (int) DB::transaction(function () use ($id) {
                            /** @var ExamCriticalAlert $alert */
                            $alert = ExamCriticalAlert::query()->lockForUpdate()->whereKey($id)->firstOrFail();
                            // Acknowledged or escalated meanwhile: nothing to send.
                            if ($alert->acknowledged_at !== null || $alert->escalated_at !== null) {
                                return false;
                            }
                            $alert->update(['escalated_at' => now()]);
                            AuditEvent::record('exam_result.critical_unacknowledged', $alert->tenant_id, $alert, ['unit_id' => $alert->unit_id, 'exam_result_id' => $alert->exam_result_id]);

                            return true;
                        });
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            }
        } finally {
            $currentTenant->set($previous);
        }
        $this->info("Avisos enviados: {$sent}.");

        return self::SUCCESS;
    }
}
