<?php

namespace App\Billing;

use App\Clinical\PatientIdentity;
use App\Models\BillingAccount;
use App\Models\Diagnosis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Step 6b.4 (ADR-0011): the SUS rules of an account. Its instrument is the AIH when the episode became an admission,
 * else the BPA; each item is the SIGTAP procedure of its billable item, valued by the competence of the day it was
 * done (SA in the BPA; SH plus SP in the AIH). A procedure the SIGTAP does not register in that instrument is part of
 * the package and is not billed apart; an item without a SIGTAP code is not billed in the SUS at all (the network
 * admin decides what the SUS pays by giving the code).
 */
final class SusRules
{
    /** The SIGTAP registration instruments: BPA-C and BPA-I; AIH principal, special and secondary. */
    private const REGISTERS = ['bpa' => ['01', '02'], 'aih' => ['03', '04', '05']];

    public static function instrument(BillingAccount $stay): string
    {
        return $stay->emergency_episode_id !== null && DB::table('admissions')->where('emergency_episode_id', $stay->emergency_episode_id)->exists() ? 'aih' : 'bpa';
    }

    /** The network's SIGTAP procedure of the competence of a day (YYYY-MM-DD), or null when it is not imported. */
    public static function procedure(int $tenantId, string $code, string $day): ?stdClass
    {
        return DB::table('sigtap_procedures as p')->join('sigtap_competences as c', 'c.id', '=', 'p.sigtap_competence_id')
            ->where('c.tenant_id', $tenantId)->where('c.competence', substr($day, 0, 7).'-01')->where('p.code', $code)
            ->first(['p.code', 'p.name', 'p.sh_cents', 'p.sa_cents', 'p.sp_cents', 'p.registers', 'p.cids', 'p.cbos']);
    }

    /** Whether the procedure is registered in the instrument (else it is in the package). */
    public static function registered(stdClass $procedure, string $instrument): bool
    {
        return array_intersect(self::list($procedure->registers), self::REGISTERS[$instrument]) !== [];
    }

    public static function price(stdClass $procedure, string $instrument): int
    {
        return $instrument === 'aih' ? (int) $procedure->sh_cents + (int) $procedure->sp_cents : (int) $procedure->sa_cents;
    }

    /**
     * Step 6b.6a2 (user's decision, 28/10/2026): a BPA line is individualized (BPA-I, instrument 02) whenever the
     * procedure accepts it, consolidated (BPA-C, 01) only when that is all it accepts.
     */
    public static function individual(stdClass $procedure): bool
    {
        return in_array('02', self::list($procedure->registers), true);
    }

    /** Whether the procedure may be an AIH's principal (instrument 03). */
    public static function principal(stdClass $procedure): bool
    {
        return in_array('03', self::list($procedure->registers), true);
    }

    /**
     * The day (YYYY-MM-DD, in the unit's zone) the admission of a SUS stay began: its AIH's competence. Still waiting for
     * a bed it is not known yet (null); discharged without ever taking one, it is the day it was requested.
     */
    public static function admissionDay(BillingAccount $stay, string $timezone): ?string
    {
        $admission = DB::table('admissions')->where('emergency_episode_id', $stay->emergency_episode_id ?? 0)->first(['admitted_at', 'requested_at', 'status']);
        $at = $admission->admitted_at ?? ($admission?->status === 'discharged' ? $admission->requested_at : null);

        return $at === null ? null : Carbon::parse($at)->setTimezone($timezone)->toDateString();
    }

    /**
     * Whether the stay's current principal procedure is in the money: its active item, or a care record's item that
     * billed the same procedure.
     */
    public static function principalBilled(BillingAccount $stay): bool
    {
        $current = DB::table('aih_principals')->where('billing_account_id', $stay->id)->orderByDesc('id')->first(['id', 'procedure_code']);
        if ($current === null) {
            return false;
        }
        $items = DB::table('billing_account_items')->where('billing_account_id', $stay->id)->get(['id', 'origin_type', 'origin_id', 'code', 'quantity']);
        $compensated = $items->where('origin_type', 'compensation')->pluck('origin_id')->flip();

        return $items->contains(fn ($item) => ! $compensated->has($item->id) && $item->quantity > 0 && (
            ($item->origin_type === 'aih_principal' && $item->origin_id === $current->id)
            || (! in_array($item->origin_type, ['manual', 'compensation', 'aih_principal'], true) && $item->code === $current->procedure_code)
        ));
    }

