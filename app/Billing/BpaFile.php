<?php

namespace App\Billing;

use App\Clinical\Municipality;
use App\Clinical\PatientIdentity;
use App\Models\BillingAccount;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

/**
 * Step 6b.6a2 (ADR-0011): the BPA file of a unit, in the official export layout of the BPA Magnético (version 05.00,
 * resources/sus/bpa/): a header, the consolidated lines (BPA-C, 20 per sheet) and the individualized ones (BPA-I, 99
 * per sheet), each line ended by CR+LF, upper-case ASCII. A procedure goes to the BPA-I whenever the SIGTAP accepts it
 * there (user's decision, 28/10/2026). Each line carries the competence in which the procedure was done; the header,
 * the processing competence. The professional of a line is who did it: the visit's, the exam result's signer, the
 * surgery's surgeon (else the stay's visit's).
 */
final class BpaFile
{
    /** Free text of the header (cbc_versao): this system and its layout version. */
    public const VERSION = 'HOSPLAT050';

    private const C_PER_SHEET = 20;

    private const I_PER_SHEET = 99;

    /**
     * The unit's closed SUS accounts whose instrument is the BPA and that no file presented yet (user's decision,
     * 28/10/2026), oldest first.
     *
     * @return Collection<int, BillingAccount>
     */
    public static function accounts(int $unitId): Collection
    {
        return BillingAccount::query()->where('unit_id', $unitId)->where('modality', 'sus')->where('status', 'closed')
            ->whereNotIn('id', DB::table('sus_bpa_file_accounts')->select('billing_account_id'))->orderBy('id')->get()
            ->filter(fn (BillingAccount $account) => SusRules::instrument(self::stay($account)) === 'bpa')->values();
    }

