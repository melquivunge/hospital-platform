<?php

namespace App\Billing;

use App\Models\BillingLot;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Step 6.5b2a (ADR-0010): the XML of a lot of guides in the TISS 4.03.00 communication standard (ISO-8859-1), with the
 * epilogue hash, validated against the official schemas kept in resources/tiss/4.03.00. Each account is one guide, its
 * items the procedures and the other expenses. SP/SADT: the hospital requests and performs (its code at the insurer and
 * the unit's CNES), the professional of the stay's first visit is the requester. Step 6.5b2b, the hospitalization
 * summary: the admission the episode became (its dates and discharge) and the insurer's authorization (password).
 *
 * The codes below come from the official TUSS domain tables, version 202609 (Representação de Conceitos em Saúde,
 * sheet "Demais terminologias"): 23 character, 25 expense, 26 council, 36 accident, 50 care type, 60 unit of measure,
 * 76 care regime, 87 tables, and for the hospitalization 39 discharge reason, 41 hospitalization regime, 55 billing
 * type, 57 hospitalization type; the UF is the IBGE code (dm_UF).
 */
final class TissLotXml
{
    public const SCHEMA = 'resources/tiss/4.03.00/tissV4_03_00.xsd';

    private const NS = 'http://www.ans.gov.br/padroes/tiss/schemas';

    /** Table 26. */
    public const COUNCILS = ['CRESS' => '01', 'COREN' => '02', 'CRF' => '03', 'CREFONO' => '04', 'CREFITO' => '05', 'CRM' => '06', 'CRN' => '07',
        'CRO' => '08', 'CRP' => '09', 'CRBIO' => '11', 'CRBM' => '12', 'CREF' => '13', 'CRMV' => '14', 'CRTR' => '15'];

    /** dm_UF: the IBGE code of each state. */
    private const UFS = ['RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17', 'MA' => '21', 'PI' => '22',
        'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27', 'SE' => '28', 'BA' => '29', 'MG' => '31', 'ES' => '32', 'RJ' => '33',
        'SP' => '35', 'PR' => '41', 'SC' => '42', 'RS' => '43', 'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53'];

    /** Table 25 (expense code), table 87 (its TUSS table) and the guide's total each kind adds to. */
    private const EXPENSES = ['daily' => ['05', '18', 'valorDiarias'], 'material' => ['03', '19', 'valorMateriais'], 'medication' => ['02', '20', 'valorMedicamentos']];

    /**
     * Table 39, by the discharge type: improved, at request, transfer, evasion and death.
     * ponytail: a death is "declaration by the attending doctor" (41); IML (42) or SVO (43) need a field at the discharge.
     */
    private const DISCHARGE_REASONS = ['improved' => '12', 'at_request' => '14', 'transfer_out' => '31', 'evasion' => '16', 'death' => '41'];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly BillingLot $lot) {}

    /**
     * What keeps the lot from becoming a file, in words for billing; empty when it can be generated.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $data = $this->data();
        $problems = [];
        if (! preg_match('/^\d{6}$/', (string) $data['ansCode'])) {
            $problems[] = 'O convênio não tem o registro ANS (6 dígitos, tela Convênios).';
        }
        if ($data['providerCode'] === null) {
            $problems[] = 'O contrato do convênio não tem o código do prestador na operadora (tela Preços).';
        }
        if ($data['cnes'] === null) {
            $problems[] = 'A unidade não tem o CNES (tela Unidades).';
        }
        $cbos = self::acceptedCbos();
        foreach ($data['accounts'] as $account) {
            $at = "Conta {$account['id']}:";
            if ($account['card'] === '' || mb_strlen($account['card']) > 20) {
                $problems[] = "{$at} a carteira precisa ter de 1 a 20 caracteres.";
            }
            $professional = $account['professional'];
            if ($this->lot->guide_type === 'hospitalization') {
                // The summary names no professional: the admission and the insurer's password instead.
                if ($account['admission']?->discharged_at === null) {
                    $problems[] = "{$at} a internação ainda não tem alta.";
                }
                if ($account['authorization'] === null) {
                    // Closing requires it since this step; an account closed before goes back by the complement (6.5c).
                    $problems[] = "{$at} fechada sem a senha do convênio, que a guia de internação exige.";
                } elseif (mb_strlen($account['authorization']->number) > 20) {
                    $problems[] = "{$at} a senha de autorização passa de 20 caracteres.";
                }
            } elseif ($professional === null) {
                $problems[] = "{$at} o atendimento não tem profissional.";
            } else {
                // Said once per professional, however many guides they are in.
                if (! isset(self::COUNCILS[strtoupper((string) $professional->council)]) || ! isset(self::UFS[(string) $professional->council_uf]) || mb_strlen((string) $professional->council_number) > 15) {
                    $problems[] = "{$professional->name}: sem conselho reconhecido pelo TISS, número (até 15) ou UF (tela Profissionais).";
                }
                if (! in_array($professional->cbo, $cbos, true)) {
                    $problems[] = "{$professional->name}: sem CBO aceito pelo TISS na função (tela Funções).";
                }
            }
            if ($account['foreignCompensations'] > 0) {
                $problems[] = "{$at} tem estorno de item de outra conta; ele vai na etapa 6.5c.";
            }
            foreach ($account['items'] as $item) {
                if ($item->code === null || mb_strlen($item->code) > 10) {
                    $problems[] = "{$at} \"{$item->name}\" sem código (até 10 caracteres) no item cobrável.";
                } elseif (! isset(self::EXPENSES[$item->kind]) && $item->quantity > 999) {
                    $problems[] = "{$at} \"{$item->name}\" com quantidade acima de 999.";
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * The file's XML (ISO-8859-1) and its epilogue hash. Call only without problems; an XML the schema refuses is a
     * bug here, never sent.
     *
     * @return array{xml: string, hash: string}
     */
    public function build(): array
    {
        if ($this->problems() !== []) {
            throw new RuntimeException('The lot has problems: it cannot become a file.');
        }
        $data = $this->data();
        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $message = $doc->appendChild($doc->createElementNS(self::NS, 'ans:mensagemTISS'));
        $now = now($data['timezone']);

        $header = $this->add($message, 'cabecalho');
        $transaction = $this->add($header, 'identificacaoTransacao');
        $this->add($transaction, 'tipoTransacao', 'ENVIO_LOTE_GUIAS');
        $this->add($transaction, 'sequencialTransacao', (string) $this->lot->id);
        $this->add($transaction, 'dataRegistroTransacao', $now->toDateString());
        $this->add($transaction, 'horaRegistroTransacao', $now->format('H:i:s'));
        $this->add($this->add($this->add($header, 'origem'), 'identificacaoPrestador'), 'codigoPrestadorNaOperadora', $data['providerCode']);
        $this->add($this->add($header, 'destino'), 'registroANS', $data['ansCode']);
        $this->add($header, 'Padrao', BillingLot::TISS_VERSION);

        $lot = $this->add($this->add($message, 'prestadorParaOperadora'), 'loteGuias');
        $this->add($lot, 'numeroLote', (string) $this->lot->id);
        $guides = $this->add($lot, 'guiasTISS');
        foreach ($data['accounts'] as $account) {
            if ($this->lot->guide_type === 'hospitalization') {
                $this->hospitalizationGuide($this->add($guides, 'guiaResumoInternacao'), $account, $data);
            } else {
                $this->guide($this->add($guides, 'guiaSP-SADT'), $account, $data);
            }
        }

        $hash = self::hash($doc);
        $this->add($this->add($message, 'epilogo'), 'hash', $hash);
        $xml = (string) $doc->saveXML();
        $errors = self::schemaErrors($xml);
        if ($errors !== []) {
            throw new RuntimeException('The lot XML does not match the TISS schema: '.implode(' | ', array_slice($errors, 0, 5)));
        }

        return ['xml' => $xml, 'hash' => $hash];
    }

    /**
     * The epilogue hash: MD5 of the text of every leaf element in document order (no tags, attributes nor
     * indentation), in ISO-8859-1 as the file (Componente Organizacional); the hash element itself is left out.
     */
    public static function hash(DOMDocument $doc): string
    {
        $text = '';
        $walk = function (DOMNode $node) use (&$walk, &$text): void {
            $children = array_filter(iterator_to_array($node->childNodes), fn (DOMNode $child) => $child instanceof DOMElement);
            if ($children === [] && $node instanceof DOMElement) {
                if ($node->localName !== 'hash') {
                    $text .= $node->textContent;
                }

                return;
            }
            foreach ($children as $child) {
                $walk($child);
            }
        };
        $walk($doc->documentElement);

        return md5((string) mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'));
    }

    /**
     * The official schema's complaints about an XML; empty when valid.
     *
     * @return list<string>
     */
    public static function schemaErrors(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            if ($doc->loadXML($xml, LIBXML_NONET) && $doc->schemaValidate(base_path(self::SCHEMA), LIBXML_NONET)) {
                return [];
            }

            return array_map(fn ($error) => trim($error->message)." (linha {$error->line})", libxml_get_errors()) ?: ['XML inválido.'];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * The CBO codes the schema accepts (dm_CBOS).
     *
     * @return list<string>
     */
    public static function acceptedCbos(): array
    {
        static $codes = null;
        if ($codes === null) {
            $doc = new DOMDocument;
            $doc->load(base_path('resources/tiss/4.03.00/tissSimpleTypesV4_03_00.xsd'), LIBXML_NONET);
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
            $codes = array_map(fn (DOMElement $value) => $value->getAttribute('value'), iterator_to_array($xpath->query("//xs:simpleType[@name='dm_CBOS']//xs:enumeration")));
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $account
     * @param  array<string, mixed>  $data
     */
    private function guide(DOMElement $guide, array $account, array $data): void
    {
        $header = $this->add($guide, 'cabecalhoGuia');
        $this->add($header, 'registroANS', $data['ansCode']);
        $this->add($header, 'numeroGuiaPrestador', (string) $account['id']);
        $beneficiary = $this->add($guide, 'dadosBeneficiario');
        $this->add($beneficiary, 'numeroCarteira', $account['card']);
        // The beneficiary is the card alone: the name left the guide in 4.00.00.
        $this->add($beneficiary, 'atendimentoRN', 'N');

        $professional = $account['professional'];
        $requester = $this->add($guide, 'dadosSolicitante');
        $this->add($this->add($requester, 'contratadoSolicitante'), 'codigoPrestadorNaOperadora', $data['providerCode']);
        $this->add($requester, 'nomeContratadoSolicitante', mb_substr($data['unitName'], 0, 70));
        $person = $this->add($requester, 'profissionalSolicitante');
        $this->add($person, 'nomeProfissional', mb_substr($professional->name, 0, 70));
        $this->add($person, 'conselhoProfissional', self::COUNCILS[strtoupper($professional->council)]);
        $this->add($person, 'numeroConselhoProfissional', $professional->council_number);
        $this->add($person, 'UF', self::UFS[$professional->council_uf]);
        $this->add($person, 'CBOS', $professional->cbo);
        // Table 23: 1 elective (consultation), 2 urgency (emergency episode).
        $this->add($this->add($guide, 'dadosSolicitacao'), 'caraterAtendimento', $account['emergency'] ? '2' : '1');

        $performer = $this->add($guide, 'dadosExecutante');
        $this->add($this->add($performer, 'contratadoExecutante'), 'codigoPrestadorNaOperadora', $data['providerCode']);
        $this->add($performer, 'CNES', $data['cnes']);
        $care = $this->add($guide, 'dadosAtendimento');
        // Table 50: 04 consultation; table 36: 9 not an accident; table 76: 01 outpatient, 04 emergency room.
        $this->add($care, 'tipoAtendimento', '04');
        $this->add($care, 'indicacaoAcidente', '9');
        $this->add($care, 'regimeAtendimento', $account['emergency'] ? '04' : '01');

        $this->items($guide, $account['items'], false);
    }

    /**
     * Step 6.5b2b: the hospitalization summary of an account whose stay became an admission.
     *
     * @param  array<string, mixed>  $account
     * @param  array<string, mixed>  $data
     */
    private function hospitalizationGuide(DOMElement $guide, array $account, array $data): void
    {
        $admission = $account['admission'];
        $authorization = $account['authorization'];
        $header = $this->add($guide, 'cabecalhoGuia');
        $this->add($header, 'registroANS', $data['ansCode']);
        $this->add($header, 'numeroGuiaPrestador', (string) $account['id']);
        // ponytail: the hospital's request is its admission; the insurer's own guide number if an insurer asks for it.
        $this->add($guide, 'numeroGuiaSolicitacaoInternacao', (string) $admission->id);
        $authorized = $this->add($guide, 'dadosAutorizacao');
        $this->add($authorized, 'dataAutorizacao', CarbonImmutable::parse($authorization->created_at)->setTimezone($data['timezone'])->toDateString());
        $this->add($authorized, 'senha', $authorization->number);
        $this->add($authorized, 'dataValidadeSenha', $authorization->valid_until);
        $beneficiary = $this->add($guide, 'dadosBeneficiario');
        $this->add($beneficiary, 'numeroCarteira', $account['card']);
        $this->add($beneficiary, 'atendimentoRN', 'N');
        $performer = $this->add($guide, 'dadosExecutante');
        $this->add($this->add($performer, 'contratadoExecutante'), 'codigoPrestadorNaOperadora', $data['providerCode']);
        $this->add($performer, 'CNES', $data['cnes']);

        $start = CarbonImmutable::parse($admission->admitted_at)->setTimezone($data['timezone']);
        $end = CarbonImmutable::parse($admission->discharged_at)->setTimezone($data['timezone']);
        $stay = $this->add($guide, 'dadosInternacao');
        // Table 23: 2 urgency (an admission comes from the emergency episode); table 55: 4 total, 3 complementary.
        $this->add($stay, 'caraterAtendimento', '2');
        $this->add($stay, 'tipoFaturamento', $account['complement'] ? '3' : '4');
        $this->add($stay, 'dataInicioFaturamento', $start->toDateString());
        $this->add($stay, 'horaInicioFaturamento', $start->format('H:i:s'));
        $this->add($stay, 'dataFinalFaturamento', $end->toDateString());
        $this->add($stay, 'horaFinalFaturamento', $end->format('H:i:s'));
        // Table 57: 2 surgical when a surgical procedure is billed, else 1 clinical; table 41: 1 hospital.
        // ponytail: obstetric, pediatric and psychiatric (3-5) need the admission's type; add it when an insurer asks.
        $this->add($stay, 'tipoInternacao', collect($account['items'])->contains('kind', 'procedure') ? '2' : '1');
        $this->add($stay, 'regimeInternacao', '1');
        $exit = $this->add($guide, 'dadosSaidaInternacao');
        $this->add($exit, 'indicadorAcidente', '9');
        $this->add($exit, 'motivoEncerramento', self::DISCHARGE_REASONS[$admission->discharge_type]);
        $this->items($guide, $account['items'], true);
    }

    /**
     * The procedures, the other expenses and the totals; the hospitalization summary puts the totals before the
     * expenses, SP/SADT after.
     *
     * @param  list<object>  $items
     */
    private function items(DOMElement $guide, array $items, bool $totalsFirst): void
    {
        $procedures = array_values(array_filter($items, fn ($item) => ! isset(self::EXPENSES[$item->kind])));
        $expenses = array_values(array_filter($items, fn ($item) => isset(self::EXPENSES[$item->kind])));
        $totals = ['valorProcedimentos' => 0, 'valorDiarias' => 0, 'valorMateriais' => 0, 'valorMedicamentos' => 0];
        foreach ($items as $item) {
            $totals[isset(self::EXPENSES[$item->kind]) ? self::EXPENSES[$item->kind][2] : 'valorProcedimentos'] += $item->quantity * $item->unit_price_cents;
        }
        $writeTotals = function () use ($guide, $totals): void {
            $value = $this->add($guide, 'valorTotal');
            foreach ($totals as $name => $cents) {
                if ($cents > 0) {
                    $this->add($value, $name, self::money($cents));
                }
            }
            $this->add($value, 'valorTotalGeral', self::money(array_sum($totals)));
        };
        if ($procedures !== []) {
            $list = $this->add($guide, 'procedimentosExecutados');
            foreach ($procedures as $i => $item) {
                $line = $this->add($list, 'procedimentoExecutado');
                $this->add($line, 'sequencialItem', (string) ($i + 1));
                $this->add($line, 'dataExecucao', $item->performed_on);
                $procedure = $this->add($line, 'procedimento');
                // ponytail: an 8-digit code is read as TUSS 22, any other as the insurer's own table (00); a per-item table field if insurers disagree.
                $this->add($procedure, 'codigoTabela', preg_match('/^\d{8}$/', $item->code) ? '22' : '00');
                $this->add($procedure, 'codigoProcedimento', $item->code);
                $this->add($procedure, 'descricaoProcedimento', mb_substr($item->name, 0, 150));
                $this->add($line, 'quantidadeExecutada', (string) $item->quantity);
                $this->add($line, 'reducaoAcrescimo', '1.00');
                $this->add($line, 'valorUnitario', self::money($item->unit_price_cents));
                $this->add($line, 'valorTotal', self::money($item->quantity * $item->unit_price_cents));
            }
        }
        if ($totalsFirst) {
            $writeTotals();
        }
        if ($expenses !== []) {
            $list = $this->add($guide, 'outrasDespesas');
            foreach ($expenses as $i => $item) {
                [$expense, $table] = self::EXPENSES[$item->kind];
                $line = $this->add($list, 'despesa');
                $this->add($line, 'sequencialItem', (string) ($i + 1));
                $this->add($line, 'codigoDespesa', $expense);
                $service = $this->add($line, 'servicosExecutados');
                $this->add($service, 'dataExecucao', $item->performed_on);
                // ponytail: a numeric code is read as the TUSS table of its kind, any other as the insurer's own (00).
                $this->add($service, 'codigoTabela', ctype_digit($item->code) ? $table : '00');
                $this->add($service, 'codigoProcedimento', $item->code);
                $this->add($service, 'quantidadeExecutada', $item->quantity.'.0000');
                // Table 60: 036 unit.
                $this->add($service, 'unidadeMedida', '036');
                $this->add($service, 'reducaoAcrescimo', '1.00');
                $this->add($service, 'valorUnitario', self::money($item->unit_price_cents));
                $this->add($service, 'valorTotal', self::money($item->quantity * $item->unit_price_cents));
                $this->add($service, 'descricaoProcedimento', mb_substr($item->name, 0, 150));
            }
        }
        if (! $totalsFirst) {
            $writeTotals();
        }
    }

    /**
     * Everything the file needs, read once: the insurer, the contract, the unit, and each account with its card, its
     * items and, for SP/SADT, the professional of its stay's first visit (with the CBO of an approved function at the
     * unit); for the hospitalization summary, the admission its episode became and the insurer's password. Pairs of an
     * item and its compensation in the same account cancel out and are left out.
     *
     * @return array<string, mixed>
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $lot = $this->lot;
        $unit = DB::table('units')->where('id', $lot->unit_id)->first(['name', 'cnes', 'timezone']);
        $accounts = DB::table('billing_accounts as a')->join('billing_lot_accounts as la', 'la.billing_account_id', '=', 'a.id')
            ->where('la.billing_lot_id', $lot->id)->whereNull('la.released_at')->orderBy('a.closed_at')->orderBy('a.id')
            ->get(['a.id', 'a.complement_of_id', 'a.appointment_id', 'a.emergency_episode_id', 'a.insurance_card_number']);
        $stays = DB::table('billing_accounts')->whereIn('id', $accounts->pluck('complement_of_id')->filter()->all())->get(['id', 'appointment_id', 'emergency_episode_id'])->keyBy('id');
        $items = DB::table('billing_account_items as i')->leftJoin('billing_items as b', 'b.id', '=', 'i.billing_item_id')
            ->whereIn('i.billing_account_id', $accounts->pluck('id')->all())->orderBy('i.performed_on')->orderBy('i.id')
            ->get(['i.id', 'i.billing_account_id', 'i.origin_type', 'i.origin_id', 'i.code', 'i.name', 'i.quantity', 'i.unit_price_cents', 'i.performed_on', 'b.kind'])
            ->groupBy('billing_account_id');

        $stayOf = fn ($account) => $account->complement_of_id === null ? $account : $stays->get($account->complement_of_id);
        $hospitalization = $lot->guide_type === 'hospitalization';
        $professionals = $hospitalization ? [] : self::professionals($accounts->map($stayOf)->all(), $lot->unit_id);
        $admissions = ! $hospitalization ? collect() : DB::table('admissions')->whereIn('emergency_episode_id', $accounts->map($stayOf)->pluck('emergency_episode_id')->filter()->all())
            ->get(['id', 'emergency_episode_id', 'admitted_at', 'discharged_at', 'discharge_type'])->keyBy('emergency_episode_id');
        $authorizations = $hospitalization ? self::passwords($accounts->map(fn ($account) => $account->complement_of_id ?? $account->id)->all()) : collect();
        $described = $accounts->map(function ($account) use ($stayOf, $items, $professionals, $admissions, $authorizations) {
            $stay = $stayOf($account);
            $own = $items->get($account->id, collect());
            $compensated = $own->where('origin_type', 'compensation')->pluck('origin_id')->all();
            $kept = $own->filter(fn ($item) => $item->origin_type !== 'compensation' && ! in_array($item->id, $compensated, true));

            return [
                'id' => $account->id,
                'card' => (string) $account->insurance_card_number,
                'emergency' => $stay->emergency_episode_id !== null,
                'complement' => $account->complement_of_id !== null,
                'professional' => $professionals[$stay->appointment_id !== null ? "a{$stay->appointment_id}" : "e{$stay->emergency_episode_id}"] ?? null,
                'admission' => $admissions->get($stay->emergency_episode_id),
                'authorization' => $authorizations->get($account->complement_of_id ?? $account->id),
                'foreignCompensations' => $own->where('origin_type', 'compensation')->whereNotIn('origin_id', $own->pluck('id')->all())->count(),
                'items' => $kept->values()->all(),
            ];
        })->all();

        return $this->data = [
            'timezone' => $unit->timezone ?? 'UTC',
            'unitName' => (string) $unit->name,
            'cnes' => $unit->cnes,
            'ansCode' => DB::table('insurers')->where('id', $lot->insurer_id)->value('ans_code'),
            'providerCode' => DB::table('insurer_contracts')->where('insurer_id', $lot->insurer_id)->value('provider_code'),
            'accounts' => $described,
        ];
    }

    /**
     * Step 6.5b2b: the insurer's password of each stay (keyed by the stay's account, complements included): the latest
     * answer of an item that is "authorized", a daily rate's first. The hospitalization summary requires it.
     *
     * @param  list<int>  $stayAccountIds
     * @return Collection<int, object>
     */
    public static function passwords(array $stayAccountIds): Collection
    {
        // Ascending, so the key kept is a daily rate's latest authorization when there is one.
        return DB::table('billing_authorizations as z')->join('billing_account_items as i', 'i.id', '=', 'z.billing_account_item_id')
            ->join('billing_accounts as a', 'a.id', '=', 'i.billing_account_id')->leftJoin('billing_items as b', 'b.id', '=', 'i.billing_item_id')
            ->whereIn(DB::raw('coalesce(a.complement_of_id, a.id)'), $stayAccountIds)->where('z.status', 'authorized')
            ->whereRaw('z.id = (SELECT max(id) FROM billing_authorizations WHERE billing_account_item_id = z.billing_account_item_id)')
            ->orderByRaw("coalesce(b.kind = 'daily', false)")->orderBy('z.id')
            ->get([DB::raw('coalesce(a.complement_of_id, a.id) AS stay_id'), 'z.number', 'z.valid_until', 'z.created_at'])->keyBy('stay_id');
    }

    /**
     * The professional of each stay's first visit (keyed "a<appointment>" or "e<episode>"), with the CBO of their
     * first approved function at the unit that has one; two queries for the whole lot.
     *
     * @param  list<object>  $stays
     * @return array<string, object>
     */
    private static function professionals(array $stays, int $unitId): array
    {
        $appointments = array_values(array_filter(array_map(fn ($stay) => $stay->appointment_id, $stays)));
        $episodes = array_values(array_filter(array_map(fn ($stay) => $stay->emergency_episode_id, $stays)));
        $visits = DB::table('visits as v')->join('professionals as p', 'p.id', '=', 'v.professional_id')
            ->where(fn ($query) => $query->whereIn('v.appointment_id', $appointments)->orWhereIn('v.emergency_episode_id', $episodes))
            ->orderBy('v.opened_at')->orderBy('v.id')
            ->get(['v.appointment_id', 'v.emergency_episode_id', 'p.id', 'p.name', 'p.council', 'p.council_number', 'p.council_uf']);
        $cbos = DB::table('qualifications as q')->join('job_functions as f', 'f.id', '=', 'q.job_function_id')
            ->whereIn('q.professional_id', $visits->pluck('id')->unique()->all())->where('q.unit_id', $unitId)->where('q.status', 'approved')->whereNotNull('f.cbo')
            ->orderByDesc('q.id')->pluck('f.cbo', 'q.professional_id');
        $first = [];
        foreach ($visits as $visit) {
            $key = $visit->appointment_id !== null ? "a{$visit->appointment_id}" : "e{$visit->emergency_episode_id}";
            if (! isset($first[$key])) {
                $visit->cbo = $cbos->get($visit->id);
                $first[$key] = $visit;
            }
        }

        return $first;
    }

    private function add(DOMNode $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $parent->ownerDocument?->createElementNS(self::NS, "ans:{$name}") ?? throw new RuntimeException('No document.');
        if ($value !== null) {
            // Only what ISO-8859-1 holds (anything else becomes "?"): the file and its hash read the same text.
            $latin1 = (string) mb_convert_encoding((string) mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1');
            $element->appendChild($element->ownerDocument->createTextNode($latin1));
        }
        $parent->appendChild($element);

        return $element;
    }

    private static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '').sprintf('%d.%02d', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
