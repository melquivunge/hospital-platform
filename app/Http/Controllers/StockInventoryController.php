<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\RoleAssignment;
use App\Models\StockInventory;
use App\Models\StockInventoryLine;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inventory of a unit's stock (step 4c.3b, ADR-0007). The unit's pharmacy opens it, counts lot by lot (a blind
 * count: the counter does not see the balance) and submits it; a unit manager who neither opened, counted nor
 * submitted it approves or refuses. Approving adjusts each lot by the counted minus its balance at the count, so
 * what moved after the count is not adjusted again; dispensing goes on during the count. No patient data.
 */
class StockInventoryController extends Controller
{
    public const STATUSES = ['counting' => 'Em contagem', 'submitted' => 'Aguardando aprovação', 'approved' => 'Aprovado', 'refused' => 'Recusado'];

    public function index(Request $request): Response
    {
        $pharmacy = self::pharmacyUnitIds($request->user());
        $units = Unit::query()->whereKey([...$pharmacy, ...self::managerUnitIds($request->user())])->orderBy('name')->get(['id', 'name']);
        abort_if($units->isEmpty(), 403);
        $inventories = StockInventory::query()->whereIn('unit_id', $units->pluck('id'))->orderByDesc('id')->simplePaginate(20)->withQueryString();

        return Inertia::render('stock/inventories/index', [
            'inventories' => $inventories->getCollection()->map(fn (StockInventory $inventory) => [
                'id' => $inventory->id,
                'unit' => $units->firstWhere('id', $inventory->unit_id)?->name,
                'status' => $inventory->status,
                'openedAt' => $inventory->opened_at->format('d/m/Y H:i'),
            ])->values(),
            'pages' => ['newer' => $inventories->previousPageUrl(), 'older' => $inventories->nextPageUrl()],
            'statuses' => self::STATUSES,
            // Where this person may open one, and the medications with balance there.
            'openUnits' => $units->whereIn('id', $pharmacy)->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'medications' => Medication::query()->whereIn('id', StockLot::query()->where('unit_id', $unit->id)->where('balance', '>', 0)->select('medication_id'))
                    ->orderBy('ingredient_key')->orderBy('form')->orderBy('strength')->get()
                    ->map(fn (Medication $medication) => ['id' => $medication->id, 'name' => StockController::label($medication)])->values(),
            ])->values(),
        ]);
    }

    /**
     * Opens the inventory of a unit: one line per lot with balance (all medications, or the chosen ones).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('open', [
            'unit_id' => ['required', 'integer', Rule::in(self::pharmacyUnitIds($request->user()))],
            'medication_ids' => ['nullable', 'array', 'max:500'],
            'medication_ids.*' => ['integer'],
        ], [], ['unit_id' => 'unidade', 'medication_ids' => 'medicamentos']);
        $lots = StockLot::query()->where('unit_id', $data['unit_id'])->where('balance', '>', 0)
            ->when(($data['medication_ids'] ?? []) !== [], fn ($query) => $query->whereIn('medication_id', $data['medication_ids']))
            ->orderBy('id')->get(['id', 'medication_id']);
        if ($lots->isEmpty()) {
            throw ValidationException::withMessages(['medication_ids' => 'Nenhum lote com saldo para contar. Para um lote sem cadastro, use a entrada de lote.'])->errorBag('open');
        }
        try {
            $inventory = DB::transaction(function () use ($request, $data, $lots) {
                $inventory = StockInventory::query()->create(['unit_id' => $data['unit_id'], 'opened_by_user_id' => $request->user()->id, 'opened_at' => now()]);
                $inventory->lines()->createMany($lots->map(fn (StockLot $lot) => ['tenant_id' => $inventory->tenant_id, 'medication_id' => $lot->medication_id, 'stock_lot_id' => $lot->id])->all());
                AuditEvent::record('stock.inventory_opened', $inventory->tenant_id, $inventory, ['unit_id' => $inventory->unit_id, 'lines' => $lots->count()]);

                return $inventory;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['unit_id' => 'Já há um inventário em andamento nesta unidade.'])->errorBag('open');
        }

        return to_route('stock.inventories.show', $inventory);
    }

    public function show(Request $request, StockInventory $inventory): Response
    {
        $counter = in_array($inventory->unit_id, self::pharmacyUnitIds($request->user()), true);
        $manager = in_array($inventory->unit_id, self::managerUnitIds($request->user()), true);
        abort_unless($counter || $manager, 403);
        $lines = $inventory->lines()->orderBy('id')->get();
        $lots = StockLot::query()->whereKey($lines->pluck('stock_lot_id')->filter()->all())->get()->keyBy('id');
        $medications = Medication::query()->whereKey($lines->pluck('medication_id')->unique()->all())->get()->keyBy('id');
        $names = User::query()->whereKey(array_filter([$inventory->opened_by_user_id, $inventory->submitted_by_user_id, $inventory->decided_by_user_id]))->pluck('name', 'id');
        $timezone = Unit::query()->whereKey($inventory->unit_id)->value('timezone') ?? 'UTC';
        // Blind count: nobody sees the balance while counting; then the manager does, and everyone once decided.
        $reveal = in_array($inventory->status, ['approved', 'refused'], true) || ($manager && $inventory->status === 'submitted');

        return Inertia::render('stock/inventories/show', [
            'inventory' => [
                'id' => $inventory->id,
                'unit' => Unit::query()->whereKey($inventory->unit_id)->value('name'),
                'status' => $inventory->status,
                'openedBy' => $names->get($inventory->opened_by_user_id),
                'openedAt' => $inventory->opened_at->timezone($timezone)->format('d/m/Y H:i'),
                'submittedBy' => $names->get((int) $inventory->submitted_by_user_id),
                'decidedBy' => $names->get((int) $inventory->decided_by_user_id),
                'decidedAt' => $inventory->decided_at?->timezone($timezone)->format('d/m/Y H:i'),
                'refusalReason' => $inventory->refusal_reason,
            ],
            'statuses' => self::STATUSES,
            'lines' => $lines->map(function (StockInventoryLine $line) use ($lots, $medications, $reveal, $timezone) {
                $lot = $line->stock_lot_id === null ? null : $lots->get($line->stock_lot_id);

                return [
                    'id' => $line->id,
                    'medication' => StockController::label($medications[$line->medication_id]),
                    'stockUnit' => $medications[$line->medication_id]->stock_unit,
                    'lot' => $lot === null ? $line->lot_code : $lot->lot_code,
                    'expiresOn' => ($lot === null ? $line->expires_on : $lot->expires_on)?->format('d/m/Y'),
                    'found' => $line->stock_lot_id === null,
                    'counted' => $line->counted,
                    'countedAt' => $line->counted_at?->timezone($timezone)->format('d/m/Y H:i'),
                    'expected' => $reveal ? $line->expected : null,
                    'difference' => $reveal && $line->counted !== null ? $line->counted - (int) $line->expected : null,
                ];
            })->values(),
            'canCount' => $counter && $inventory->status === 'counting',
            'canDecide' => $manager && $inventory->status === 'submitted' && ! in_array($request->user()->id, self::participants($inventory), true),
            'catalog' => $counter && $inventory->status === 'counting' ? Medication::query()->where('active', true)->orderBy('ingredient_key')->orderBy('form')->orderBy('strength')->get()
                ->map(fn (Medication $medication) => ['id' => $medication->id, 'name' => StockController::label($medication), 'stockUnit' => $medication->stock_unit])->values() : [],
        ]);
    }

    /**
     * Counts one line (again, while counting): keeps the lot's balance read with the lot locked now.
     */
    public function count(Request $request, StockInventory $inventory, StockInventoryLine $line): RedirectResponse
    {
        abort_unless($line->stock_inventory_id === $inventory->id, 404);
        abort_unless(in_array($inventory->unit_id, self::pharmacyUnitIds($request->user()), true), 403);
        $bag = "count{$line->id}";
        $data = $request->validateWithBag($bag, ['counted' => ['required', 'integer', 'min:0', 'max:100000']], [], ['counted' => 'quantidade contada']);
        DB::transaction(function () use ($request, $inventory, $line, $data, $bag) {
            self::participate(self::lockCounting($request, $inventory, $bag), $request->user());
            $expected = $line->stock_lot_id === null ? 0 : StockLot::query()->lockForUpdate()->findOrFail($line->stock_lot_id)->balance;
            $line->update(['counted' => $data['counted'], 'expected' => $expected, 'counted_by_user_id' => $request->user()->id, 'counted_at' => now()]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contagem salva.']);

        return back();
    }

    /**
     * A lot found on the shelf: a recorded lot of the unit not in the list joins it; otherwise a line with its code
     * and expiry (an expired one too: it is there, and is then discarded) whose lot is created on approval.
     */
    public function found(Request $request, StockInventory $inventory): RedirectResponse
    {
        abort_unless(in_array($inventory->unit_id, self::pharmacyUnitIds($request->user()), true), 403);
        $data = $request->validateWithBag('found', [
            'medication_id' => ['required', 'integer'],
            'lot_code' => ['required', 'string', 'max:50', 'regex:/^\s*[A-Za-z0-9][A-Za-z0-9.\/\- ]*$/'],
            'expires_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->addYears(10)->toDateString()],
            'counted' => ['required', 'integer', 'min:1', 'max:100000'],
        ], ['lot_code.regex' => 'O lote aceita letras sem acento, números, ponto, barra e hífen.'], [
            'medication_id' => 'medicamento', 'lot_code' => 'lote', 'expires_on' => 'validade', 'counted' => 'quantidade contada',
        ]);
        $medication = Medication::query()->where('active', true)->whereKey((int) $data['medication_id'])->first()
            ?? throw ValidationException::withMessages(['medication_id' => 'Escolha um medicamento ativo do catálogo.'])->errorBag('found');
        $code = StockLot::code($data['lot_code']);

        try {
            DB::transaction(function () use ($request, $inventory, $data, $medication, $code) {
                self::participate(self::lockCounting($request, $inventory, 'found'), $request->user());
                $lot = StockLot::query()->where(['unit_id' => $inventory->unit_id, 'medication_id' => $medication->id, 'lot_code' => $code, 'expires_on' => $data['expires_on']])->lockForUpdate()->first();
                // Already listed as found (its lot recorded since, or not): the same lot twice is refused.
                if ($inventory->lines()->where(['medication_id' => $medication->id, 'lot_code' => $code, 'expires_on' => $data['expires_on']])->exists()) {
                    throw ValidationException::withMessages(['lot_code' => 'Este lote já está na lista. Corrija a contagem dele.'])->errorBag('found');
                }
                $inventory->lines()->create([
                    'tenant_id' => $inventory->tenant_id, 'medication_id' => $medication->id,
                    ...($lot === null ? ['lot_code' => $code, 'expires_on' => $data['expires_on']] : ['stock_lot_id' => $lot->id]),
                    'counted' => $data['counted'], 'expected' => $lot === null ? 0 : $lot->balance, 'counted_by_user_id' => $request->user()->id, 'counted_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['lot_code' => 'Este lote já está na lista. Corrija a contagem dele.'])->errorBag('found');
        }

        return back();
    }

    public function submit(Request $request, StockInventory $inventory): RedirectResponse
    {
        DB::transaction(function () use ($request, $inventory) {
            $inventory = self::lockCounting($request, $inventory, 'submit');
            self::participate($inventory, $request->user());
            if ($inventory->lines()->whereNull('counted')->exists()) {
                throw ValidationException::withMessages(['lines' => 'Conte todos os lotes antes de enviar. Lote não encontrado conta 0.'])->errorBag('submit');
            }
            $inventory->update(['status' => 'submitted', 'submitted_by_user_id' => $request->user()->id, 'submitted_at' => now()]);
            AuditEvent::record('stock.inventory_submitted', $inventory->tenant_id, $inventory, ['unit_id' => $inventory->unit_id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Inventário enviado para aprovação do gestor da unidade.']);

        return back();
    }

    /**
     * The unit manager's approval: one adjustment per lot with a difference, on the lots locked in id order. A lot
     * whose balance now cannot take a negative adjustment (it went out after the count) refuses the approval.
     */
    public function approve(Request $request, StockInventory $inventory): RedirectResponse
    {
        $adjusted = DB::transaction(function () use ($request, $inventory) {
            $inventory = self::lockSubmitted($request, $inventory, 'decide');
            $lines = $inventory->lines()->orderBy('id')->get();
            $changes = $lines->filter(fn (StockInventoryLine $line) => $line->stock_lot_id !== null && $line->counted !== $line->expected);
            $lots = StockLot::query()->whereKey($changes->pluck('stock_lot_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $short = $changes->filter(fn (StockInventoryLine $line) => $lots[$line->stock_lot_id]->balance + $line->counted - $line->expected < 0)
                ->map(fn (StockInventoryLine $line) => $lots[$line->stock_lot_id]->lot_code)->values()->all();
            if ($short !== []) {
                throw ValidationException::withMessages(['decision' => 'Saiu mais do lote '.implode(', ', $short).' depois da contagem do que o ajuste comporta. Recuse e peça uma nova contagem.'])->errorBag('decide');
            }
            $adjusted = [];
            foreach ($changes as $line) {
                $adjusted[$line->stock_lot_id] = self::adjust($inventory, (int) $line->stock_lot_id, (int) $line->counted - (int) $line->expected, $request->user());
            }
            // Found lots without a record: the lot is created now and takes in what was counted. A lot recorded in the
            // meantime (an entry after the count) would be counted twice: a new count is asked for.
            foreach ($lines->whereNull('stock_lot_id')->where('counted', '>', 0) as $line) {
                $lot = StockLot::query()->createOrFirst(
                    ['unit_id' => $inventory->unit_id, 'medication_id' => $line->medication_id, 'lot_code' => $line->lot_code, 'expires_on' => $line->expires_on?->toDateString()],
                    ['created_by_user_id' => $line->counted_by_user_id, 'created_at' => now()],
                );
                if (! $lot->wasRecentlyCreated) {
                    throw ValidationException::withMessages(['decision' => "O lote {$lot->lot_code} foi cadastrado depois de encontrado na contagem. Recuse e peça uma nova contagem."])->errorBag('decide');
                }
                $adjusted[$lot->id] = self::adjust($inventory, $lot->id, (int) $line->counted, $request->user());
            }
            $adjusted = array_filter($adjusted);
            $inventory->update(['status' => 'approved', 'decided_by_user_id' => $request->user()->id, 'decided_at' => now()]);
            AuditEvent::record('stock.inventory_approved', $inventory->tenant_id, $inventory, ['unit_id' => $inventory->unit_id, 'adjustments' => $adjusted]);

            return $adjusted;
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $adjusted === [] ? 'Inventário aprovado. Nenhum lote precisou de ajuste.' : 'Inventário aprovado. '.count($adjusted).' lote(s) ajustado(s).']);

        return back();
    }

    public function refuse(Request $request, StockInventory $inventory): RedirectResponse
    {
        abort_unless(in_array($inventory->unit_id, self::managerUnitIds($request->user()), true), 403);
        $data = $request->validateWithBag('decide', ['refusal_reason' => ['required', 'string', 'max:200']], [], ['refusal_reason' => 'motivo']);
        DB::transaction(function () use ($request, $inventory, $data) {
            $inventory = self::lockSubmitted($request, $inventory, 'decide');
            $inventory->update(['status' => 'refused', 'refusal_reason' => trim($data['refusal_reason']), 'decided_by_user_id' => $request->user()->id, 'decided_at' => now()]);
            // The reason stays on the inventory, not in the audit.
            AuditEvent::record('stock.inventory_refused', $inventory->tenant_id, $inventory, ['unit_id' => $inventory->unit_id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Inventário recusado. A farmácia pode abrir uma nova contagem.']);

        return back();
    }

    private static function adjust(StockInventory $inventory, int $lotId, int $quantity, User $user): int
    {
        if ($quantity !== 0) {
            StockMovement::query()->create(['stock_lot_id' => $lotId, 'type' => 'adjustment', 'quantity' => $quantity, 'stock_inventory_id' => $inventory->id, 'user_id' => $user->id, 'created_at' => now()]);
        }

        return $quantity;
    }

    /**
     * Under the transaction: the inventory locked, still counting, and the caller the unit's pharmacy.
     */
    private static function lockCounting(Request $request, StockInventory $inventory, string $bag): StockInventory
    {
        abort_unless(in_array($inventory->unit_id, self::pharmacyUnitIds($request->user()), true), 403);
        $locked = StockInventory::query()->lockForUpdate()->findOrFail($inventory->id);
        if ($locked->status !== 'counting') {
            throw ValidationException::withMessages(['status' => 'Este inventário não está mais em contagem.'])->errorBag($bag);
        }

        return $locked;
    }

    /**
     * Under the transaction: the inventory locked and submitted, and the caller a manager of its unit who took no
     * part in it (two people: who counts never approves).
     */
    private static function lockSubmitted(Request $request, StockInventory $inventory, string $bag): StockInventory
    {
        abort_unless(in_array($inventory->unit_id, self::managerUnitIds($request->user()), true), 403);
        $locked = StockInventory::query()->lockForUpdate()->findOrFail($inventory->id);
        if ($locked->status !== 'submitted') {
            throw ValidationException::withMessages(['decision' => 'Este inventário não está aguardando aprovação.'])->errorBag($bag);
        }
        if (in_array($request->user()->id, self::participants($locked), true)) {
            throw ValidationException::withMessages(['decision' => 'Quem abriu, contou ou enviou o inventário não decide sobre ele.'])->errorBag($bag);
        }

        return $locked;
    }

    /**
     * Under the transaction, on the locked inventory: this person took part in it (counted or submitted).
     */
    private static function participate(StockInventory $inventory, User $user): void
    {
        if (! in_array($user->id, $inventory->participant_user_ids, true)) {
            $inventory->update(['participant_user_ids' => [...$inventory->participant_user_ids, $user->id]]);
        }
    }

    /**
     * @return list<int> who opened, counted or submitted the inventory, even if a count was redone by someone else
     */
    private static function participants(StockInventory $inventory): array
    {
        return array_values(array_unique([$inventory->opened_by_user_id, ...$inventory->participant_user_ids]));
    }

    /**
     * @return list<int>
     */
    private static function pharmacyUnitIds(User $user): array
    {
        return PharmacyController::unitIds(UnitPolicy::currentMembership($user));
    }

    /**
     * @return list<int>
     */
    public static function managerUnitIds(User $user): array
    {
        $membership = UnitPolicy::currentMembership($user);

        return $membership === null || $membership->revoked_at !== null ? [] : array_values(array_unique($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => $assignment->role === Role::UnitManager)
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)->all()));
    }
}
