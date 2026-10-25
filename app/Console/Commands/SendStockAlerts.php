<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\StockLot;
use App\Models\StockMinimum;
use App\Models\Tenant;
use App\Models\Unit;
use App\Stock\StockItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Step 4c.4a (ADR-0007): once per day of each unit (from 7 h in its time zone), the pharmacy and the unit manager
 * hear of lots expiring within 30 days, expired lots still with balance and medications or materials below the
 * minimum (valid lots only). Scheduled hourly: a run that missed 7 h catches up, and a second run the same day sends nothing.
 * Counts only: no patient data.
 */
class SendStockAlerts extends Command
{
    protected $signature = 'stock:alerts';

    protected $description = 'Avisa a farmácia e o gestor de cada unidade sobre validade e estoque mínimo, uma vez por dia';

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
        $today = $now->toDateString();
        $lots = StockLot::query()->where('unit_id', $unit->id)->where('balance', '>', 0)->get(['medication_id', 'material_id', 'expires_on', 'balance']);
        $valid = $lots->filter(fn (StockLot $lot) => $lot->expires_on->toDateString() >= $today);
        $validByItem = $valid->groupBy(fn (StockLot $lot) => StockItem::key($lot))->map->sum('balance');
        $counts = [
            'expiring' => $valid->filter(fn (StockLot $lot) => $lot->expires_on->toDateString() <= $now->copy()->addDays(StockLot::EXPIRING_DAYS)->toDateString())->count(),
            'expired' => $lots->count() - $valid->count(),
            'below_minimum' => StockMinimum::query()->where('unit_id', $unit->id)->get()
                ->filter(fn (StockMinimum $minimum) => $validByItem->get(StockItem::key($minimum), 0) < $minimum->minimum)->count(),
        ];
        if (array_sum($counts) === 0) {
            return false;
        }

        return DB::transaction(function () use ($unit, $today, $counts) {
            // The day's row first: a second run (or a concurrent one) of the same day sends nothing.
            if (DB::table('stock_alert_days')->insertOrIgnore(['tenant_id' => $unit->tenant_id, 'unit_id' => $unit->id, 'alert_date' => $today, 'created_at' => now()]) === 0) {
                return false;
            }
            AuditEvent::record('stock.daily_alert', $unit->tenant_id, $unit, ['unit_id' => $unit->id, 'date' => $today, ...$counts]);

            return true;
        });
    }
}