    /**
     * Step 6b.5b2: the AIH's principal procedure on the open stay account, under its lock. It starts as the request
     * report's and follows the report's new versions until billing changes it with a reason (aih_principals). The
     * current one is billed once, SH plus SP of the admission day's competence, unless a care record already billed
     * that procedure (the surgery done); any other principal item is compensated by who changed it, with why.
     */
    public static function syncPrincipal(BillingAccount $stay, string $timezone): void
    {
        $admissionId = DB::table('admissions')->where('emergency_episode_id', $stay->emergency_episode_id ?? 0)->value('id');
        if ($admissionId === null) {
            return;
        }
        $current = DB::table('aih_principals')->where('billing_account_id', $stay->id)->orderByDesc('id')->first();
        $report = DB::table('aih_requests')->where('admission_id', $admissionId)->orderByDesc('version')->first();
        // The report leads until billing changes the procedure; a new version with the same procedure changes nothing.
        if ($report !== null && ($current === null || ($current->aih_request_id !== null && $current->aih_request_id !== $report->id && $current->procedure_code !== $report->procedure_code))) {
            DB::table('aih_principals')->insert([
                'tenant_id' => $stay->tenant_id, 'billing_account_id' => $stay->id, 'procedure_code' => $report->procedure_code,
                'procedure_name' => $report->procedure_name, 'aih_request_id' => $report->id, 'user_id' => $report->author_user_id, 'recorded_at' => now(),
            ]);
            $current = DB::table('aih_principals')->where('billing_account_id', $stay->id)->orderByDesc('id')->first();
        }
        if ($current === null) {
            return;
        }
        $items = DB::table('billing_account_items')->where('billing_account_id', $stay->id)->get(['id', 'origin_type', 'origin_id', 'code', 'name', 'quantity', 'unit_price_cents', 'billing_item_id']);
        $compensated = $items->where('origin_type', 'compensation')->pluck('origin_id')->flip();
        $active = $items->reject(fn ($item) => $item->origin_type === 'compensation' || $compensated->has($item->id));
        $done = $active->whereNotIn('origin_type', ['manual', 'aih_principal'])->where('quantity', '>', 0)->contains('code', $current->procedure_code);
        $wanted = $done ? null : $current->id;
        $why = $done ? 'O procedimento principal já está na conta pelo registro assistencial.'
            : ($current->reason ?? 'Novo laudo da AIH (versão '.DB::table('aih_requests')->where('id', $current->aih_request_id)->value('version').').');
        $today = now($timezone)->toDateString();
        foreach ($active->where('origin_type', 'aih_principal')->where('origin_id', '<>', $wanted) as $item) {
            DB::table('billing_account_items')->insertOrIgnore([
                'tenant_id' => $stay->tenant_id, 'billing_account_id' => $stay->id, 'origin_type' => 'compensation', 'origin_id' => $item->id,
                'billing_item_id' => $item->billing_item_id, 'code' => $item->code, 'name' => $item->name, 'quantity' => -$item->quantity,
                'unit_price_cents' => $item->unit_price_cents, 'needs_authorization' => false, 'performed_on' => $today,
                'reason' => mb_substr($why, 0, 500), 'author_user_id' => $current->user_id, 'created_at' => now(),
            ]);
        }
        $day = self::admissionDay($stay, $timezone);
        if ($wanted === null || $day === null || $items->where('origin_type', 'aih_principal')->contains('origin_id', $wanted)) {
            return;
        }
        // Without the competence imported it waits, as every SUS item.
        $procedure = self::procedure($stay->tenant_id, $current->procedure_code, $day);
        if ($procedure === null) {
            return;
        }
        DB::table('billing_account_items')->insertOrIgnore([
            'tenant_id' => $stay->tenant_id, 'billing_account_id' => $stay->id, 'origin_type' => 'aih_principal', 'origin_id' => $wanted,
            'billing_item_id' => null, 'code' => $procedure->code, 'name' => $current->procedure_name, 'quantity' => 1,
            'unit_price_cents' => self::price($procedure, 'aih'), 'needs_authorization' => false, 'performed_on' => $day, 'created_at' => now(),
        ]);
    }

