<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Medication;
use App\Models\StockLot;
use App\Models\StockMinimum;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pharmacy stock of each unit (step 4c.1a, ADR-0007): the unit's Pharmacy role records lot entries and
 * keeps the minimum of each medication; the balance of each lot comes from its movements. No patient data.
 */
class StockController extends Controller
{
    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(self::unitIds($request))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', (int) $request->query('unit')) ?? $units->first();
        $today = now($unit->timezone ?? 'UTC')->startOfDay();

        $lots = StockLot::query()->where('unit_id', $unit->id)->where('balance', '>', 0)->orderBy('expires_on')->orderBy('id')->get()->groupBy('medication_id');
        $minimums = StockMinimum::query()->where('unit_id', $unit->id)->pluck('minimum', 'medication_id');
        $medications = Medication::query()->whereKey([...$lots->keys(), ...$minimums->keys()])
            ->orderBy('ingredient_key')->orderBy('form')->orderBy('strength')->get();

        return Inertia::render('stock/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'today' => $today->toDateString(),
            'medications' => $medications->map(function (Medication $medication) use ($lots, $minimums, $today) {
                $own = $lots->get($medication->id, collect());
                $total = (int) $own->sum('balance');
                $minimum = $minimums->get($medication->id);

                return [
                    'id' => $medication->id,
                    'name' => self::label($medication),
                    'stockUnit' => $medication->stock_unit,
                    'total' => $total,
                    'minimum' => $minimum,
                    'below' => $minimum !== null && $total < $minimum,
                    'lots' => $own->map(fn (StockLot $lot) => [
                        'id' => $lot->id,
                        'code' => $lot->lot_code,
                        'expiresOn' => $lot->expires_on->format('d/m/Y'),
                        'manufacturer' => $lot->manufacturer,
                        'balance' => $lot->balance,
                        'state' => match (true) {
                            $lot->expires_on->lt($today) => 'expired',
                            $lot->expires_on->lte($today->copy()->addDays(StockLot::EXPIRING_DAYS)) => 'expiring',
                            default => 'valid',
                        },
                    ])->values(),
                ];
            })->values(),
            'catalog' => Medication::query()->where('active', true)->orderBy('ingredient_key')->orderBy('form')->orderBy('strength')->get()
                ->map(fn (Medication $medication) => ['id' => $medication->id, 'name' => self::label($medication), 'stockUnit' => $medication->stock_unit])->values(),
        ]);
    }

    /**
     * A lot entry: the lot is found or created (the same code and expiry of the medication in the unit is the
     * same lot) and an entry movement adds to it. An expired lot is not taken in.
     */
    public function storeEntry(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('entry', [
            'unit_id' => ['required', 'integer', Rule::in(self::unitIds($request))],
            'medication_id' => ['required', 'integer'],
            'lot_code' => ['required', 'string', 'max:50', 'regex:/^\s*[A-Za-z0-9][A-Za-z0-9.\/\- ]*$/'],
            // Up to 10 years ahead: a typo (2062, 20260) is caught.
            'expires_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->addYears(10)->toDateString()],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ], ['lot_code.regex' => 'O lote aceita letras sem acento, números, ponto, barra e hífen.'], [
            'unit_id' => 'unidade', 'medication_id' => 'medicamento', 'lot_code' => 'lote', 'expires_on' => 'validade', 'manufacturer' => 'fabricante', 'quantity' => 'quantidade',
        ]);
        $medication = Medication::query()->where('active', true)->whereKey((int) $data['medication_id'])->first();
        if ($medication === null) {
            throw ValidationException::withMessages(['medication_id' => 'Escolha um medicamento ativo do catálogo.'])->errorBag('entry');
        }
        $timezone = Unit::query()->whereKey($data['unit_id'])->value('timezone') ?? 'UTC';
        if ($data['expires_on'] < now($timezone)->toDateString()) {
            throw ValidationException::withMessages(['expires_on' => 'Lote vencido não entra no estoque.'])->errorBag('entry');
        }
        $code = StockLot::code($data['lot_code']);

        $manufacturer = ($data['manufacturer'] ?? null) === null ? null : Medication::clean($data['manufacturer']);

        DB::transaction(fn () => self::enter((int) $data['unit_id'], $medication, $code, $data['expires_on'], $manufacturer, (int) $data['quantity'], $request->user()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Entrada registrada.']);

        return back();
    }

    /**
     * The minimum of a medication in the unit; empty removes it.
     */
    public function updateMinimum(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('minimum', [
            'unit_id' => ['required', 'integer', Rule::in(self::unitIds($request))],
            'medication_id' => ['required', 'integer'],
            'minimum' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ], [], ['unit_id' => 'unidade', 'medication_id' => 'medicamento', 'minimum' => 'estoque mínimo']);
        abort_unless(Medication::query()->whereKey($data['medication_id'])->exists(), 404);
        $keys = ['unit_id' => $data['unit_id'], 'medication_id' => $data['medication_id']];
        $save = fn () => DB::transaction(function () use ($request, $data, $keys) {
            $minimum = $data['minimum'] ?? null;
            $current = StockMinimum::query()->where($keys)->value('minimum');
            if ($current === $minimum) {
                return;
            }
            if ($minimum === null) {
                StockMinimum::query()->where($keys)->delete();
            } else {
                StockMinimum::query()->updateOrCreate($keys, ['minimum' => $minimum, 'updated_by_user_id' => $request->user()->id]);
            }
            AuditEvent::record('stock.minimum_set', UnitPolicy::currentMembership($request->user())?->tenant_id, null, [...$keys, 'minimum' => $minimum]);
        });

        try {
            $save();
        } catch (UniqueConstraintViolationException) {
            // Created at the same time by someone else: this one updates it.
            $save();
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Estoque mínimo atualizado.']);

        return back();
    }

    /**
     * Under a transaction: finds or creates the lot and records an entry into it (the screen and the import of
     * the initial balance, step 4c.1b). A manufacturer different from the lot's is refused (ValidationException
     * on "manufacturer", bag "entry").
     *
     * @param  array<string, mixed>  $audit  more audit context (ids only)
     */
    public static function enter(int $unitId, Medication $medication, string $code, string $expiresOn, ?string $manufacturer, int $quantity, User $user, array $audit = []): StockMovement
    {
        // The same lot entered twice at once: one creates it, the other finds it (savepoint).
        $lot = StockLot::query()->createOrFirst(
            ['unit_id' => $unitId, 'medication_id' => $medication->id, 'lot_code' => $code, 'expires_on' => $expiresOn],
            ['manufacturer' => $manufacturer, 'created_by_user_id' => $user->id, 'created_at' => now()],
        );
        // A lot has one manufacturer: a different one is a different lot or a typo, never dropped silently.
        if (! $lot->wasRecentlyCreated && $manufacturer !== null && mb_strtolower((string) $lot->manufacturer) !== mb_strtolower($manufacturer)) {
            throw ValidationException::withMessages(['manufacturer' => $lot->manufacturer === null
                ? 'Este lote já foi cadastrado sem fabricante. Deixe o fabricante em branco.'
                : "Este lote já está cadastrado com o fabricante {$lot->manufacturer}."])->errorBag('entry');
        }
        $movement = StockMovement::query()->create([
            'stock_lot_id' => $lot->id, 'type' => 'entry', 'quantity' => $quantity, 'user_id' => $user->id, 'created_at' => now(),
        ]);
        AuditEvent::record('stock.entry', $lot->tenant_id, $movement, [
            'unit_id' => $lot->unit_id, 'medication_id' => $lot->medication_id, 'stock_lot_id' => $lot->id, 'quantity' => $movement->quantity, ...$audit,
        ]);

        return $movement;
    }

    public static function label(Medication $medication): string
    {
        return "{$medication->active_ingredient} {$medication->strength} ({$medication->form})";
    }

    /**
     * @return list<int>
     */
    private static function unitIds(Request $request): array
    {
        return PharmacyController::unitIds(UnitPolicy::currentMembership($request->user()));
    }
}
