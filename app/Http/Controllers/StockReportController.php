<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The stock movements of a unit by period and medication (step 4c.4b, ADR-0007), controlled medications first,
 * to support the manual bookkeeping; a CSV of the same rows. For the unit's pharmacy and managers. No patient
 * data: a dispensing shows as its type only, never the id that leads to the patient.
 */
class StockReportController extends Controller
{
    private const MAX_DAYS = 366;

    // ponytail: built in memory; stream with lazyById if units need larger periods.
    private const CSV_MAX_ROWS = 20000;

    public const TYPES = ['entry' => 'Entrada', 'dispensing' => 'Dispensação', 'return' => 'Devolução', 'discard' => 'Descarte', 'adjustment' => 'Ajuste de inventário'];

    private const DISCARD_REASONS = ['expired' => 'Vencido', 'damage' => 'Avaria', 'loss' => 'Perda', 'other' => 'Outro'];

    public function index(Request $request): Response
    {
        [$units, $unit, $from, $to, $medicationId, $controlled] = $this->scope($request);
        $movements = self::movements($unit, $from, $to, $medicationId, $controlled)->orderByDesc('stock_movements.id')->paginate(50)->withQueryString();
        $totals = self::movements($unit, $from, $to, $medicationId, $controlled)->toBase()
            ->select(DB::raw('stock_lots.medication_id, stock_movements.type, sum(stock_movements.quantity) as total'))
            ->groupBy('stock_lots.medication_id', 'stock_movements.type')->get();
        $medications = Medication::query()->whereKey([...$totals->pluck('medication_id')->all(), ...$movements->getCollection()->pluck('medication_id')->all()])->get()->keyBy('id');

        return Inertia::render('stock/movements', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(), 'medication' => $medicationId, 'controlled' => $controlled],
            'options' => Medication::query()->whereIn('id', StockLot::query()->where('unit_id', $unit->id)->select('medication_id'))
                ->orderBy('ingredient_key')->orderBy('form')->orderBy('strength')->get()
                ->map(fn (Medication $medication) => ['id' => $medication->id, 'name' => StockController::label($medication), 'controlled' => $medication->controlled])->values(),
            // Controlled first, then by name.
            'summary' => $totals->groupBy('medication_id')->map(function (Collection $byType, int|string $id) use ($medications) {
                $medication = $medications[(int) $id];

                return [
                    'id' => $medication->id,
                    'name' => StockController::label($medication),
                    'stockUnit' => $medication->stock_unit,
                    'controlled' => $medication->controlled,
                    ...collect(self::TYPES)->mapWithKeys(fn (string $label, string $type) => [$type => (int) ($byType->firstWhere('type', $type)->total ?? 0)])->all(),
                ];
            })->sortBy([['controlled', 'desc'], ['name', 'asc']])->values(),
            'movements' => self::rows($movements->getCollection(), $medications, $unit->timezone ?? 'UTC'),
            'pages' => ['newer' => $movements->previousPageUrl(), 'older' => $movements->nextPageUrl(), 'total' => $movements->total()],
        ]);
    }

    public function csv(Request $request): SymfonyResponse
    {
        [, $unit, $from, $to, $medicationId, $controlled] = $this->scope($request);
        $query = self::movements($unit, $from, $to, $medicationId, $controlled);
        abort_if((clone $query)->count() > self::CSV_MAX_ROWS, 422, 'Período grande demais para a planilha. Escolha um período menor.');
        $movements = $query->orderBy('stock_movements.id')->get();
        $medications = Medication::query()->whereKey($movements->pluck('medication_id')->unique()->all())->get()->keyBy('id');
        $rows = array_map(fn (array $row) => [$row['at'], $row['medication'], $row['controlled'] ? 'sim' : 'não', $row['lot'], $row['expiresOn'], $row['type'], (string) $row['in'], (string) $row['out'], $row['reason'], $row['user']],
            self::rows($movements, $medications, $unit->timezone ?? 'UTC'));
        $last = $to->subDay();
        AuditEvent::record('stock.movements_exported', $unit->tenant_id, $unit, [
            'unit_id' => $unit->id, 'from' => $from->toDateString(), 'to' => $last->toDateString(), 'medication_id' => $medicationId, 'controlled' => $controlled, 'rows' => count($rows),
        ]);

        return ReportController::csv("movimentos-estoque-{$from->toDateString()}-a-{$last->toDateString()}.csv",
            ['Data e hora', 'Medicamento', 'Controlado', 'Lote', 'Validade', 'Tipo', 'Entrada', 'Saída', 'Motivo', 'Responsável'], $rows);
    }

    /**
     * @return Builder<StockMovement>
     */
    private static function movements(Unit $unit, CarbonImmutable $from, CarbonImmutable $to, ?int $medicationId, bool $controlled): Builder
    {
        return StockMovement::query()->join('stock_lots', 'stock_lots.id', '=', 'stock_movements.stock_lot_id')
            ->where('stock_lots.unit_id', $unit->id)
            // The unit's days, bound in UTC (a zoned date would be bound as its wall-clock time).
            ->where('stock_movements.created_at', '>=', $from->utc())->where('stock_movements.created_at', '<', $to->utc())
            ->when($medicationId !== null, fn (Builder $query) => $query->where('stock_lots.medication_id', $medicationId))
            ->when($controlled, fn (Builder $query) => $query->whereIn('stock_lots.medication_id', Medication::query()->where('controlled', true)->select('id')))
            ->select(['stock_movements.id', 'stock_movements.type', 'stock_movements.quantity', 'stock_movements.reason', 'stock_movements.note', 'stock_movements.stock_inventory_id',
                'stock_movements.user_id', 'stock_movements.created_at', 'stock_lots.lot_code', 'stock_lots.expires_on', 'stock_lots.medication_id']);
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @param  Collection<int, Medication>  $medications
     * @return list<array{id: int, at: string, medication: string, controlled: bool, lot: string, expiresOn: string, type: string, in: int|string, out: int|string, reason: string, user: string}>
     */
    private static function rows(Collection $movements, Collection $medications, string $timezone): array
    {
        $users = User::query()->whereKey($movements->pluck('user_id')->unique()->all())->pluck('name', 'id');

        return array_values($movements->map(function (StockMovement $movement) use ($medications, $users, $timezone) {
            $medication = $medications[(int) $movement->getAttribute('medication_id')];

            return [
                'id' => $movement->id,
                'at' => $movement->created_at->timezone($timezone)->format('d/m/Y H:i'),
                'medication' => StockController::label($medication),
                'controlled' => $medication->controlled,
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
     * The person's units (pharmacy or manager), the chosen one, and the period in the unit's time zone: an invalid
     * or too long period falls back to the last 30 days.
     *
     * @return array{Collection<int, Unit>, Unit, CarbonImmutable, CarbonImmutable, int|null, bool}
     */
    private function scope(Request $request): array
    {
        $user = $request->user();
        $units = Unit::query()->whereKey([...PharmacyController::unitIds(UnitPolicy::currentMembership($user)), ...StockInventoryController::managerUnitIds($user)])
            ->orderBy('name')->get(['id', 'tenant_id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';
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
        $medication = $request->integer('medication');

        return [$units, $unit, $from, $to, $medication > 0 ? $medication : null, $request->boolean('controlled')];
    }
}