    /**
     * What keeps a SUS account from closing, in words for billing: items without the competence's value, the CID-10
     * (missing or not compatible with a procedure), the CBO of who attended, and the CNS or CPF of the patient and the
     * CNS of the professional that the BPA-I and the AIH carry.
     *
     * @return list<string>
     */
    public static function pending(BillingAccount $account): array
    {
        $stay = $account->complement_of_id === null ? $account : BillingAccount::query()->findOrFail($account->complement_of_id);
        $problems = [];
        $items = DB::table('billing_account_items')->where('billing_account_id', $account->id)->where('origin_type', '<>', 'compensation')->get(['origin_type', 'code', 'name', 'unit_price_cents', 'performed_on']);
        // Items wait for their competence's SIGTAP (BillingAccount::generate): the account's month must be imported.
        $month = substr((string) $stay->opened_at, 0, 7);
        if (! DB::table('sigtap_competences')->where('tenant_id', $account->tenant_id)->where('competence', "{$month}-01")->exists()) {
            $problems[] = 'A SIGTAP de '.substr($month, 5, 2).'/'.substr($month, 0, 4).' não foi importada: os procedimentos entram na conta depois da importação (tela Tabela SIGTAP).';
        }
        $diagnoses = self::diagnoses($stay);
        $principal = collect($diagnoses)->firstWhere('principal', true)['code'] ?? null;
        if ($principal === null) {
            $problems[] = 'Sem CID-10 principal: quem atendeu informa ao encerrar o atendimento ou na alta.';
        }
        $professional = DB::table('visits as v')->join('professionals as p', 'p.id', '=', 'v.professional_id')
            ->where($stay->appointment_id !== null ? 'v.appointment_id' : 'v.emergency_episode_id', $stay->appointment_id ?? $stay->emergency_episode_id)
            ->orderBy('v.id')->first(['p.id', 'p.name', 'p.cns']);
        $cbo = $professional === null ? null : DB::table('qualifications as q')->join('job_functions as f', 'f.id', '=', 'q.job_function_id')
            ->where('q.professional_id', $professional->id)->where('q.unit_id', $stay->unit_id)->where('q.status', 'approved')->whereNotNull('f.cbo')->orderByDesc('q.id')->value('f.cbo');
        // Step 6b.6a1: whether a line goes to the BPA-I (it carries the patient's race/color).
        $individual = false;
        foreach ($items->whereNotNull('code')->unique(fn ($item) => $item->code.$item->origin_type) as $item) {
            $procedure = self::procedure($account->tenant_id, $item->code, substr((string) $item->performed_on, 0, 10));
            if ($procedure === null) {
                continue;
            }
            $individual = $individual || self::individual($procedure);
            $cids = self::list($procedure->cids);
            if ($principal !== null && $cids !== [] && ! in_array($principal, $cids, true)) {
                $problems[] = "O CID {$principal} não é compatível com {$item->code} ({$item->name}) na SIGTAP.";
            }
            $cbos = self::list($procedure->cbos);
            // The CBO is of who attended: only the visit's own item (an exam or a surgery is someone else's).
            if ($item->origin_type === 'visit' && $cbos !== [] && ! in_array((string) $cbo, $cbos, true)) {
                $problems[] = 'A função de quem atendeu (CBO '.($cbo ?? 'ausente').") não é compatível com {$item->code} na SIGTAP.";
            }
        }
        $patient = DB::table('patients')->where('id', $stay->patient_id)->first(['cns', 'cpf', 'race_color']);
        // Step 6b.6a1 (user's decision, 28/10/2026): the BPA-I and the AIH carry the self-declared race/color.
        if ($patient->race_color === null && ($individual || self::instrument($stay) === 'aih')) {
            $problems[] = 'O paciente não tem raça/cor no cadastro (a recepção completa o cadastro): exigida no BPA-I e na AIH.';
        }
        if (($patient->cns === null || ! PatientIdentity::validCns($patient->cns)) && $patient->cpf === null) {
            $problems[] = 'O paciente não tem CNS nem CPF no cadastro (a recepção completa o cadastro).';
        }
        // Step 6b.5b2: the AIH has a principal procedure: the report's, or billing's change.
        if ($stay->status === 'open' && self::instrument($stay) === 'aih') {
            $principal = DB::table('aih_principals')->where('billing_account_id', $stay->id)->orderByDesc('id')->value('procedure_code');
            $day = self::admissionDay($stay, $timezone = DB::table('units')->where('id', $stay->unit_id)->value('timezone') ?? 'UTC');
            if ($principal === null) {
                $problems[] = 'Sem procedimento principal da AIH: o médico emite o laudo na internação (ou o faturamento informa o procedimento).';
            } elseif ($day === null) {
                $problems[] = 'O procedimento principal entra na conta quando o paciente ocupar o leito (competência da internação).';
            } elseif (! self::principalBilled($stay)) {
                $problems[] = "O procedimento principal {$principal} não tem valor na SIGTAP de ".substr($day, 5, 2).'/'.substr($day, 0, 4).' (competência da internação): importe a competência ou troque o procedimento.';
            }
        }
        // Step 6b.5b1: the AIH closes with the number the manager authorized (on the stay's account). A stay closed before
        // the rule cannot take it any more: its complementary account is not held for it.
        if ($stay->status === 'open' && self::instrument($stay) === 'aih' && $stay->aih_number === null) {
            $problems[] = 'Sem o número da AIH autorizada: registre-o quando o gestor devolver o laudo autorizado.';
        }
        if ($professional !== null && $professional->cns === null) {
            $problems[] = "{$professional->name} não tem o Cartão Nacional de Saúde no cadastro (tela Profissionais).";
        }

        return array_values(array_unique($problems));
    }

    /**
     * The CID-10 codes of the stay: the discharge's when the episode became an admission and was discharged, else
     * the visit's close.
     *
     * @return list<array{code: string, name: string|null, principal: bool}>
     */
    public static function diagnoses(BillingAccount $stay): array
    {
        $admission = $stay->emergency_episode_id === null ? null : DB::table('admissions')->where('emergency_episode_id', $stay->emergency_episode_id)->value('id');
        if ($admission !== null && ($codes = Diagnosis::current('admission_id', (int) $admission)) !== []) {
            return $codes;
        }
        $visit = DB::table('visits')->where($stay->appointment_id !== null ? 'appointment_id' : 'emergency_episode_id', $stay->appointment_id ?? $stay->emergency_episode_id)->orderByDesc('id')->value('id');

        return $visit === null ? [] : Diagnosis::current('visit_id', (int) $visit);
    }

    /**
     * A Postgres text[] as read ("{a,b}").
     *
     * @return list<string>
     */
    public static function list(string $array): array
    {
        $inner = trim($array, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }
}
