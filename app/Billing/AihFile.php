<?php

namespace App\Billing;

use App\Clinical\AihCodes;
use App\Clinical\Municipality;
use App\Clinical\PatientIdentity;
use App\Models\BillingAccount;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Step 6b.6b2b2 (ADR-0011): the SISAIH01 import file of a unit (layout 07/2026, resources/sus/aih/): one record of 1800
 * positions per AIH (type 01, AIH principal), ended by CR+LF, upper-case ASCII. Not applicable fields are zeros
 * (numbers) or blanks (text); the secondary procedures (up to 9, block 009) are the account's items the SIGTAP registers
 * as secondary or special in the AIH (user's decision, 28/10/2026), each with who did it.
 */
final class AihFile
{
    private const SECONDARY = 9;

    /** IN_EQUIPE of a surgery's team member. */
    private const TEAM = ['surgeon' => '1', 'assistant' => '2', 'anesthetist' => '6'];

    /**
     * The unit's closed AIH accounts (the stay's, not a complement) that no file presented yet, or whose latest
     * presentation the manager rejected (step 6b.7a), oldest first.
     *
     * @return Collection<int, BillingAccount>
     */
    public static function accounts(int $unitId): Collection
    {
        return BillingAccount::query()->where('unit_id', $unitId)->where('modality', 'sus')->where('status', 'closed')->whereNull('complement_of_id')
            ->where(fn ($query) => $query->whereNotIn('id', DB::table('sus_aih_file_accounts')->select('billing_account_id'))->orWhereRaw('sus_aih_rejected(id)'))
            ->orderBy('id')->get()
            ->filter(fn (BillingAccount $account) => SusRules::instrument($account) === 'aih')->values();
    }

    /**
     * The file's text, or what keeps it from being generated. An AIH discharged after the competence waits, and so does
     * a rejected one until a competence after the one it was presented in.
     *
     * @param  Collection<int, BillingAccount>  $accounts
     * @return array{text: string, accounts: list<int>, problems: list<string>}
     */
    public static function build(Unit $unit, string $competence, Collection $accounts): array
    {
        $problems = [];
        $timezone = $unit->timezone ?? 'UTC';
        $director = $unit->clinical_director_id === null ? null : DB::table('professionals')->where('id', $unit->clinical_director_id)->value('cns');
        foreach ([[$unit->cnes, 'o CNES'], [$unit->aih_issuer, 'o órgão emissor da AIH'], [Municipality::sus($unit->municipality), 'o município'], [$director, 'o diretor clínico com Cartão Nacional de Saúde']] as [$value, $label]) {
            if ($value === null) {
                $problems[] = "A unidade não tem {$label} (tela Unidades).";
            }
        }
        $records = [];
        $presented = [];
        $before = DB::table('sus_aih_file_accounts')->join('sus_aih_files', 'sus_aih_files.id', '=', 'sus_aih_file_accounts.sus_aih_file_id')
            ->whereIn('billing_account_id', $accounts->pluck('id')->all())->groupBy('billing_account_id')->selectRaw("billing_account_id, to_char(max(competence), 'YYYY-MM') AS competence")
            ->pluck('competence', 'billing_account_id');
        foreach ($accounts as $account) {
            $admission = DB::table('admissions')->where('emergency_episode_id', $account->emergency_episode_id)->first(['id', 'patient_id', 'responsible_professional_id', 'admitted_at', 'requested_at', 'discharged_at']);
            if ($admission?->discharged_at === null || Carbon::parse($admission->discharged_at)->setTimezone($timezone)->format('Y-m') > $competence
                || ($before->get($account->id) ?? '') >= $competence) {
                continue;
            }
            $presented[] = $account->id;
            $fail = function (string $message) use (&$problems, $account) {
                $problems[] = "Conta {$account->id}: {$message}.";
            };
            $patient = DB::table('patients')->where('id', $admission->patient_id)->first();
            $report = DB::table('aih_requests')->where('admission_id', $admission->id)->orderByDesc('version')->first(['procedure_code', 'professional_id']);
            $principal = DB::table('aih_principals')->where('billing_account_id', $account->id)->orderByDesc('id')->value('procedure_code');
            $requested = $report->procedure_code ?? $principal;
            $cns = fn (?int $id) => $id === null ? null : DB::table('professionals')->where('id', $id)->value('cns');
            $requester = $cns($report->professional_id ?? $admission->responsible_professional_id);
            $responsible = $cns($admission->responsible_professional_id);
            $cid = collect(SusRules::diagnoses($account))->firstWhere('principal', true)['code'] ?? null;
            $municipality = $patient->address_municipality === null ? null : Municipality::all()[$patient->address_municipality] ?? null;
            $patientCns = $patient->cns !== null && PatientIdentity::validCns($patient->cns) ? $patient->cns : null;
            foreach ([
                [$account->aih_number === null, 'sem o número da AIH'],
                [$account->aih_authorizer_cns === null, 'sem o CNS do médico autorizador'],
                [$account->aih_bed_specialty === null || ! isset(AihCodes::bedSpecialties()[$account->aih_bed_specialty]), 'sem a especialidade do leito'],
                [$account->aih_exit_reason === null, 'sem o motivo de saída'],
                [$principal === null, 'sem o procedimento principal'],
                [$requester === null || $responsible === null, 'o médico solicitante ou o responsável não tem o Cartão Nacional de Saúde'],
                [$cid === null, 'sem o CID principal da alta'],
                [$patient->birth_date === null, 'o paciente não tem data de nascimento'],
                [$patient->race_color === null, 'o paciente não tem raça/cor'],
                [$patient->race_color === '05' && $patient->ethnicity === null, 'o paciente indígena não tem etnia'],
                // The database checks only the format: the code is checked in the official table here (6b.6b2a review).
                [! isset(AihCodes::nationalities()[$patient->nationality ?? '']), 'o paciente não tem nacionalidade da tabela oficial'],
                [! isset(AihCodes::streetTypes()[$patient->address_street_type ?? '']), 'o paciente não tem tipo de logradouro da tabela oficial'],
                [$municipality === null || in_array(null, [$patient->address_street, $patient->address_number, $patient->address_district], true), 'o paciente não tem o endereço completo'],
            ] as [$missing, $message]) {
                if ($missing) {
                    $fail($message);
                }
            }
            $secondary = self::secondary($account, $unit, (string) $principal, $fail);
            if (count($secondary) > self::SECONDARY) {
                $fail('mais de '.self::SECONDARY.' procedimentos secundários (o SISAIH01 aceita até '.self::SECONDARY.' por AIH)');
            }
            if ($problems !== []) {
                continue;
            }
            $date = fn (?string $at) => $at === null ? null : Carbon::parse($at)->setTimezone($timezone)->format('Ymd');
            $phone = (string) preg_replace('/\D/', '', (string) $patient->phone);
            $phone = strlen($phone) === 13 && str_starts_with($phone, '55') ? substr($phone, 2) : $phone;
            $phone = in_array(strlen($phone), [10, 11], true) ? $phone : null;
            // The emission date of the AIH: the day billing recorded the authorized number.
            $issued = DB::table('audit_events')->where('action', 'billing_account.aih_number_recorded')->where('subject_id', $account->id)
                ->where('subject_type', $account->getMorphClass())->orderByDesc('id')->value('occurred_at');
            $records[] = self::record([
                // Lot fields: zeros (filled by the SISAIH01); the presentation competence.
                self::num(0, 8), self::num(0, 3), str_replace('-', '', $competence), self::num(0, 3),
                self::alpha($unit->aih_issuer, 10), self::num($unit->cnes, 7), self::num(Municipality::sus($unit->municipality), 6),
                self::num($account->aih_number, 13), '01', self::num($account->aih_bed_specialty, 2), self::num(0, 45),
                // Hospital modality; no AIH 5, next or previous AIH.
                '02', self::num(0, 3), self::num(0, 13), self::num(0, 13),
                self::num($date($issued) ?? $date($admission->admitted_at ?? $admission->requested_at), 8), self::num($date($admission->admitted_at ?? $admission->requested_at), 8),
                self::num($date($admission->discharged_at), 8), self::num($requested, 10), $requested === $principal ? '2' : '1', self::num($principal, 10),
                self::num(SusRules::aihCharacter($account), 2), self::num($account->aih_exit_reason, 2),
                // Requester, responsible doctor, clinical director and authorizer, by the CNS.
                '2', self::num($requester, 15), '2', self::num($responsible, 15), '2', self::num($director, 15), '2', self::num($account->aih_authorizer_cns, 15),
                self::alpha($cid, 4), self::num(0, 4),
                self::num($patient->cpf, 11), self::alpha($patient->name, 70), self::num(str_replace('-', '', (string) $patient->birth_date), 8),
                match ($patient->sex) {
                    'female' => 'F', 'male' => 'M', default => 'I'
                }, self::num($patient->race_color, 2),
                self::alpha($patient->mother_name, 70), self::alpha($patient->guardian_name, 70),
                // Document type 5, ignored (the record keeps CPF and CNS in their own fields).
                '5', $patient->race_color === '05' ? self::num($patient->ethnicity, 4) : '0000',
                self::num(0, 5), ' ', '0', self::num($patientCns, 15),
                self::num($patient->nationality, 3), self::num($patient->address_street_type, 3), self::alpha($patient->address_street, 50),
                self::alpha($patient->address_number, 7), self::alpha($patient->address_complement, 15), self::alpha($patient->address_district, 30),
                self::num(Municipality::sus($patient->address_municipality), 6), self::alpha($municipality[1] ?? null, 2), self::num($patient->address_zip, 8),
                // The record number: the patient's registration.
                self::num($patient->id, 15), self::num(0, 4), self::num(0, 4),
                implode('', array_pad($secondary, self::SECONDARY, self::num(0, 79))),
                // Filler, budget source, team (INE), neonatal ICU, employment, birth and sterilization data: not applicable.
                self::num(0, 7), self::num(0, 2), self::num(0, 10), '0', self::num(0, 4), '0', self::num(0, 14), self::num(0, 6), self::num(0, 3), '0',
                self::num(0, 5), self::num(0, 10), self::num(0, 2), '0', self::blank(4), self::num(0, 2), self::num(0, 2),
                // High-risk pregnancy: 1, no (0 is yes in the layout).
                '1',
                self::blank(35), self::num(0, 12), self::blank(32),
                self::num($phone === null ? null : substr($phone, 0, 2), 2), self::num($phone === null ? null : substr($phone, 2), 9), self::blank(50),
                // Secondary diagnoses: blank (their pre-existing or acquired class is not recorded).
                str_repeat(self::blank(4).'0', 9), self::num(0, 165),
            ]);
        }
        if ($records === [] && $problems === []) {
            $problems[] = 'Nenhuma conta de internação SUS fechada, com alta até esta competência, para apresentar nesta unidade.';
        }
        if ($problems !== []) {
            return ['text' => '', 'accounts' => [], 'problems' => array_values(array_unique($problems))];
        }

        return ['text' => implode('', $records), 'accounts' => $presented, 'problems' => []];
    }

    /**
     * The block 009 entries (79 positions each): the account's active items that the SIGTAP registers as secondary or
     * special in the AIH (04, 05), other than the principal, each with who did it.
     *
     * @param  callable(string): void  $fail
     * @return list<string>
     */
    private static function secondary(BillingAccount $account, Unit $unit, string $principal, callable $fail): array
    {
        $items = DB::table('billing_account_items')->where('billing_account_id', $account->id)->orderBy('performed_on')->orderBy('id')
            ->get(['id', 'origin_type', 'origin_id', 'code', 'quantity', 'performed_on']);
        $compensated = $items->where('origin_type', 'compensation')->pluck('origin_id')->flip();
        $entries = [];
        foreach ($items as $item) {
            if (in_array($item->origin_type, ['compensation', 'aih_principal'], true) || $compensated->has($item->id) || $item->code === null || $item->quantity <= 0 || $item->code === $principal) {
                continue;
            }
            // A date column: read as written (a timezone shift would move day 1 to the previous month; review finding).
            $day = substr((string) $item->performed_on, 0, 10);
            $procedure = SusRules::procedure($account->tenant_id, $item->code, $day);
            if ($procedure === null || array_intersect(SusRules::list($procedure->registers), ['04', '05']) === []) {
                continue;
            }
            $professional = BpaFile::professional($account, $item);
            $cbo = $professional === null ? null : SusRules::cbo((int) $professional->id, $account->unit_id);
            if ($professional?->cns === null || $cbo === null) {
                $fail("quem realizou {$item->code} não tem o Cartão Nacional de Saúde ou o CBO na função da unidade");

                continue;
            }
            $role = $item->origin_type === 'surgery' ? DB::table('surgery_team_members')->where('surgery_id', $item->origin_id)->where('professional_id', $professional->id)->whereNull('removed_at')->value('role') : null;
            $entries[] = '2'.self::num($professional->cns, 15).self::alpha($cbo, 6).(self::TEAM[$role] ?? '0')
                // The service provider: not applicable; the executor credited: the hospital, by the CNES.
                .'0'.self::num(0, 14).'5'.self::num($unit->cnes, 15)
                .self::num($item->code, 10).self::num((int) $item->quantity, 3).str_replace('-', '', substr($day, 0, 7)).'000000';
        }

        return $entries;
    }

    /**
     * @param  list<string>  $fields
     */
    private static function record(array $fields): string
    {
        $record = implode('', $fields);
        if (strlen($record) !== 1800) {
            throw new LogicException('An AIH record has '.strlen($record).' positions instead of 1800.');
        }

        return $record."\r\n";
    }

    private static function num(int|string|null $value, int $length): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) > $length) {
            throw new LogicException("An AIH number has more than {$length} digits.");
        }

        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    /** Upper-case letters, digits and the layout's punctuation (slash, parentheses, hyphen, comma, period). */
    private static function alpha(?string $value, int $length): string
    {
        $text = strtoupper(Str::ascii((string) $value));

        return str_pad(substr((string) preg_replace('/[^A-Z0-9 .,\/()-]/', '', $text), 0, $length), $length);
    }

    private static function blank(int $length): string
    {
        return str_repeat(' ', $length);
    }
}
