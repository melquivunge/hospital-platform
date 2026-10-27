<?php

namespace App\Models;

use App\Billing\TissLotXml;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A lot of TISS guides (step 6.5b1, ADR-0010): one insurer, one unit, one competence (the month the accounts closed,
 * in the unit's time zone) and one guide type; each closed insurance account is a guide, up to 100 per lot. An account
 * is in at most one active lot; cancelling the lot releases its accounts.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $unit_id
 * @property int $insurer_id
 * @property Carbon $competence first day of the month
 * @property string $guide_type sp_sadt | hospitalization
 * @property string $tiss_version
 * @property string $status generated | cancelled
 * @property int $guide_count
 * @property int $total_cents
 * @property int $created_by_user_id
 * @property Carbon $created_at
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 * @property string|null $file_path step 6.5b2a: the TISS file, written once
 * @property string|null $file_sha256
 * @property string|null $file_hash the epilogue hash
 * @property Carbon|null $file_generated_at
 * @property int|null $file_generated_by_user_id
 */
class BillingLot extends Model
{
    use BelongsToTenant;

    public const TISS_VERSION = '4.03.00';

    public const MAX_GUIDES = 100;

    public const GUIDE_TYPES = ['sp_sadt' => 'SP/SADT', 'hospitalization' => 'Resumo de internação'];

    public $timestamps = false;

    /**
     * Step 6.5c1: the glosa codes and terms of TUSS table 38 (resources/tiss/4.03.00/glosas.json).
     *
     * @return array<string, string>
     */
    public static function glosaCodes(): array
    {
        static $codes;

        return $codes ??= json_decode((string) file_get_contents(resource_path('tiss/4.03.00/glosas.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The closed insurance accounts of a unit outside any active lot, with each one's insurer, competence, guide type
     * and total: what a lot can take. A complementary account takes its stay's guide type.
     */
    public static function ready(int $unitId, string $timezone): Builder
    {
        // The tenant filters repeat what RLS already enforces (defense in depth).
        $admitted = 'EXISTS (SELECT 1 FROM admissions ad JOIN billing_accounts stay ON stay.id = coalesce(a.complement_of_id, a.id) AND stay.tenant_id = a.tenant_id
            WHERE ad.emergency_episode_id = stay.emergency_episode_id AND ad.tenant_id = a.tenant_id)';
        $total = '(SELECT coalesce(sum(quantity * unit_price_cents), 0) FROM billing_account_items i WHERE i.billing_account_id = a.id AND i.tenant_id = a.tenant_id)';

        return DB::table('billing_accounts as a')
            ->where('a.unit_id', $unitId)->where('a.status', 'closed')->where('a.modality', 'insurance')
            // ponytail: a complementary account left negative by compensations is no guide (TISS has no negative value); it waits for the return and appeal of step 6.5c.
            ->whereRaw("{$total} >= 0")
            ->whereNotExists(fn ($lots) => $lots->from('billing_lot_accounts')->whereColumn('billing_lot_accounts.billing_account_id', 'a.id')
                ->whereColumn('billing_lot_accounts.tenant_id', 'a.tenant_id')->whereNull('released_at'))
            ->select('a.id', 'a.insurer_id', 'a.closed_at')
            ->selectRaw("date_trunc('month', a.closed_at AT TIME ZONE ?)::date AS competence", [$timezone])
            ->selectRaw("CASE WHEN {$admitted} THEN 'hospitalization' ELSE 'sp_sadt' END AS guide_type")
            ->selectRaw("{$total} AS total_cents");
    }

    /**
     * Generates the lot of the oldest ready accounts (up to 100) for the insurer, competence and guide type; null when
     * none is ready. A concurrent lot taking the same accounts makes this one fail on the unique index (the caller
     * reports it), so an account never lands in two active lots.
     */
    public static function generate(Unit $unit, int $insurerId, CarbonImmutable $competence, string $guideType, User $user): ?self
    {
        $month = $competence->startOfMonth()->toDateString();

        return DB::transaction(function () use ($unit, $insurerId, $month, $guideType, $user) {
            $accounts = DB::query()->fromSub(self::ready($unit->id, $unit->timezone ?? 'UTC'), 'ready')
                ->where('insurer_id', $insurerId)->where('competence', $month)->where('guide_type', $guideType)
                ->orderBy('closed_at')->orderBy('id')->limit(self::MAX_GUIDES)->get();
            if ($accounts->isEmpty()) {
                return null;
            }
            $lot = new self;
            $lot->forceFill([
                'unit_id' => $unit->id, 'insurer_id' => $insurerId, 'competence' => $month, 'guide_type' => $guideType,
                'tiss_version' => self::TISS_VERSION, 'guide_count' => $accounts->count(), 'total_cents' => (int) $accounts->sum('total_cents'),
                'created_by_user_id' => $user->id, 'created_at' => now(),
            ])->save();
            DB::table('billing_lot_accounts')->insert($accounts->map(fn ($account) => [
                'tenant_id' => $lot->tenant_id, 'billing_lot_id' => $lot->id, 'billing_account_id' => $account->id,
            ])->all());
            AuditEvent::record('billing_lot.generated', $lot->tenant_id, $lot, [
                'insurer_id' => $insurerId, 'guide_type' => $guideType, 'guide_count' => $lot->guide_count, 'total_cents' => $lot->total_cents,
            ]);

            return $lot;
        });
    }

    /**
     * Cancels a generated lot with a reason and releases its accounts; returns why it could not (already cancelled, or
     * the insurer's return is recorded), null when cancelled.
     */
    public function cancel(string $reason, User $user): ?string
    {
        return DB::transaction(function () use ($reason, $user) {
            $lot = self::query()->lockForUpdate()->findOrFail($this->id);
            if ($lot->status !== 'generated') {
                return 'Este lote já foi cancelado.';
            }
            if (DB::table('billing_item_returns')->where('billing_lot_id', $lot->id)->exists()) {
                return 'O retorno da operadora já foi registrado: o lote não é mais cancelado.';
            }
            $lot->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $user->id, 'cancel_reason' => $reason])->save();
            DB::table('billing_lot_accounts')->where('billing_lot_id', $lot->id)->whereNull('released_at')->update(['released_at' => now()]);
            AuditEvent::record('billing_lot.cancelled', $lot->tenant_id, $lot, ['guide_count' => $lot->guide_count]);

            return null;
        });
    }

    /**
     * Step 6.5b2a: writes the lot's TISS file once, validated against the official schema, with its SHA-256. Returns
     * why it could not (what is missing, or that it is cancelled or already written); empty when written.
     *
     * @return list<string>
     */
    public function writeFile(User $user): array
    {
        return DB::transaction(function () use ($user) {
            // The lock makes a second request wait and then see the file.
            $lot = self::query()->lockForUpdate()->findOrFail($this->id);
            if ($lot->status !== 'generated') {
                return ['Este lote foi cancelado.'];
            }
            if ($lot->file_path !== null) {
                return ['O arquivo deste lote já foi gerado.'];
            }
            $tiss = new TissLotXml($lot);
            if (($problems = $tiss->problems()) !== []) {
                return $problems;
            }
            try {
                ['xml' => $xml, 'hash' => $hash] = $tiss->build();
            } catch (\RuntimeException $failure) {
                // The schema refused what the checks let through: a bug to fix, never a file to send.
                report($failure);

                return ['O arquivo não passou na validação do padrão TISS. Avise o suporte com o número do lote.'];
            }
            $path = "tiss/lots/{$lot->tenant_id}/{$lot->id}.xml";
            Storage::disk('local')->put($path, $xml);
            $sha256 = hash('sha256', $xml);
            $lot->forceFill(['file_path' => $path, 'file_sha256' => $sha256, 'file_hash' => $hash, 'file_generated_at' => now(), 'file_generated_by_user_id' => $user->id])->save();
            AuditEvent::record('billing_lot.file_generated', $lot->tenant_id, $lot, ['guide_count' => $lot->guide_count, 'sha256' => $sha256]);

            return [];
        });
    }

    /**
     * Step 6.5c1: the items its guides carry (each account's items but compensations and the items they undo), with
     * the billed value; the return is recorded per item.
     *
     * @return Collection<int, object>
     */
    public function guideItems(): Collection
    {
        return DB::table('billing_account_items as i')
            ->join('billing_lot_accounts as la', 'la.billing_account_id', '=', 'i.billing_account_id')
            ->where('la.billing_lot_id', $this->id)->whereNull('la.released_at')->where('i.origin_type', '<>', 'compensation')
            ->whereNotExists(fn ($undone) => $undone->from('billing_account_items as c')->whereColumn('c.billing_account_id', 'i.billing_account_id')
                ->where('c.origin_type', 'compensation')->whereColumn('c.origin_id', 'i.id'))
            ->orderBy('i.billing_account_id')->orderBy('i.performed_on')->orderBy('i.id')
            ->select('i.id', 'i.billing_account_id', 'i.code', 'i.name', 'i.quantity')->selectRaw('i.quantity * coalesce(i.unit_price_cents, 0) AS billed_cents')
            ->get()->each(fn ($item) => $item->billed_cents = (int) $item->billed_cents);
    }

    /**
     * Step 6.5c1: records the insurer's return of the sent lot, once, for every guide item: what is not glossed is paid.
     * Returns why it could not; empty when recorded.
     *
     * @param  array<int, array{cents: int, code: string, reason: string|null}>  $glosas  by item id
     * @return list<string>
     */
    public function recordReturn(array $glosas, User $user): array
    {
        return DB::transaction(function () use ($glosas, $user) {
            // The lock makes a second request wait and then see the return.
            $lot = self::query()->lockForUpdate()->findOrFail($this->id);
            if ($lot->status !== 'generated' || $lot->file_path === null) {
                return ['O retorno é registrado num lote enviado (com o arquivo TISS gerado).'];
            }
            if (DB::table('billing_item_returns')->where('billing_lot_id', $lot->id)->exists()) {
                return ['O retorno deste lote já foi registrado.'];
            }
            $items = $lot->guideItems();
            // The guides' items add up to the lot (compensations cancel their items): the conciliation holds.
            if ($items->isEmpty() || $items->sum('billed_cents') !== $lot->total_cents) {
                report(new \RuntimeException("Lot {$lot->id}: the guide items do not add up to the lot total."));

                return ['Os itens das guias não somam o total do lote. Avise o suporte com o número do lote.'];
            }
            $billed = $items->pluck('billed_cents', 'id');
            foreach ($glosas as $id => $glosa) {
                if (! $billed->has($id)) {
                    return ['Um item glosado não é deste lote. Atualize a página e confira.'];
                }
                if ($glosa['cents'] > $billed[$id]) {
                    return ['A glosa de um item passa do valor cobrado.'];
                }
            }
            $now = now();
            $rows = $items->map(function ($item) use ($glosas, $lot, $user, $now) {
                $glosa = $glosas[$item->id] ?? null;
                $glossed = $glosa['cents'] ?? 0;

                return [
                    'tenant_id' => $lot->tenant_id, 'billing_lot_id' => $lot->id, 'billing_account_item_id' => $item->id,
                    'billed_cents' => $item->billed_cents, 'paid_cents' => $item->billed_cents - $glossed, 'glossed_cents' => $glossed,
                    'glosa_code' => $glossed > 0 ? $glosa['code'] : null, 'glosa_reason' => $glossed > 0 ? $glosa['reason'] : null,
                    'recorded_by_user_id' => $user->id, 'recorded_at' => $now,
                ];
            });
            DB::table('billing_item_returns')->insert($rows->all());
            AuditEvent::record('billing_lot.return_recorded', $lot->tenant_id, $lot, [
                'paid_cents' => $rows->sum('paid_cents'), 'glossed_cents' => $rows->sum('glossed_cents'), 'glossed_items' => $rows->where('glossed_cents', '>', 0)->count(),
            ]);

            return [];
        });
    }

    protected function casts(): array
    {
        return ['competence' => 'date', 'created_at' => 'datetime', 'cancelled_at' => 'datetime', 'file_generated_at' => 'datetime'];
    }
}
