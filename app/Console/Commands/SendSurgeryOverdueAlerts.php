<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Surgery;
use App\Models\Tenant;
use App\Models\Unit;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Step 5b.3c (ADR-0009): once per day of each unit (from 7 h in its time zone), the unit managers hear how many
 * surgeries are still scheduled more than 24 hours after the start (they keep the team's access to the record) and
 * how many requests were never scheduled 7 days after the wished date. Scheduled hourly, as exams:overdue. Counts
 * only: no patient data.
 */
class SendSurgeryOverdueAlerts extends Command
{
    protected $signature = 'surgeries:overdue';

    protected $description = 'Avisa o gestor de cada unidade sobre cirurgias agendadas vencidas e pedidos de cirurgia parados, uma vez por dia';

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
        $scheduled = Surgery::overdue()->where('unit_id', $unit->id)->count();
        $requested = Surgery::stalled()->where('unit_id', $unit->id)->count();
        if ($scheduled + $requested === 0) {
            return false;
        }
        $today = $now->toDateString();

        return DB::transaction(function () use ($unit, $today, $scheduled, $requested) {
            // The day's row first: a second run (or a concurrent one) of the same day sends nothing.
            if (DB::table('surgery_alert_days')->insertOrIgnore(['tenant_id' => $unit->tenant_id, 'unit_id' => $unit->id, 'alert_date' => $today, 'created_at' => now()]) === 0) {
                return false;
            }
            AuditEvent::record('surgery.overdue', $unit->tenant_id, $unit, ['unit_id' => $unit->id, 'date' => $today, 'scheduled' => $scheduled, 'requested' => $requested]);

            return true;
        });
    }
}
