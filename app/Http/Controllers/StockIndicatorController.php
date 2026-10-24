<?php

namespace App\Http\Controllers;

use App\Models\Medication;
use App\Models\StockLot;
use App\Models\StockMinimum;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The stock indicators (step 4c.4c, ADR-0007): per unit, medications below the minimum (valid lots), lots expiring
 * within 30 days, expired lots with balance and losses in the period by reason (discards and negative inventory
 * differences); the chosen unit in detail. The unit's pharmacy and managers see their units; the network admin
 * sees every unit, read-only. No patient data.
 */
class StockIndicatorController extends Controller
{
    public const LOSS_REASONS = ['expired' => 'Vencido', 'damage' => 'Avaria', 'loss' => 'Perda', 'other' => 'Outro', 'inventory' => 'Diferença de inventário'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $membership = UnitPolicy::currentMembership($user);
        // The units whose stock screen this person opens (pharmacy or manager); the network admin sees them all here.
        $stockUnitIds = array_values(array_unique([...PharmacyController::unitIds($membership), ...StockInventoryController::managerUnitIds($user)]));
        $units = Unit::query()->when(! ($membership?->hasRole(Role::NetworkAdmin) ?? false), fn ($query) => $query->whereKey($stockUnitIds))
            ->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        // ponytail: the period is read in the chosen unit's time zone for every unit; per-unit periods if a network spans zones.
        [$from, $to] = StockReportController::period($request, $unit->timezone ?? 'UTC');
        $ids = $units->pluck('id')->all();

        // ponytail: every lot with balance of the visible units in memory; aggregate in SQL if a network grows to many thousands.
        $lots = StockLot::query()->whereIn('unit_id', $ids)->where('balance', '>', 0)->orderBy('expires_on')->orderBy('id')
            ->get(['id', 'unit_id', 'medication_id', 'lot_code', 'expires_on', 'balance'])->groupBy('unit_id');
        $minimums = StockMinimum::query()->whereIn('unit_id', $ids)->get()->groupBy('unit_id');
        $losses = StockMovement::query()->join('stock_lots', 'stock_lots.id', '=', 'stock_movements.stock_lot_id')
            ->whereIn('stock_lots.unit_id', $ids)
            ->where('stock_movements.created_at', '>=', $from->utc())->where('stock_movements.created_at', '<', $to->utc())
            ->where(fn ($query) => $query->where('stock_movements.type', 'discard')->orWhere(fn ($query) => $query->where('stock_movements.type', 'adjustment')->where('stock_movements.quantity', '<', 0)))
            ->toBase()
            ->select(DB::raw("stock_lots.unit_id, stock_lots.medication_id, CASE WHEN stock_movements.type = 'discard' THEN stock_movements.reason ELSE 'inventory' END AS reason, -sum(stock_movements.quantity) AS quantity, count(*) AS records"))
            ->groupByRaw("stock_lots.unit_id, stock_lots.medication_id, CASE WHEN stock_movements.type = 'discard' THEN stock_movements.reason ELSE 'inventory' END")
            ->get()->groupBy('unit_id');

        $figures = $units->map(function (Unit $each) use ($lots, $minimums, $losses) {
            $today = now($each->timezone ?? 'UTC')->toDateString();
            $limit = now($each->timezone ?? 'UTC')->addDays(StockLot::EXPIRING_DAYS)->toDateString();
            $own = $lots->get($each->id, collect());
            $valid = $own->filter(fn (StockLot $lot) => $lot->expires_on->toDateString() >= $today);
            $validByMedication = $valid->groupBy('medication_id')->map(fn (Collection $same) => (int) $same->sum('balance'));

            return [
                'unit' => $each,
                'below' => $minimums->get($each->id, collect())->filter(fn (StockMinimum $minimum) => $validByMedication->get($minimum->medication_id, 0) < $minimum->minimum)->values(),
                'expiring' => $valid->filter(fn (StockLot $lot) => $lot->expires_on->toDateString() <= $limit)->values(),
                'expired' => $own->filter(fn (StockLot $lot) => $lot->expires_on->toDateString() < $today)->values(),
                'validByMedication' => $validByMedication,
                'losses' => $losses->get($each->id, collect()),
            ];
        });
        $chosen = $figures->firstWhere('unit.id', $unit->id);
        $medications = Medication::query()->whereKey([
            ...$chosen['below']->pluck('medication_id')->all(), ...$chosen['expiring']->pluck('medication_id')->all(),
            ...$chosen['expired']->pluck('medication_id')->all(), ...$chosen['losses']->pluck('medication_id')->all(),
        ])->get()->keyBy('id');
        $lot = fn (StockLot $lot) => ['id' => $lot->id, 'medication' => StockController::label($medications[$lot->medication_id]), 'lot' => $lot->lot_code,
            'expiresOn' => $lot->expires_on->format('d/m/Y'), 'balance' => $lot->balance, 'stockUnit' => $medications[$lot->medication_id]->stock_unit];

        return Inertia::render('stock/indicators', [
            'unitId' => $unit->id,
            'stockUnitIds' => $stockUnitIds,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString()],
            'reasons' => self::LOSS_REASONS,
            'units' => $figures->map(fn (array $row) => [
                'id' => $row['unit']->id,
                'name' => $row['unit']->name,
                'below' => $row['below']->count(),
                'expiring' => $row['expiring']->count(),
                'expired' => $row['expired']->count(),
                // Loss records by reason (units of different medications are not added up).
                'losses' => $row['losses']->groupBy('reason')->map(fn (Collection $rows) => (int) $rows->sum('records'))->sortKeys()->all(),
            ])->values(),
            'detail' => [
                'below' => $chosen['below']->map(fn (StockMinimum $minimum) => [
                    'id' => $minimum->medication_id,
                    'medication' => StockController::label($medications[$minimum->medication_id]),
                    'stockUnit' => $medications[$minimum->medication_id]->stock_unit,
                    'valid' => $chosen['validByMedication']->get($minimum->medication_id, 0),
                    'minimum' => $minimum->minimum,
                ])->sortBy('medication')->values(),
                'expiring' => $chosen['expiring']->map($lot)->values(),
                'expired' => $chosen['expired']->map($lot)->values(),
                'losses' => $chosen['losses']->map(fn (\stdClass $row) => [
                    'medication' => StockController::label($medications[(int) $row->medication_id]),
                    'stockUnit' => $medications[(int) $row->medication_id]->stock_unit,
                    'reason' => (string) $row->reason,
                    'quantity' => (int) $row->quantity,
                ])->sortBy([['medication', 'asc'], ['reason', 'asc']])->values(),
            ],
        ]);
    }
}
