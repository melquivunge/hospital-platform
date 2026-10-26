<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Surgery;
use App\Models\SurgeryDescription;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use App\Stock\StockItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The materials a surgery used (step 5b.6c, ADR-0009). The surgical center of the surgery's unit records them from
 * the unit's valid material lots while the surgery is in progress, and after its completion for the same days as the
 * addendum (it is often recorded afterwards); it corrects by giving back, never by changing a movement. The stock
 * movement keeps only the surgery's id. The surgery is locked first, then the lot, as everywhere else.
 */
class SurgeryMaterialController extends Controller
{
    /** The same consumption by the same person within this many seconds is taken as a repeated submission. */
    private const REPEAT_SECONDS = 10;

    public function store(Request $request, Surgery $surgery): RedirectResponse
    {
        abort_unless(self::canUse($request->user(), $surgery), 403);
        $data = $request->validateWithBag('material', [
            'stock_lot_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ], [], ['stock_lot_id' => 'lote', 'quantity' => 'quantidade']);

        DB::transaction(function () use ($request, $surgery, $data) {
            $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
            abort_unless(self::canUse($request->user(), $locked), 403);
            $lot = StockLot::query()->lockForUpdate()->find((int) $data['stock_lot_id']);
            $today = now(Unit::query()->whereKey($locked->unit_id)->value('timezone') ?? 'UTC')->toDateString();
            $refusal = match (true) {
                $lot === null || $lot->unit_id !== $locked->unit_id || $lot->material_id === null => ['stock_lot_id', 'Escolha um lote de material desta unidade.'],
                $lot->expires_on->toDateString() < $today => ['stock_lot_id', 'Lote vencido não é consumido.'],
                $data['quantity'] > $lot->balance => ['quantity', "O lote tem {$lot->balance} em saldo."],
                default => null,
            };
            if ($refusal !== null) {
                throw ValidationException::withMessages([$refusal[0] => $refusal[1]])->errorBag('material');
            }
            // The same consumption sent twice in a row (double click, a retry) is recorded once (security review 5b.7).
            if (StockMovement::query()->where(['surgery_id' => $locked->id, 'stock_lot_id' => $lot->id, 'type' => 'surgery', 'quantity' => -$data['quantity'], 'user_id' => $request->user()->id])
                ->where('created_at', '>=', now()->subSeconds(self::REPEAT_SECONDS))->exists()) {
                throw ValidationException::withMessages(['quantity' => 'Este consumo acabou de ser registrado. Se foram mais, registre de novo em instantes.'])->errorBag('material');
            }
            $movement = StockMovement::query()->create([
                'stock_lot_id' => $lot->id, 'type' => 'surgery', 'quantity' => -$data['quantity'], 'surgery_id' => $locked->id, 'user_id' => $request->user()->id, 'created_at' => now(),
            ]);
            AuditEvent::record('stock.surgery_consumption', $locked->tenant_id, $movement, [
                'unit_id' => $locked->unit_id, 'surgery_id' => $locked->id, 'material_id' => $lot->material_id, 'stock_lot_id' => $lot->id, 'quantity' => (int) $data['quantity'],
            ]);
        });
        // Step 6.2b: the stay's account receives it; a failure is reported, never undoes it.
        rescue(fn () => BillingAccount::ofStay($surgery->visit_id, $surgery->admission_id)?->generate());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Consumo registrado.']);

        return back();
    }

    /**
     * Gives back to a lot at most what the surgery took from it and did not give back yet. What comes back to a lot
     * that expired in the meantime is discarded at once, as the dispensing return (step 4c.2).
     */
    public function giveBack(Request $request, Surgery $surgery, StockLot $lot): RedirectResponse
    {
        abort_unless(self::canUse($request->user(), $surgery), 403);
        $bag = "materialReturn{$lot->id}";
        $data = $request->validateWithBag($bag, ['quantity' => ['required', 'integer', 'min:1', 'max:100000']], [], ['quantity' => 'quantidade']);

        DB::transaction(function () use ($request, $surgery, $lot, $data, $bag) {
            $locked = Surgery::query()->lockForUpdate()->findOrFail($surgery->id);
            abort_unless(self::canUse($request->user(), $locked), 403);
            $taken = self::taken($locked->id, $lot->id);
            if ($data['quantity'] > $taken) {
                throw ValidationException::withMessages(['quantity' => $taken === 0 ? 'Não há consumo deste lote a estornar nesta cirurgia.' : "O máximo a estornar deste lote é {$taken}."])->errorBag($bag);
            }
            $lot = StockLot::query()->lockForUpdate()->findOrFail($lot->id);
            $movement = StockMovement::query()->create([
                'stock_lot_id' => $lot->id, 'type' => 'surgery_return', 'quantity' => $data['quantity'], 'surgery_id' => $locked->id, 'user_id' => $request->user()->id, 'created_at' => now(),
            ]);
            $expired = $lot->expires_on->toDateString() < now(Unit::query()->whereKey($locked->unit_id)->value('timezone') ?? 'UTC')->toDateString();
            if ($expired) {
                StockMovement::query()->create([
                    'stock_lot_id' => $lot->id, 'type' => 'discard', 'quantity' => -$data['quantity'], 'reason' => 'expired', 'user_id' => $request->user()->id, 'created_at' => now(),
                ]);
            }
            AuditEvent::record('stock.surgery_return', $locked->tenant_id, $movement, [
                'unit_id' => $locked->unit_id, 'surgery_id' => $locked->id, 'material_id' => $lot->material_id, 'stock_lot_id' => $lot->id, 'quantity' => (int) $data['quantity'],
                ...($expired ? ['discarded' => (int) $data['quantity']] : []),
            ]);
        });
        // Step 6.2b: the stay's account receives it; a failure is reported, never undoes it.
        rescue(fn () => BillingAccount::ofStay($surgery->visit_id, $surgery->admission_id)?->generate());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Estorno registrado.']);

        return back();
    }

    /**
     * The surgical center of the surgery's unit, while it is in progress or for ADDENDUM_DAYS after its completion.
     */
    public static function canUse(User $user, Surgery $surgery): bool
    {
        return in_array($surgery->unit_id, SurgeryScheduleController::unitIds(UnitPolicy::currentMembership($user)), true)
            && ($surgery->status === 'in_progress'
                || ($surgery->status === 'completed' && $surgery->completed_at >= now()->subDays(SurgeryDescription::ADDENDUM_DAYS)));
    }

    /**
     * What the surgery used, lot by lot, and the material lots of its unit it may take from now.
     *
     * @return array{used: list<array<string, mixed>>, lots: list<array<string, mixed>>}
     */
    public static function present(Surgery $surgery, string $timezone, bool $canUse): array
    {
        $moves = StockMovement::query()->where('surgery_id', $surgery->id)->whereIn('type', ['surgery', 'surgery_return'])->get();
        $used = StockLot::query()->whereKey($moves->pluck('stock_lot_id')->unique()->all())->orderBy('expires_on')->orderBy('id')->get();
        $available = $canUse ? StockLot::query()->where('unit_id', $surgery->unit_id)->whereNotNull('material_id')->where('balance', '>', 0)
            ->where('expires_on', '>=', now($timezone)->toDateString())->orderBy('expires_on')->orderBy('id')->get() : collect();
        $items = StockItem::describe($used->concat($available));
        $lot = fn (StockLot $lot) => ['id' => $lot->id, 'name' => $items[StockItem::key($lot)]['name'], 'stockUnit' => $items[StockItem::key($lot)]['stockUnit'], 'code' => $lot->lot_code, 'expiresOn' => $lot->expires_on->format('d/m/Y')];

        return [
            'used' => array_values($used->map(fn (StockLot $each) => [
                ...$lot($each),
                'taken' => -(int) $moves->where('stock_lot_id', $each->id)->where('type', 'surgery')->sum('quantity'),
                'returned' => (int) $moves->where('stock_lot_id', $each->id)->where('type', 'surgery_return')->sum('quantity'),
            ])->all()),
            'lots' => array_values($available->map(fn (StockLot $each) => [...$lot($each), 'balance' => $each->balance])->all()),
        ];
    }

    /**
     * Under the transaction, with the surgery locked: what it took from the lot and did not give back.
     */
    private static function taken(int $surgeryId, int $lotId): int
    {
        return -(int) StockMovement::query()->where(['surgery_id' => $surgeryId, 'stock_lot_id' => $lotId])->whereIn('type', ['surgery', 'surgery_return'])->sum('quantity');
    }
}
