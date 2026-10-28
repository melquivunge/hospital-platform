<?php

namespace App\Billing;

use App\Clinical\PatientIdentity;
use App\Models\BillingAccount;
use App\Models\Diagnosis;
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
            ->first(['p.code', 'p.sh_cents', 'p.sa_cents', 'p.sp_cents', 'p.registers', 'p.cids', 'p.cbos']);
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
        foreach ($items->whereNotNull('code')->unique(fn ($item) => $item->code.$item->origin_type) as $item) {
            $procedure = self::procedure($account->tenant_id, $item->code, substr((string) $item->performed_on, 0, 10));
            if ($procedure === null) {
                continue;
            }
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
        $patient = DB::table('patients')->where('id', $stay->patient_id)->first(['cns', 'cpf']);
        if (($patient->cns === null || ! PatientIdentity::validCns($patient->cns)) && $patient->cpf === null) {
            $problems[] = 'O paciente não tem CNS nem CPF no cadastro (a recepção completa o cadastro).';
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