    /**
     * The file's text, or what keeps it from being generated.
     *
     * @param  Collection<int, BillingAccount>  $accounts
     * @return array{text: string, lines: int, sheets: int, accounts: list<int>, problems: list<string>}
     */
    public static function build(Unit $unit, string $competence, Collection $accounts): array
    {
        $problems = [];
        foreach (['cnes' => 'o CNES', 'cnpj' => 'o CNPJ do prestador', 'sus_manager_name' => 'o gestor de destino', 'sus_manager_level' => 'a esfera do gestor'] as $field => $label) {
            if ($unit->getAttribute($field) === null) {
                $problems[] = "A unidade não tem {$label} (tela Unidades).";
            }
        }
        $consolidated = [];
        $individual = [];
        $presented = [];
        foreach ($accounts as $account) {
            $items = DB::table('billing_account_items')->where('billing_account_id', $account->id)->orderBy('performed_on')->orderBy('id')
                ->get(['id', 'origin_type', 'origin_id', 'code', 'quantity', 'performed_on']);
            $compensated = $items->where('origin_type', 'compensation')->pluck('origin_id')->flip();
            $items = $items->reject(fn ($item) => $item->origin_type === 'compensation' || $compensated->has($item->id) || $item->code === null || $item->quantity <= 0);
            // Nothing to present stays out (never marked presented); a procedure done after the competence waits for a
            // later file, so a line is never later than its header (review finding, 6b.6a2).
            if ($items->isEmpty() || substr((string) $items->max('performed_on'), 0, 7) > $competence) {
                continue;
            }
            $presented[] = $account->id;
            $stay = self::stay($account);
            $patient = DB::table('patients')->where('id', $stay->patient_id)->first(['name', 'birth_date', 'sex', 'cns', 'cpf', 'race_color', 'ethnicity', 'address_municipality']);
            $cid = collect(SusRules::diagnoses($stay))->firstWhere('principal', true)['code'] ?? null;
            foreach ($items as $item) {
                $day = substr((string) $item->performed_on, 0, 10);
                $procedure = SusRules::procedure($account->tenant_id, $item->code, $day);
                if ($procedure === null) {
                    $problems[] = "Conta {$account->id}: o procedimento {$item->code} não está na SIGTAP importada de ".substr($day, 5, 2).'/'.substr($day, 0, 4).'.';

                    continue;
                }
                $professional = self::professional($stay, $item);
                $cbo = $professional === null ? null : SusRules::cbo((int) $professional->id, $stay->unit_id);
                $age = $patient->birth_date === null ? 0 : (int) CarbonImmutable::parse($patient->birth_date)->diffInYears(CarbonImmutable::parse($day));
                $done = str_replace('-', '', substr($day, 0, 7));
                if ($cbo === null) {
                    $problems[] = "Conta {$account->id}: o profissional que realizou não tem CBO na função da unidade.";
                }
                if ($age < 0) {
                    $problems[] = "Conta {$account->id}: a data de nascimento do paciente é posterior ao atendimento.";
                }
                if (! SusRules::individual($procedure)) {
                    $key = "{$done}|{$cbo}|{$item->code}|{$age}";
                    $consolidated[$key] = ['cmp' => $done, 'cbo' => $cbo, 'code' => $item->code, 'age' => $age, 'quantity' => ($consolidated[$key]['quantity'] ?? 0) + (int) $item->quantity];

                    continue;
                }
                $cns = $patient->cns !== null && PatientIdentity::validCns($patient->cns) ? $patient->cns : null;
                foreach ([
                    [$professional?->cns === null, 'o profissional que realizou não tem o Cartão Nacional de Saúde'],
                    [$cns === null && $patient->cpf === null, 'o paciente não tem CNS nem CPF'],
                    [! in_array($patient->sex, ['female', 'male'], true), 'o paciente não tem sexo masculino ou feminino no cadastro'],
                    [$patient->birth_date === null, 'o paciente não tem data de nascimento'],
                    [$patient->race_color === null, 'o paciente não tem raça/cor'],
                ] as [$missing, $message]) {
                    if ($missing) {
                        $problems[] = "Conta {$account->id}: {$message}.";
                    }
                }
                $individual[] = [
                    'cmp' => $done, 'cnsmed' => $professional?->cns, 'cbo' => $cbo, 'date' => str_replace('-', '', $day), 'code' => $item->code,
                    'cnspac' => $cns, 'ibge' => Municipality::sus($patient->address_municipality), 'sex' => $patient->sex === 'female' ? 'F' : 'M', 'cid' => $cid, 'age' => $age, 'quantity' => (int) $item->quantity,
                    // Character of care (SUS table): 01 elective (a booked consultation), 02 urgency (the emergency).
                    'character' => $stay->appointment_id !== null ? '01' : '02',
                    'name' => $patient->name, 'birth' => $patient->birth_date === null ? null : str_replace('-', '', substr((string) $patient->birth_date, 0, 10)),
                    'race' => (string) $patient->race_color, 'ethnicity' => $patient->race_color === '05' ? $patient->ethnicity : null,
                    // One document only: the CNS, else the CPF.
                    'cpf' => $cns === null ? $patient->cpf : null,
                ];
            }
        }
        if ($consolidated === [] && $individual === [] && $problems === []) {
            $problems[] = 'Nenhuma conta SUS de BPA fechada para apresentar nesta unidade até esta competência.';
        }
        if ((int) ceil(count($consolidated) / self::C_PER_SHEET) + (int) ceil(count($individual) / self::I_PER_SHEET) > 999) {
            $problems[] = 'O arquivo passaria de 999 folhas: gere-o com menos contas.';
        }
        if ($problems !== []) {
            return ['text' => '', 'lines' => 0, 'sheets' => 0, 'accounts' => [], 'problems' => array_values(array_unique($problems))];
        }

        $lines = [];
        $control = 0;
        $sheet = 0;
        // Sheets numbered through the file: the consolidated ones first.
        foreach (array_values($consolidated) as $index => $line) {
            $sheet = intdiv($index, self::C_PER_SHEET) + 1;
            $lines[] = self::line([
                '02', self::num($unit->cnes, 7), $line['cmp'], self::alpha($line['cbo'], 6), self::num($sheet, 3), self::num($index % self::C_PER_SHEET + 1, 2),
                self::num($line['code'], 10), self::num($line['age'], 3), self::num($line['quantity'], 6), 'BPA',
            ], 48);
            $control += (int) $line['code'] + $line['quantity'];
        }
        $first = $sheet;
        foreach ($individual as $index => $line) {
            $sheet = $first + intdiv($index, self::I_PER_SHEET) + 1;
            $lines[] = self::line([
                '03', self::num($unit->cnes, 7), $line['cmp'], self::num($line['cnsmed'], 15), self::alpha($line['cbo'], 6), $line['date'],
                self::num($sheet, 3), self::num($index % self::I_PER_SHEET + 1, 2), self::num($line['code'], 10), self::blankOr($line['cnspac'], 15),
                $line['sex'], self::blankOr($line['ibge'], 6), self::alpha($line['cid'], 4), self::num($line['age'], 3), self::num($line['quantity'], 6),
                $line['character'], self::blank(13), 'BPA', self::alpha($line['name'], 30), self::blankOr($line['birth'], 8), $line['race'],
                self::blankOr($line['ethnicity'], 4),
                // Nationality, service, classification, team (sequence and area), OPM CNPJ, address and contact: blank
                // (optional in the layout; the nationality waits for the official table).
                self::blank(3 + 3 + 3 + 8 + 4 + 14 + 8 + 3 + 30 + 10 + 5 + 30 + 11 + 40),
                self::blank(10), self::blankOr($line['cpf'], 11), ' ', ' ',
            ], 351);
            $control += (int) $line['code'] + $line['quantity'];
        }
        $header = self::line([
            '01', '#BPA#', str_replace('-', '', $competence), self::num(count($lines), 6), self::num($sheet, 6),
            // Control field: the sum of the procedure codes and quantities, its remainder by 1111, plus 1111.
            self::num($control % 1111 + 1111, 4), self::alpha($unit->name, 30), self::alpha($unit->sus_acronym, 6), self::num($unit->cnpj, 14),
            self::alpha($unit->sus_manager_name, 40), (string) $unit->sus_manager_level, self::alpha(self::VERSION, 10),
        ], 130);

        return ['text' => $header.implode('', $lines), 'lines' => count($lines), 'sheets' => $sheet, 'accounts' => $presented, 'problems' => []];
    }

