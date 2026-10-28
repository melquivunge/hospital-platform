<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BillingItem;
use App\Models\Exam;
use App\Models\Insurer;
use App\Models\InsurerContract;
use App\Models\Material;
use App\Models\Medication;
use App\Models\PriceEntry;
use App\Models\PriceTable;
use App\Models\SurgicalProcedure;
use App\Models\User;
use App\Stock\StockItem;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The network's billable items, price tables and prices (step 6.1a, ADR-0010), kept by the network admin (the same
 * gate as the other network catalogs). A new price ends the one before it the day before it starts; a price is never
 * changed nor deleted (trigger) and two never hold on the same day (exclusion constraint). No patient data.
 */
class BillingPriceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage', Medication::class);
        $tables = PriceTable::query()->orderByDesc('active')->orderBy('name')->get(['id', 'name', 'active', 'private']);
        $contracts = InsurerContract::query()->get()->keyBy('insurer_id');
        $authorizations = DB::table('insurer_contract_authorizations')->whereIn('insurer_contract_id', $contracts->pluck('id'))->get(['insurer_contract_id', 'billing_item_id'])->groupBy('insurer_contract_id');
        $table = $tables->firstWhere('id', $request->integer('table')) ?? $tables->first();

        return Inertia::render('billing-prices/index', [
            'kinds' => BillingItem::KINDS,
            'bedTypes' => BillingItem::BED_TYPES,
            'items' => BillingItem::query()->orderBy('kind')->orderBy('name')->get()->map->only('id', 'kind', 'bed_type', 'name', 'code', 'sigtap_code', 'active')->values(),
            'tables' => $tables->values(),
            'tableId' => $table?->id,
            'prices' => $table === null ? [] : PriceEntry::query()->where('price_table_id', $table->id)->orderBy('billing_item_id')->orderByDesc('valid_from')->get()
                ->map(fn (PriceEntry $entry) => ['id' => $entry->id, 'itemId' => $entry->billing_item_id, 'cents' => $entry->price_cents,
                    'from' => $entry->valid_from->format('d/m/Y'), 'until' => $entry->valid_until?->format('d/m/Y')])->values(),
            // Step 6.1b: each active insurer with its contract.
            'insurers' => Insurer::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn (Insurer $insurer) => [
                'id' => $insurer->id,
                'name' => $insurer->name,
                'contract' => ($contract = $contracts->get($insurer->id)) === null ? null : [
                    'id' => $contract->id, 'tableId' => $contract->price_table_id, 'adjustmentBp' => $contract->adjustment_bp, 'providerCode' => $contract->provider_code,
                    'authorizationItemIds' => ($authorizations->get($contract->id) ?? collect())->pluck('billing_item_id')->map(fn ($id) => (int) $id)->values(),
                ],
            ])->values(),
            // What an item may be tied to, by kind (active catalog entries).
            'references' => [
                'exam' => Exam::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->map->only('id', 'name')->values(),
                'procedure' => SurgicalProcedure::query()->where('active', true)->orderBy('name_key')->get(['id', 'name'])->map->only('id', 'name')->values(),
                'material' => Material::query()->where('active', true)->orderBy('name_key')->get(['id', 'name'])->map->only('id', 'name')->values(),
                'medication' => Medication::query()->where('active', true)->orderBy('ingredient_key')->orderBy('form')->orderBy('strength')->get()
                    ->map(fn (Medication $medication) => ['id' => $medication->id, 'name' => StockItem::label($medication)])->values(),
            ],
        ]);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $data = $request->validateWithBag('newItem', [
            'kind' => ['required', Rule::in(array_keys(BillingItem::KINDS))],
            'reference_id' => ['nullable', 'required_if:kind,exam,procedure,material,medication', 'integer'],
            'bed_type' => ['nullable', 'required_if:kind,daily', Rule::in(array_keys(BillingItem::BED_TYPES))],
            'name' => ['required', 'string', 'max:255', 'not_regex:/^[=+\-@\t\r]/'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/', 'not_regex:/^-/'],
            // Step 6b.3: the SIGTAP procedure the item is billed as in the SUS.
            'sigtap_code' => ['nullable', 'string', 'regex:/^\d{10}\z/'],
        ], ['sigtap_code.regex' => 'O código SIGTAP tem 10 dígitos.'], ['kind' => 'tipo', 'reference_id' => 'item do catálogo', 'bed_type' => 'tipo de leito', 'name' => 'nome', 'code' => 'código']);
        $column = BillingItem::REFERENCES[$data['kind']] ?? null;
        // Only an active entry of this network's catalog (the tenant scope applies to the query).
        if ($column !== null && ! self::catalog($data['kind'])->whereKey((int) $data['reference_id'])->exists()) {
            throw ValidationException::withMessages(['reference_id' => 'Escolha um item ativo do catálogo.'])->errorBag('newItem');
        }
        self::unique(function () use ($data, $column) {
            $item = BillingItem::create([
                'kind' => $data['kind'], 'name' => Medication::clean($data['name']), 'code' => $data['code'] ?? null, 'sigtap_code' => $data['sigtap_code'] ?? null,
                'bed_type' => $data['kind'] === 'daily' ? $data['bed_type'] : null, ...($column === null ? [] : [$column => (int) $data['reference_id']]),
            ]);
            AuditEvent::record('billing_item.created', $item->tenant_id, $item, ['kind' => $item->kind]);
        }, 'newItem', 'name', 'Já existe um item cobrável para isto.');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item cobrável cadastrado.']);

        return back();
    }

    public function updateItem(Request $request, BillingItem $item): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $data = $request->validateWithBag("item-{$item->id}", [
            'name' => ['required', 'string', 'max:255', 'not_regex:/^[=+\-@\t\r]/'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.-]+$/', 'not_regex:/^-/'],
            'sigtap_code' => ['nullable', 'string', 'regex:/^\d{10}\z/'],
            'active' => ['required', 'boolean'],
        ], ['sigtap_code.regex' => 'O código SIGTAP tem 10 dígitos.'], ['name' => 'nome', 'code' => 'código']);
        $item->fill(['name' => Medication::clean($data['name']), 'code' => $data['code'] ?? null, 'sigtap_code' => $data['sigtap_code'] ?? null, 'active' => $data['active']]);
        $changed = array_values(array_diff(array_keys($item->getDirty()), ['updated_at']));
        $item->save();
        if ($changed !== []) {
            AuditEvent::record('billing_item.updated', $item->tenant_id, $item, ['fields' => $changed, 'active' => $item->active]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item cobrável atualizado.']);

        return back();
    }

    public function storeTable(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $data = $request->validateWithBag('newTable', ['name' => ['required', 'string', 'max:100', 'not_regex:/^[=+\-@\t\r]/']], [], ['name' => 'nome']);
        $table = null;
        self::unique(function () use ($data, &$table) {
            $table = PriceTable::create(['name' => Medication::clean($data['name'])]);
            AuditEvent::record('price_table.created', $table->tenant_id, $table);
        }, 'newTable', 'name', 'Já existe uma tabela com este nome.');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tabela de preços criada.']);

        return to_route('billing-prices.index', ['table' => $table?->id]);
    }

    public function updateTable(Request $request, PriceTable $table): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $bag = "table-{$table->id}";
        $data = $request->validateWithBag($bag, ['name' => ['required', 'string', 'max:100', 'not_regex:/^[=+\-@\t\r]/'], 'active' => ['required', 'boolean']], [], ['name' => 'nome']);
        if ($table->private && ! $data['active']) {
            throw ValidationException::withMessages(['active' => 'A tabela particular não é desativada. Defina outra tabela particular antes.'])->errorBag($bag);
        }
        $table->fill(['name' => Medication::clean($data['name']), 'active' => $data['active']]);
        $changed = array_values(array_diff(array_keys($table->getDirty()), ['updated_at']));
        self::unique(fn () => $table->save(), $bag, 'name', 'Já existe uma tabela com este nome.');
        if ($changed !== []) {
            AuditEvent::record('price_table.updated', $table->tenant_id, $table, ['fields' => $changed, 'active' => $table->active]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tabela de preços atualizada.']);

        return back();
    }

    /**
     * A new price of an item in a table from a day on: the open price before it ends the day before; a price already
     * starting on or after that day is refused (a correction is a new price from a later day).
     */
    public function storePrice(Request $request, PriceTable $table): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $data = $request->validateWithBag('price', [
            'billing_item_id' => ['required', 'integer', Rule::exists('billing_items', 'id')->where('tenant_id', $table->tenant_id)->where('active', true)],
            'price' => ['required', 'string', 'max:20'],
            'valid_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYears(5)->toDateString()],
        ], [], ['billing_item_id' => 'item', 'price' => 'preço', 'valid_from' => 'início da vigência']);
        if (! $table->active) {
            throw ValidationException::withMessages(['billing_item_id' => 'Esta tabela está inativa. Reative-a antes de registrar preços.'])->errorBag('price');
        }
        $cents = PriceEntry::cents($data['price']);
        if ($cents === null) {
            throw ValidationException::withMessages(['price' => 'Escreva o preço em reais, como 1.234,56.'])->errorBag('price');
        }
        self::enterPrice($table, (int) $data['billing_item_id'], $cents, $data['valid_from'], $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Preço registrado.']);

        return back();
    }

    /**
     * Records a price from a day on (the screen and the CSV import, step 6.1c): the open price before it ends the day
     * before; a price already starting on or after that day is refused (ValidationException on "valid_from", bag
     * "price"). Its own transaction, with the table locked first.
     *
     * @param  array<string, mixed>  $audit  more audit context (ids only)
     */
    public static function enterPrice(PriceTable $table, int $itemId, int $cents, string $from, User $user, array $audit = []): PriceEntry
    {
        try {
            return DB::transaction(function () use ($table, $itemId, $cents, $from, $user, $audit) {
                // The table first: two prices of the same table at once wait for each other.
                PriceTable::query()->lockForUpdate()->findOrFail($table->id);
                $entries = PriceEntry::query()->where('price_table_id', $table->id)->where('billing_item_id', $itemId);
                if ((clone $entries)->where('valid_from', '>=', $from)->exists()) {
                    throw ValidationException::withMessages(['valid_from' => 'Já há um preço deste item a partir desta data ou depois. Escolha um início posterior.'])->errorBag('price');
                }
                (clone $entries)->whereNull('valid_until')->first()?->update(['valid_until' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
                $entry = PriceEntry::create([
                    'price_table_id' => $table->id, 'billing_item_id' => $itemId, 'price_cents' => $cents,
                    'valid_from' => $from, 'created_by_user_id' => $user->id, 'created_at' => now(),
                ]);
                AuditEvent::record('price_entry.created', $table->tenant_id, $entry, ['price_table_id' => $table->id, 'billing_item_id' => $itemId, 'price_cents' => $cents, 'valid_from' => $from, ...$audit]);

                return $entry;
            });
        } catch (QueryException $exception) {
            // An overlap that slipped past the checks above: the exclusion constraint has the last word.
            throw $exception->getCode() === '23P01' ? ValidationException::withMessages(['valid_from' => 'Este período se sobrepõe a outro preço do item.'])->errorBag('price') : $exception;
        }
    }

    /**
     * The contract of an insurer of the network (step 6.1b): its table and adjustment; created on the first save.
     */
    public function saveContract(Request $request, Insurer $insurer): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $bag = "contract-{$insurer->id}";
        $data = $request->validateWithBag($bag, [
            'price_table_id' => ['required', 'integer', Rule::exists('price_tables', 'id')->where('tenant_id', $insurer->tenant_id)->where('active', true)],
            'adjustment' => ['nullable', 'string', 'max:10'],
            // Step 6.5a: the hospital's code at this insurer (codigoPrestadorNaOperadora, TISS).
            'provider_code' => ['nullable', 'string', 'max:14'],
        ], [], ['price_table_id' => 'tabela', 'adjustment' => 'ajuste', 'provider_code' => 'código do prestador na operadora']);
        $providerCode = trim((string) ($data['provider_code'] ?? '')) === '' ? null : trim((string) $data['provider_code']);
        $points = ($data['adjustment'] ?? '') === '' ? 0 : InsurerContract::basisPoints($data['adjustment']);
        if ($points === null || $points < -10000 || $points > 100000) {
            throw ValidationException::withMessages(['adjustment' => 'Escreva o ajuste em porcentagem, de -100 a 1000, como 12,5.'])->errorBag($bag);
        }
        // The first save of two at once: one creates the contract, the other updates it (unique insurer_id, savepoint).
        try {
            DB::transaction(function () use ($insurer, $data, $points, $providerCode) {
                $contract = InsurerContract::query()->firstOrCreate(['insurer_id' => $insurer->id], ['price_table_id' => (int) $data['price_table_id'], 'adjustment_bp' => $points, 'provider_code' => $providerCode]);
                if ($contract->wasRecentlyCreated) {
                    AuditEvent::record('insurer_contract.saved', $contract->tenant_id, $contract, ['insurer_id' => $insurer->id, 'fields' => ['insurer_id', 'price_table_id', 'adjustment_bp', ...($providerCode === null ? [] : ['provider_code'])], 'price_table_id' => $contract->price_table_id, 'adjustment_bp' => $points]);
                }
            });
        } catch (UniqueConstraintViolationException) {
        }
        DB::transaction(function () use ($insurer, $data, $points, $providerCode) {
            $contract = InsurerContract::query()->lockForUpdate()->where('insurer_id', $insurer->id)->firstOrFail();
            $contract->fill(['price_table_id' => (int) $data['price_table_id'], 'adjustment_bp' => $points, 'provider_code' => $providerCode]);
            $changed = array_values(array_diff(array_keys($contract->getDirty()), ['updated_at']));
            $contract->save();
            if ($changed !== []) {
                AuditEvent::record('insurer_contract.saved', $contract->tenant_id, $contract, ['insurer_id' => $insurer->id, 'fields' => $changed, 'price_table_id' => $contract->price_table_id, 'adjustment_bp' => $points]);
            }
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contrato salvo.']);

        return back();
    }

    /**
     * The billable items that need the insurer's authorization: the list sent replaces the one before.
     */
    public function saveAuthorizations(Request $request, InsurerContract $contract): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        $data = $request->validateWithBag("authorizations-{$contract->id}", [
            'billing_item_ids' => ['nullable', 'array', 'max:2000'],
            'billing_item_ids.*' => ['integer', Rule::exists('billing_items', 'id')->where('tenant_id', $contract->tenant_id)],
        ], [], ['billing_item_ids' => 'itens']);
        $ids = array_values(array_unique(array_map('intval', $data['billing_item_ids'] ?? [])));
        DB::transaction(function () use ($contract, $ids) {
            InsurerContract::query()->lockForUpdate()->findOrFail($contract->id);
            // Only active items are on the screen: the requirement on an inactive one stays as it was.
            DB::table('insurer_contract_authorizations')->where('insurer_contract_id', $contract->id)->whereNotIn('billing_item_id', $ids)
                ->whereIn('billing_item_id', BillingItem::query()->where('active', true)->select('id'))->delete();
            DB::table('insurer_contract_authorizations')->insertOrIgnore(array_map(fn (int $id) => ['tenant_id' => $contract->tenant_id, 'insurer_contract_id' => $contract->id, 'billing_item_id' => $id], $ids));
            AuditEvent::record('insurer_contract.authorizations_saved', $contract->tenant_id, $contract, ['insurer_id' => $contract->insurer_id, 'billing_item_ids' => $ids]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Itens que exigem autorização salvos.']);

        return back();
    }

    /**
     * Makes a table the network's private one (the one before stops being it).
     */
    public function setPrivate(Request $request, PriceTable $table): RedirectResponse
    {
        Gate::authorize('manage', Medication::class);
        DB::transaction(function () use ($table) {
            // One at a time: the tenant's tables locked, the old private one cleared before the new one is set.
            PriceTable::query()->lockForUpdate()->get(['id']);
            if (! PriceTable::query()->whereKey($table->id)->value('active')) {
                throw ValidationException::withMessages(['private' => 'Uma tabela inativa não vira a tabela particular.'])->errorBag("table-{$table->id}");
            }
            PriceTable::query()->where('private', true)->whereKeyNot($table->id)->update(['private' => false]);
            PriceTable::query()->whereKey($table->id)->update(['private' => true]);
            AuditEvent::record('price_table.private_set', $table->tenant_id, $table);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tabela particular definida.']);

        return back();
    }

    /**
     * @return Builder<covariant Model>
     */
    private static function catalog(string $kind): Builder
    {
        return match ($kind) {
            'exam' => Exam::query()->where('active', true),
            'procedure' => SurgicalProcedure::query()->where('active', true),
            'material' => Material::query()->where('active', true),
            default => Medication::query()->where('active', true),
        };
    }

    /**
     * @param  Closure(): mixed  $save
     */
    private static function unique(Closure $save, string $bag, string $field, string $message): void
    {
        try {
            DB::transaction($save);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => $message])->errorBag($bag);
        }
    }
}
