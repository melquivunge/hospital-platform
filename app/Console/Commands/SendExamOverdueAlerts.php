<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\ExamOrder;
use App\Models\Tenant;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Step 5.3b (ADR-0008): once per day of each unit (from 7 h in its time zone), the unit managers hear how many
 * orders are open with an item not collected for more than 7 days; a forgotten order keeps the exam team's access
 * to the record. Scheduled hourly: a run that missed 7 h catches up, and a second run the same day sends nothing.
 * Counts only: no patient data.
 */
class SendExamOverdueAlerts extends Command
{
    protected $signature = 'exams:overdue';

    protected $description = 'Avisa o gestor de cada unidade sobre pedidos de exame sem coleta há mais de 7 dias, uma vez por dia';

    private const FROM_HOUR = 7;

    public function handle(CurrentTenant $currentTenant): int
    {
        $previous = $currentTenant->get();
        $sent = 0;
        try {
            foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
                $currentTenant->set($tenant);
                foreach (Unit::query()->orderBy('id')->get(['id', 'tenant_id', 'timezone']) as $unit) {
                    try {
                        $sent += (int) $this->alertUnit($unit);
                    } catch (Throwable $exception) {
                        // One broken unit is reported and skipped; the others still hear today.
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

    private function alertUnit(Unit $unit): bool
    {
        $now = now($unit->timezone ?? 'UTC');
        if ($now->hour < self::FROM_HOUR) {
            return false;
        }
        $count = ExamOrder::overdue()->where('unit_id', $unit->id)->count();
        if ($count === 0) {
            return false;
        }
        $today = $now->toDateString();

        return DB::transaction(function () use ($unit, $today, $count) {
            // The day's row first: a second run (or a concurrent one) of the same day sends nothing.
            if (DB::table('exam_alert_days')->insertOrIgnore(['tenant_id' => $unit->tenant_id, 'unit_id' => $unit->id, 'alert_date' => $today, 'created_at' => now()]) === 0) {
                return false;
            }
            AuditEvent::record('exam.overdue_orders', $unit->tenant_id, $unit, ['unit_id' => $unit->id, 'date' => $today, 'orders' => $count]);

            return true;
        });
    }
}
