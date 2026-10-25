<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use App\Stock\StockItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The stock movements of a unit by period and medication or material (step 4c.4b, ADR-0007; materials since step
 * 5b.6b), controlled medications first, to support the manual bookkeeping; a CSV of the same rows. For the unit's pharmacy and managers. No patient
 * data: a dispensing or a surgery shows as its type only, never the id that leads to the patient.
 */
class StockReportController extends Controller
{
    private const MAX_DAYS = 366;

    // ponytail: built in memory; stream with lazyById if units need larger periods.
    private const CSV_MAX_ROWS = 20000;

    public const TYPES = ['entry' => 'Entrada', 'dispensing' => 'Dispensação', 'return' => 'Devolução', 'discard' => 'Descarte', 'adjustment' => 'Ajuste de inventário', 'surgery' => 'Consumo em cirurgia', 'surgery_return' => 'Estorno de cirurgia'];

    private const DISCARD_REASONS = ['expired' => 'Vencido', 'damage' => 'Avaria', 'loss' => 'Perda', 'other' => 'Outro'];

    public function index(Request $request): Response
    {
        [$units, $unit, $from, $to, $item, $controlled] = $this->scope($request);
        $movements = self::movements($unit, $from, $to, $item, $controlled)->orderByDesc('stock_movements.id')->paginate(50)->withQueryString();
        $totals = self::movements($unit, $from, $to, $item, $controlled)->toBase()
            ->select(DB::raw('stock_lots.medication_id, stock_lots.material_id, stock_movements.type, sum(stock_movements.quantity) as total'))
            ->groupBy('stock_lots.medication_id', 'stock_lots.material_id', 'stock_movements.type')->get();
        $items = StockItem::describe($totals->concat($movements->getCollection()));

        return Inertia::render('stock/movements', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(), 'item' => $item === null ? null : StockItem::key((object) $item), 'controlled' => $controlled],
            'options' => StockItem::describe(StockLot::query()->where('unit_id', $unit->id)->distinct()->get(['medication_id', 'material_id']))
                ->sortBy([['material', 'asc'], ['name', 'asc']])->map(fn (array $option, string $key) => ['key' => $key, 'name' => $option['name'], 'controlled' => $option['controlled']])->values(),
            // Controlled first, then by name.
            'summary' => $totals->groupBy(fn (\stdClass $row) => StockItem::key($row))->map(function (Collection $byType, string $key) use ($items) {
                return [
                    'key' => $key,
                    'name' => $items[$key]['name'],
                    'stockUnit' => $items[$key]['stockUnit'],
                    'controlled' => $items[$key]['controlled'],
                    ...collect(self::TYPES)->mapWithKeys(fn (string $label, string $type) => [$type => (int) ($byType->firstWhere('type', $type)->total ?? 0)])->all(),
                ];
            })->sortBy([['controlled', 'desc'], ['name', 'asc']])->values(),
            'movements' => self::rows($movements->getCollection(), $items, $unit->timezone ?? 'UTC'),
            'pages' => ['newer' => $movements->previousPageUrl(), 'older' => $movements->nextPageUrl(), 'total' => $movements->total()],
        ]);
    }

    public function csv(Request $request): SymfonyResponse
    {
        [, $unit, $from, $to, $item, $controlled] = $this->scope($request);
        $query = self::movements($unit, $from, $to, $item, $controlled);
        abort_if((clone $query)->count() > self::CSV_MAX_ROWS, 422, 'Período grande demais para a planilha. Escolha um período menor.');
        $movements = $query->orderBy('stock_movements.id')->get();
        $rows = array_map(fn (array $row) => [$row['at'], $row['medication'], $row['controlled'] ? 'sim' : 'não', $row['lot'], $row['expiresOn'], $row['type'], (string) $row['in'], (string) $row['out'], $row['reason'], $row['user']],
            self::rows($movements, StockItem::describe($movements), $unit->timezone ?? 'UTC'));
        $last = $to->subDay();
        AuditEvent::record('stock.movements_exported', $unit->tenant_id, $unit, [
            'unit_id' => $unit->id, 'from' => $from->toDateString(), 'to' => $last->toDateString(), 'medication_id' => $item['medication_id'] ?? null, ...(isset($item['material_id']) ? ['material_id' => $item['material_id']] : []), 'controlled' => $controlled, 'rows' => count($rows),
        ]);

        // The unit in the file name: a CSV asked for another unit falls back to the person's first one (L2, 4c.5).
        return ReportController::csv('movimentos-estoque-'.Str::slug($unit->name)."-{$from->toDateString()}-a-{$last->toDateString()}.csv",
            ['Data e hora', 'Medicamento ou material', 'Controlado', 'Lote', 'Validade', 'Tipo', 'Entrada', 'Saída', 'Motivo', 'Responsável'], $rows);
    }

    /**
     * @param  array{medication_id: int|null, material_id: int|null}|null  $item
     * @return Builder<StockMovement>
     */
    private static function movements(Unit $unit, CarbonImmutable $from, CarbonImmutable $to, ?array $item, bool $controlled): Builder
    {
        return StockMovement::query()->join('stock_lots', 'stock_lots.id', '=', 'stock_movements.stock_lot_id')
            ->where('stock_lots.unit_id', $unit->id)
            // The unit's days, bound in UTC (a zoned date would be bound as its wall-clock time).
            ->where('stock_movements.created_at', '>=', $from->utc())->where('stock_movements.created_at', '<', $to->utc())
            ->when($item !== null, fn (Builder $query) => $item['medication_id'] !== null ? $query->where('stock_lots.medication_id', $item['medication_id']) : $query->where('stock_lots.material_id', $item['material_id']))
            ->when($controlled, fn (Builder $query) => $query->whereIn('stock_lots.medication_id', Medication::query()->where('controlled', true)->select('id')))
            ->select(['stock_movements.id', 'stock_movements.type', 'stock_movements.quantity', 'stock_movements.reason', 'stock_movements.note', 'stock_movements.stock_inventory_id',
                'stock_movements.user_id', 'stock_movements.created_at', 'stock_lots.lot_code', 'stock_lots.expires_on', 'stock_lots.medication_id', 'stock_lots.material_id']);
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @param  Collection<string, array{name: string, stockUnit: string, controlled: bool, material: bool}>  $items
     * @return list<array{id: int, at: string, medication: string, controlled: bool, lot: string, expiresOn: string, type: string, in: int|string, out: int|string, reason: string, user: string}>
     */
    private static function rows(Collection $movements, Collection $items, string $timezone): array
    {
        $users = User::query()->whereKey($movements->pluck('user_id')->unique()->all())->pluck('name', 'id');

        return array_values($movements->map(function (StockMovement $movement) use ($items, $users, $timezone) {
            $item = $items[StockItem::key($movement)];

            return [
                'id' => $movement->id,
                'at' => $movement->created_at->timezone($timezone)->format('d/m/Y H:i'),
                // The medication's or the material's name.
                'medication' => $item['name'],
                'controlled' => $item['controlled'],
                'lot' => (string) $movement->getAttribute('lot_code'),
                'expiresOn' => CarbonImmutable::parse((string) $movement->getAttribute('expires_on'))->format('d/m/Y'),
                'type' => self::TYPES[$movement->type] ?? $movement->type,
                'in' => $movement->quantity > 0 ? $movement->quantity : '',
                'out' => $movement->quantity < 0 ? -$movement->quantity : '',
                'reason' => match ($movement->type) {
                    'discard' => (self::DISCARD_REASONS[(string) $movement->reason] ?? (string) $movement->reason).($movement->note === null ? '' : ": {$movement->note}"),
                    'adjustment' => "Inventário {$movement->stock_inventory_id}",
                    default => '',
                },
                'user' => (string) $users->get($movement->user_id),
            ];
        })->all());
    }

    /**
     * The period asked for (from/to, days in the time zone, the end day included) as [start, end): an invalid or
     * too long one falls back to the last 30 days. Also the stock indicators (step 4c.4c).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function period(Request $request, string $timezone): array
    {
        $date = function (string $key) use ($request, $timezone): ?CarbonImmutable {
            $value = $request->string($key)->value();

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? (CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) ?: null) : null;
        };
        $from = $date('from');
        $to = $date('to')?->addDay();
        if ($from === null || $to === null || $to <= $from || $from->addDays(self::MAX_DAYS) < $to) {
            $to = CarbonImmutable::now($timezone)->startOfDay()->addDay();
            $from = $to->subDays(30);
        }

        return [$from, $to];
    }

    /**
     * The person's units (pharmacy or manager), the chosen one, and the period in the unit's time zone: an invalid
     * or too long period falls back to the last 30 days.
     *
     * @return array{Collection<int, Unit>, Unit, CarbonImmutable, CarbonImmutable, array{medication_id: int|null, material_id: int|null}|null, bool}
     */
    private function scope(Request $request): array
    {
        $user = $request->user();
        $units = Unit::query()->whereKey([...PharmacyController::unitIds(UnitPolicy::currentMembership($user)), ...StockInventoryController::managerUnitIds($user)])
            ->orderBy('name')->get(['id', 'tenant_id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        [$from, $to] = self::period($request, $unit->timezone ?? 'UTC');
        // "medication:5" or "material:3"; a bare medication id (links before step 5b.6b) still works.
        $chosen = preg_match('/^(medication|material):([1-9][0-9]{0,15})$/', $request->string('item')->value(), $parts) === 1 ? [$parts[1], (int) $parts[2]]
            : ($request->integer('medication') > 0 ? ['medication', $request->integer('medication')] : null);
        $item = $chosen === null ? null : ['medication_id' => $chosen[0] === 'medication' ? $chosen[1] : null, 'material_id' => $chosen[0] === 'material' ? $chosen[1] : null];

        return [$units, $unit, $from, $to, $item, $request->boolean('controlled')];
    }
}