    /** A complementary account is presented with its stay's patient, diagnosis and care. */
    private static function stay(BillingAccount $account): BillingAccount
    {
        return $account->complement_of_id === null ? $account : BillingAccount::query()->findOrFail($account->complement_of_id);
    }

    /** Who did the item's procedure (id and CNS): the visit's professional, the exam's signer, the surgeon. */
    private static function professional(BillingAccount $stay, stdClass $item): ?stdClass
    {
        $id = match ($item->origin_type) {
            'visit' => DB::table('visits')->where('id', $item->origin_id)->value('professional_id'),
            'exam_order_item' => DB::table('exam_results')->where('exam_order_item_id', $item->origin_id)->whereNotNull('professional_id')->orderBy('id')->value('professional_id'),
            'surgery' => DB::table('surgery_team_members')->where('surgery_id', $item->origin_id)->where('role', 'surgeon')->whereNull('removed_at')->orderBy('id')->value('professional_id'),
            default => null,
        } ?? DB::table('visits')->where($stay->appointment_id !== null ? 'appointment_id' : 'emergency_episode_id', $stay->appointment_id ?? $stay->emergency_episode_id)->orderBy('id')->value('professional_id');

        return $id === null ? null : DB::table('professionals')->where('id', $id)->first(['id', 'cns']);
    }

    /**
     * @param  list<string>  $fields
     */
    private static function line(array $fields, int $length): string
    {
        $line = implode('', $fields);
        if (strlen($line) !== $length) {
            throw new LogicException('A BPA line has '.strlen($line)." positions instead of {$length}.");
        }

        return $line."\r\n";
    }

    private static function num(int|string|null $value, int $length): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);
        // Never cut silently: the control field counts the whole value.
        if (strlen($digits) > $length) {
            throw new LogicException("A BPA number has more than {$length} digits.");
        }

        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }

    private static function alpha(?string $value, int $length): string
    {
        $text = strtoupper(Str::ascii((string) $value));

        return str_pad(substr((string) preg_replace('/[^A-Z0-9 .,\/-]/', '', $text), 0, $length), $length);
    }

    private static function blank(int $length): string
    {
        return str_repeat(' ', $length);
    }

    private static function blankOr(?string $digits, int $length): string
    {
        return $digits === null ? self::blank($length) : self::num($digits, $length);
    }
}
