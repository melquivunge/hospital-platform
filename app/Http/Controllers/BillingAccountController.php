<?php

namespace App\Http\Controllers;

use App\Clinical\ClinicalAccess;
use App\Models\Admission;
use App\Models\BillingAccount;
use App\Models\BillingAccountItem;
use App\Models\Insurer;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\RoleAssignment;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

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

    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(self::unitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $pendingOnly = $request->boolean('pending');
        $accounts = self::withTotals()->where('unit_id', $unit->id)->where('status', 'open')
            ->when($pendingOnly, fn ($query) => $query->whereHas('items', self::pending(...)))
            ->orderByDesc('opened_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();

        return Inertia::render('billing-accounts/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'pendingOnly' => $pendingOnly,
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

        return Inertia::render('billing-accounts/show', [
            'account' => [
                ...self::describe([$account], $timezone)[0],
                'card' => $account->insurance_card_number,
                'cardValidUntil' => $account->insurance_card_valid_until?->format('d/m/Y'),
            ],
            'items' => BillingAccountItem::query()->where('billing_account_id', $account->id)->orderBy('performed_on')->orderBy('id')->get()
                ->map(fn (BillingAccountItem $item) => [
                    'id' => $item->id,
                    'day' => $item->performed_on->format('d/m/Y'),
                    'origin' => $item->origin_type,
                    'code' => $item->code,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unitPriceCents' => $item->unit_price_cents,
                    'needsAuthorization' => $item->needs_authorization,
                ])->values(),
        ]);
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
     * Pending: an item without a price, or one the insurer's contract authorizes (recorded from step 6.3).
     *
     * @param  Builder<BillingAccountItem>  $items
     */
    private static function pending(Builder $items): void
    {
        $items->where(fn (Builder $item) => $item->whereNull('unit_price_cents')->orWhere('needs_authorization', true));
    }

    /**
     * The units where the membership has the Billing role.
     *
     * @return list<int>
     */
    public static function unitIds(?Membership $membership): array
    {
        if ($membership === null || $membership->revoked_at !== null) {
            return [];
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
        $admitted = Admission::query()->whereIn('emergency_episode_id', array_filter(array_map(fn (BillingAccount $account) => $account->emergency_episode_id, $accounts)))
            ->pluck('emergency_episode_id')->flip();

        return array_map(function (BillingAccount $account) use ($patients, $primaries, $insurers, $admitted, $timezone) {
            $patient = $patients->get($account->patient_id);
            // A registration merged later: the primary one's name.
            $patient = $patient?->merged_into_id !== null ? $primaries->get($patient->merged_into_id) : $patient;

            return [
                'id' => $account->id,
                'patient' => $patient === null ? null : ($patient->social_name ?? $patient->name),
                'stay' => $account->appointment_id !== null ? 'Consulta' : ($admitted->has($account->emergency_episode_id) ? 'Internação' : 'Urgência'),
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
