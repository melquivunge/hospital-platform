<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\BillingAccountItem;
use App\Models\BillingItem;
use App\Models\Insurer;
use App\Models\InsurerContract;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\RoleAssignment;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The accounts of a unit for its Billing role (step 6.2c, ADR-0010): the list, with identification, modality, totals
 * and pending items only (no read logged), and one account at a time, each opening logged with the reason "billing".
 * Billing sees the patient's name, the card, and each item's day, code, name, quantity, price and origin by its
 * type: never the record (notes, results, reports, descriptions, indications). Opening an account first adds what
 * is still unbilled.
 */
class BillingAccountController extends Controller
{
    /** ponytail: newest first and capped; paginate when a unit's open accounts outgrow it. */
    private const LIST_LIMIT = 200;

    public const MODALITIES = ['private' => 'Particular', 'insurance' => 'Convênio', 'sus' => 'SUS'];

    /** Step 6.4b: how a private account was paid (informative, no integration). */
    public const METHODS = ['cash' => 'Dinheiro', 'debit' => 'Cartão de débito', 'credit' => 'Cartão de crédito', 'pix' => 'Pix', 'transfer' => 'Transferência'];

    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(self::unitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $closedOnly = $request->boolean('closed');
        $pendingOnly = ! $closedOnly && $request->boolean('pending');
        // Patients' names and stays: kept out of the browser's history in plain text.
        Inertia::encryptHistory();
        $accounts = self::withTotals()->where('unit_id', $unit->id)->where('status', $closedOnly ? 'closed' : 'open')
            ->when($pendingOnly, fn ($query) => $query->whereHas('items', self::pending(...)))
            ->orderByDesc($closedOnly ? 'closed_at' : 'opened_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();

        return Inertia::render('billing-accounts/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'pendingOnly' => $pendingOnly,
            'closedOnly' => $closedOnly,
            'accounts' => self::describe(array_values($accounts->all()), $unit->timezone ?? 'UTC'),
        ]);
    }

    public function show(Request $request, BillingAccount $account): Response
    {
        abort_unless(in_array($account->unit_id, self::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        // The account is complete when opened: late results, returns and days are billed now (ADR-0010).
        rescue(fn () => $account->generate());
        ClinicalAccess::recordRead($request->user(), Patient::query()->findOrFail($account->patient_id), $account);
        Inertia::encryptHistory();
        $timezone = Unit::query()->whereKey($account->unit_id)->value('timezone') ?? 'UTC';
        $account = self::withTotals()->findOrFail($account->id);
        $items = BillingAccountItem::query()->where('billing_account_id', $account->id)->orderBy('performed_on')->orderBy('id')->get();
        $authors = User::query()->whereKey($items->pluck('author_user_id')->filter()->unique()->all())->pluck('name', 'id');
        $compensated = $items->where('origin_type', 'compensation')->pluck('origin_id')->flip();
        // Step 6.3b: the latest answer of each item (insert-only records).
        $answers = DB::table('billing_authorizations')->whereIn('billing_account_item_id', $items->modelKeys())->orderBy('id')->get()->keyBy('billing_account_item_id');
        $authors = $authors->union(User::query()->whereKey($answers->pluck('author_user_id')->unique()->all())->pluck('name', 'id'));

        return Inertia::render('billing-accounts/show', [
            'account' => [
                ...self::describe([$account], $timezone)[0],
                'card' => $account->insurance_card_number,
                'cardValidUntil' => $account->insurance_card_valid_until?->format('d/m/Y'),
            ],
            'items' => $items->map(fn (BillingAccountItem $item) => [
                'id' => $item->id,
                'day' => $item->performed_on->format('d/m/Y'),
                'origin' => $item->origin_type,
                'code' => $item->code,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'unitPriceCents' => $item->unit_price_cents,
                'needsAuthorization' => $item->needs_authorization,
                // Step 6.3a: billing's own items carry why and who; an item is compensated once.
                'reason' => $item->reason,
                'author' => $item->author_user_id === null ? null : $authors->get($item->author_user_id),
                'compensated' => $compensated->has($item->id),
                'authorization' => ($answer = $answers->get($item->id)) === null ? null : [
                    'status' => $answer->status,
                    'number' => $answer->number,
                    'validUntil' => $answer->valid_until === null ? null : CarbonImmutable::parse($answer->valid_until)->format('d/m/Y'),
                    'reason' => $answer->reason,
                    'author' => $authors->get($answer->author_user_id),
                ],
            ])->values(),
            'canChange' => $account->status === 'open' && in_array($account->modality, ['private', 'insurance'], true),
            // Step 6.4a: an item of a closed account is compensated in the complementary account.
            'canCompensate' => in_array($account->modality, ['private', 'insurance'], true),
            'canClose' => $account->status === 'open' && in_array($account->modality, ['private', 'insurance'], true) && $account->stayEnded(),
            // Step 6.4b: the private account's payment and its receipt.
            'payment' => ($payment = DB::table('billing_payments')->where('billing_account_id', $account->id)->first()) === null ? null : [
                'method' => self::METHODS[$payment->method], 'amountCents' => (int) $payment->amount_cents,
                'receivedAt' => CarbonImmutable::parse($payment->received_at)->setTimezone($timezone)->format('d/m/Y H:i'),
                'by' => User::query()->whereKey($payment->received_by_user_id)->value('name'),
            ],
            'canReceive' => $payment === null && $account->status === 'closed' && $account->modality === 'private' && (int) $account->getAttribute('total_cents') > 0,
            'methods' => self::METHODS,
            'complements' => BillingAccount::query()->where('complement_of_id', $account->complement_of_id ?? $account->id)->orderBy('id')->pluck('id')->all(),
            'billableItems' => BillingItem::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code'])->values(),
            'today' => now($timezone)->toDateString(),
        ]);
    }

    /**
     * Step 6.3a: a manual item, from the network's billable items, priced by the table of its day for the account's
     * modality, with the reason and the author. Only while the account is open, and not for SUS (delivery 6b).
     */
    public function storeItem(Request $request, BillingAccount $account): RedirectResponse
    {
        abort_unless(in_array($account->unit_id, self::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $timezone = Unit::query()->whereKey($account->unit_id)->value('timezone') ?? 'UTC';
        $data = $request->validateWithBag('item', [
            'billing_item_id' => ['required', 'integer', Rule::exists('billing_items', 'id')->where('tenant_id', $account->tenant_id)->where('active', true)],
            'performed_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$account->opened_at->setTimezone($timezone)->toDateString(), 'before_or_equal:'.now($timezone)->toDateString()],
            'quantity' => ['required', 'integer', 'between:1,1000'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'performed_on.after_or_equal' => 'A data não pode ser anterior à abertura da conta.',
            'performed_on.before_or_equal' => 'A data não pode estar no futuro.',
        ], ['billing_item_id' => 'item', 'performed_on' => 'data', 'quantity' => 'quantidade', 'reason' => 'motivo']);

        DB::transaction(function () use ($request, $account, $data) {
            $locked = self::lockOpen($account, 'item');
            $billable = BillingItem::query()->findOrFail((int) $data['billing_item_id']);
            $item = new BillingAccountItem;
            $item->forceFill([
                'billing_account_id' => $locked->id, 'origin_type' => 'manual', 'origin_id' => null, 'billing_item_id' => $billable->id,
                'code' => $billable->code, 'name' => $billable->name, 'quantity' => (int) $data['quantity'],
                'unit_price_cents' => InsurerContract::priceFor($locked->insurer_id, $billable->id, $data['performed_on']),
                'needs_authorization' => $locked->insurer_id !== null && InsurerContract::needsAuthorization($locked->insurer_id, $billable->id),
                'performed_on' => $data['performed_on'], 'reason' => trim($data['reason']), 'author_user_id' => $request->user()->id, 'created_at' => now(),
            ])->save();
            // Ids and values only: never the item's name next to the patient.
            AuditEvent::record('billing_item.added', $locked->tenant_id, $item, ['billing_account_id' => $locked->id, 'billing_item_id' => $billable->id, 'quantity' => $item->quantity, 'unit_price_cents' => $item->unit_price_cents]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item incluído.']);

        return back();
    }

    /**
     * Step 6.3a: an item is never deleted; it is compensated, once, by a negative item at its quantity and price, with
     * the reason and the author.
     */
    public function compensate(Request $request, BillingAccountItem $item): RedirectResponse
    {
        $account = BillingAccount::query()->findOrFail($item->billing_account_id);
        abort_unless(in_array($account->unit_id, self::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $bag = "compensate{$item->id}";
        $data = $request->validateWithBag($bag, ['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        $taken = ValidationException::withMessages(['reason' => 'Este item já foi estornado.'])->errorBag($bag);
        if ($item->origin_type === 'compensation') {
            throw ValidationException::withMessages(['reason' => 'Um estorno não é estornado.'])->errorBag($bag);
        }

        try {
            DB::transaction(function () use ($request, $account, $item, $data, $bag, $taken) {
                // Step 6.4a: an item of a closed account is compensated in its open complementary account.
                $family = BillingAccount::query()->lockForUpdate()->findOrFail($account->complement_of_id ?? $account->id);
                if (! in_array($family->modality, ['private', 'insurance'], true)) {
                    throw ValidationException::withMessages(['reason' => 'Esta conta não recebe mudanças: é do SUS ou não tem modalidade.'])->errorBag($bag);
                }
                $locked = BillingAccount::target($family);
                if (BillingAccountItem::query()->where('origin_type', 'compensation')->where('origin_id', $item->id)->exists()) {
                    throw $taken;
                }
                $compensation = new BillingAccountItem;
                $compensation->forceFill([
                    'billing_account_id' => $locked->id, 'origin_type' => 'compensation', 'origin_id' => $item->id, 'billing_item_id' => $item->billing_item_id,
                    'code' => $item->code, 'name' => $item->name, 'quantity' => -$item->quantity, 'unit_price_cents' => $item->unit_price_cents,
                    'needs_authorization' => false, 'performed_on' => now(Unit::query()->whereKey($locked->unit_id)->value('timezone') ?? 'UTC')->toDateString(),
                    'reason' => trim($data['reason']), 'author_user_id' => $request->user()->id, 'created_at' => now(),
                ])->save();
                AuditEvent::record('billing_item.compensated', $locked->tenant_id, $compensation, ['billing_account_id' => $locked->id, 'compensated_item_id' => $item->id, 'quantity' => $compensation->quantity, 'unit_price_cents' => $compensation->unit_price_cents]);
            });
        } catch (UniqueConstraintViolationException) {
            throw $taken;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Item estornado.']);

        return back();
    }

    /**
     * Step 6.3b: the insurer's answer for items that need it, by item or by guide (several items, one number):
     * authorized (number and validity, valid on the day of each item), denied (reason) or urgency without it (reason).
     * Insert-only: the latest answer is the item's state. Only in an insurer's open account.
     */
    public function authorizeItems(Request $request, BillingAccount $account): RedirectResponse
    {
        abort_unless(in_array($account->unit_id, self::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $data = $request->validateWithBag('authorization', [
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['integer', 'distinct'],
            'status' => ['required', Rule::in(['authorized', 'denied', 'urgency'])],
            'number' => ['exclude_unless:status,authorized', 'required', 'string', 'max:40'],
            'valid_until' => ['exclude_unless:status,authorized', 'required', 'date_format:Y-m-d'],
            'reason' => ['exclude_if:status,authorized', 'required', 'string', 'max:500'],
        ], [], ['item_ids' => 'itens', 'status' => 'resposta', 'number' => 'número da autorização', 'valid_until' => 'validade', 'reason' => 'motivo']);

        DB::transaction(function () use ($request, $account, $data) {
            $locked = self::lockOpen($account, 'item_ids', 'authorization');
            $items = BillingAccountItem::query()->where('billing_account_id', $locked->id)->whereKey($data['item_ids'])
                ->where('needs_authorization', true)->where('origin_type', '<>', 'compensation')
                ->whereNotIn('id', BillingAccountItem::query()->where('origin_type', 'compensation')->select('origin_id'))->get();
            if ($locked->insurer_id === null || $items->count() !== count($data['item_ids'])) {
                throw ValidationException::withMessages(['item_ids' => 'Escolha itens desta conta que exigem autorização do convênio.'])->errorBag('authorization');
            }
            if (isset($data['valid_until']) && $items->contains(fn (BillingAccountItem $item) => $item->performed_on->toDateString() > $data['valid_until'])) {
                throw ValidationException::withMessages(['valid_until' => 'A validade não cobre a data de todos os itens escolhidos.'])->errorBag('authorization');
            }
            DB::table('billing_authorizations')->insert($items->map(fn (BillingAccountItem $item) => [
                'tenant_id' => $locked->tenant_id, 'billing_account_item_id' => $item->id, 'status' => $data['status'],
                'number' => isset($data['number']) ? trim($data['number']) : null, 'valid_until' => $data['valid_until'] ?? null,
                'reason' => isset($data['reason']) ? trim($data['reason']) : null, 'author_user_id' => $request->user()->id, 'created_at' => now(),
            ])->all());
            // Ids and the answer only.
            AuditEvent::record('billing_item.authorization', $locked->tenant_id, $locked, ['item_ids' => $items->modelKeys(), 'status' => $data['status']]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => match ($data['status']) {
            'authorized' => 'Autorização registrada.', 'denied' => 'Negativa registrada.', default => 'Urgência registrada.',
        }]);

        return back();
    }

    /**
     * Step 6.4a: billing closes the account once the stay ended and nothing is pending (an item without a price, or one
     * waiting for the insurer's authorization, urgency excepted); late records are billed first. A closed account is
     * immutable in the database; what comes afterwards goes to a complementary account. Closing twice closes once.
     */
    public function close(Request $request, BillingAccount $account): RedirectResponse
    {
        abort_unless(in_array($account->unit_id, self::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        // Late records first; an account already closed is not touched (its late records wait for the complement).
        if ($account->status === 'open') {
            rescue(fn () => $account->generate());
        }
        $refuse = fn (string $message) => ValidationException::withMessages(['close' => $message])->errorBag('close');

        DB::transaction(function () use ($request, $account, $refuse) {
            // The stay's account first, as the generation: nothing lands in this account while it closes.
            BillingAccount::query()->lockForUpdate()->findOrFail($account->complement_of_id ?? $account->id);
            $locked = BillingAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($locked->status !== 'open') {
                throw $refuse('Esta conta já foi fechada.');
            }
            if (! in_array($locked->modality, ['private', 'insurance'], true)) {
                throw $refuse('Contas do SUS ou sem modalidade ficam para a entrega do SUS.');
            }
            if (! $locked->stayEnded()) {
                throw $refuse('O atendimento ainda não terminou: a conta fecha depois do encerramento ou da alta.');
            }
            $items = BillingAccountItem::query()->where('billing_account_id', $locked->id);
            if (! (clone $items)->exists()) {
                throw $refuse('A conta não tem itens.');
            }
            if ((clone $items)->where(fn (Builder $query) => self::pending($query))->exists()) {
                throw $refuse('Resolva as pendências antes de fechar: itens sem preço ou sem autorização do convênio.');
            }
            $locked->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $request->user()->id])->save();
            $total = (int) (clone $items)->sum(DB::raw('quantity * unit_price_cents'));
            AuditEvent::record('billing_account.closed', $locked->tenant_id, $locked, ['unit_id' => $locked->unit_id, 'total_cents' => $total, 'complement_of_id' => $locked->complement_of_id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conta fechada.']);

        return back();
    }

    /**
     * Step 6.4b: the payment of a closed private account, once: its total, and how it was paid (informative).
     */
    public function receive(Request $request, BillingAccount $account): RedirectResponse
    {
        abort_unless(in_array($account->unit_id, self::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $data = $request->validateWithBag('payment', ['method' => ['required', Rule::in(array_keys(self::METHODS))]], [], ['method' => 'forma de pagamento']);
        $refuse = fn (string $message) => ValidationException::withMessages(['method' => $message])->errorBag('payment');

        try {
            DB::transaction(function () use ($request, $account, $data, $refuse) {
                $locked = BillingAccount::query()->lockForUpdate()->findOrFail($account->id);
                if ($locked->status !== 'closed' || $locked->modality !== 'private') {
                    throw $refuse('O recebimento é registrado numa conta particular fechada.');
                }
                if (DB::table('billing_payments')->where('billing_account_id', $locked->id)->exists()) {
                    throw $refuse('O recebimento desta conta já foi registrado.');
                }
                $total = (int) BillingAccountItem::query()->where('billing_account_id', $locked->id)->sum(DB::raw('quantity * unit_price_cents'));
                if ($total <= 0) {
                    throw $refuse('A conta não tem valor a receber.');
                }
                $id = DB::table('billing_payments')->insertGetId([
                    'tenant_id' => $locked->tenant_id, 'billing_account_id' => $locked->id, 'method' => $data['method'], 'amount_cents' => $total,
                    'received_by_user_id' => $request->user()->id, 'received_at' => now(),
                ]);
                AuditEvent::record('billing_payment.received', $locked->tenant_id, $locked, ['payment_id' => $id, 'amount_cents' => $total, 'method' => $data['method']]);
            });
        } catch (UniqueConstraintViolationException) {
            throw $refuse('O recebimento desta conta já foi registrado.');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Recebimento registrado.']);

        return back();
    }

    /**
     * Step 6.4b: the receipt (PDF) of a paid private account: the network and unit, the patient, the items and the
     * payment. A read of the account, logged in the trail like opening it.
     */
    public function receipt(Request $request, BillingAccount $account): SymfonyResponse
    {
        abort_unless(in_array($account->unit_id, self::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $payment = DB::table('billing_payments')->where('billing_account_id', $account->id)->first();
        abort_if($payment === null, 404);
        $patient = Patient::query()->findOrFail($account->patient_id);
        $patient = $patient->merged_into_id === null ? $patient : Patient::query()->findOrFail($patient->merged_into_id);
        ClinicalAccess::recordRead($request->user(), $patient, $account);
        $unit = Unit::query()->findOrFail($account->unit_id);
        $timezone = $unit->timezone ?? 'UTC';
        $pdf = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']));
        $pdf->loadHtml(view('billing.receipt', [
            'network' => $unit->tenant->name, 'unit' => $unit->name, 'number' => $payment->id, 'account' => $account->id,
            'patient' => $patient->social_name ?? $patient->name, 'cpf' => $patient->cpf,
            'items' => BillingAccountItem::query()->where('billing_account_id', $account->id)->orderBy('performed_on')->orderBy('id')->get()
                ->map(fn (BillingAccountItem $item) => ['day' => $item->performed_on->format('d/m/Y'), 'code' => $item->code, 'name' => $item->name, 'quantity' => $item->quantity,
                    'price' => self::reais((int) $item->unit_price_cents), 'total' => self::reais($item->quantity * (int) $item->unit_price_cents)])->all(),
            'total' => self::reais((int) $payment->amount_cents), 'method' => self::METHODS[$payment->method],
            'receivedAt' => CarbonImmutable::parse($payment->received_at)->setTimezone($timezone)->format('d/m/Y H:i'),
        ])->render());
        $pdf->setPaper('A4');
        $pdf->render();
        AuditEvent::record('billing_payment.receipt', $account->tenant_id, $account, ['payment_id' => $payment->id]);

        return response((string) $pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"recibo-{$payment->id}.pdf\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function reais(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    /**
     * Inside the transaction: the account, locked, while it is open and its modality is billed here.
     */
    private static function lockOpen(BillingAccount $account, string $field, string $bag = 'item'): BillingAccount
    {
        $locked = BillingAccount::query()->lockForUpdate()->findOrFail($account->id);
        if ($locked->status !== 'open' || ! in_array($locked->modality, ['private', 'insurance'], true)) {
            throw ValidationException::withMessages([$field => 'Esta conta não recebe mudanças: está fechada, é do SUS ou não tem modalidade.'])->errorBag($bag);
        }

        return $locked;
    }

    /**
     * Accounts with their item count, pending count and total in cents.
     *
     * @return Builder<BillingAccount>
     */
    private static function withTotals(): Builder
    {
        return BillingAccount::query()->withCount(['items', 'items as pending_count' => self::pending(...)])
            ->withSum('items as total_cents', DB::raw('quantity * unit_price_cents'));
    }

    /**
     * Pending: an item without a price, or one the insurer's contract authorizes (recorded from step 6.3b); neither a
     * compensation nor a compensated item (step 6.3a), which no longer count.
     *
     * @param  Builder<BillingAccountItem>  $items
     */
    private static function pending(Builder $items): void
    {
        // Step 6.3b: an item that needs authorization stops pending when its latest answer is authorized or urgency.
        $items->where(fn (Builder $item) => $item->whereNull('unit_price_cents')->orWhere(fn (Builder $needs) => $needs->where('needs_authorization', true)
            ->whereRaw("coalesce((SELECT status FROM billing_authorizations WHERE billing_account_item_id = billing_account_items.id ORDER BY id DESC LIMIT 1), '') NOT IN ('authorized', 'urgency')")))
            ->where('origin_type', '<>', 'compensation')
            ->whereNotExists(fn ($compensations) => $compensations->from('billing_account_items as compensations')
                ->where('compensations.origin_type', 'compensation')->whereColumn('compensations.origin_id', 'billing_account_items.id'));
    }

    /**
     * The units where the membership bills: those of its Billing role, or every unit of the network for the network
     * admin (user's decision, 27/10/2026). Each opening of an account is still logged in the trail.
     *
     * @return list<int>
     */
    public static function unitIds(?Membership $membership): array
    {
        if ($membership === null || $membership->revoked_at !== null) {
            return [];
        }
        if ($membership->hasRole(Role::NetworkAdmin)) {
            return array_values(array_map('intval', Unit::query()->where('tenant_id', $membership->tenant_id)->pluck('id')->all()));
        }

        return array_values(array_unique($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => $assignment->role === Role::Billing)
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)->all()));
    }

    /**
     * Identification, stay and totals only.
     *
     * @param  list<BillingAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    private static function describe(array $accounts, string $timezone): array
    {
        $patients = Patient::query()->whereKey(array_map(fn (BillingAccount $account) => $account->patient_id, $accounts))->get(['id', 'name', 'social_name', 'merged_into_id'])->keyBy('id');
        $primaries = Patient::query()->whereKey($patients->pluck('merged_into_id')->filter()->all())->get(['id', 'name', 'social_name'])->keyBy('id');
        $insurers = Insurer::query()->whereKey(array_filter(array_map(fn (BillingAccount $account) => $account->insurer_id, $accounts)))->pluck('name', 'id');
        // A complementary account's stay is its account's.
        $originals = BillingAccount::query()->whereKey(array_filter(array_map(fn (BillingAccount $account) => $account->complement_of_id, $accounts)))
            ->get(['id', 'appointment_id', 'emergency_episode_id'])->keyBy('id');
        $stayOf = fn (BillingAccount $account) => $account->complement_of_id === null ? $account : $originals->get($account->complement_of_id);
        $admitted = Admission::query()->whereIn('emergency_episode_id', array_filter(array_map(fn (BillingAccount $account) => $stayOf($account)?->emergency_episode_id, $accounts)))
            ->pluck('emergency_episode_id')->flip();

        return array_map(function (BillingAccount $account) use ($patients, $primaries, $insurers, $admitted, $timezone, $stayOf) {
            $patient = $patients->get($account->patient_id);
            // A registration merged later: the primary one's name.
            $patient = $patient?->merged_into_id !== null ? $primaries->get($patient->merged_into_id) : $patient;

            return [
                'id' => $account->id,
                'patient' => $patient === null ? null : ($patient->social_name ?? $patient->name),
                'stay' => ($stay = $stayOf($account))?->appointment_id !== null ? 'Consulta' : ($admitted->has($stay?->emergency_episode_id) ? 'Internação' : 'Urgência'),
                'complementOf' => $account->complement_of_id,
                'closedAt' => $account->closed_at?->setTimezone($timezone)->format('d/m/Y H:i'),
                'modality' => $account->modality === null ? null : self::MODALITIES[$account->modality],
                'insurer' => $account->insurer_id === null ? null : $insurers->get($account->insurer_id),
                'status' => $account->status,
                'openedAt' => $account->opened_at->setTimezone($timezone)->format('d/m/Y'),
                'items' => (int) $account->getAttribute('items_count'),
                'pending' => (int) $account->getAttribute('pending_count'),
                'totalCents' => (int) $account->getAttribute('total_cents'),
            ];
        }, $accounts);
    }
}
