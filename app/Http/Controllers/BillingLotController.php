<?php

namespace App\Http\Controllers;

use App\Billing\TissLotXml;
use App\Clinical\ClinicalAccess;
use App\Models\AuditEvent;
use App\Models\BillingAccount;
use App\Models\BillingLot;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\PriceEntry;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Step 6.5b1 (ADR-0010): the TISS lots of a unit, for its Billing role (or the network admin): what is ready to go
 * (closed insurance accounts outside any active lot, by insurer, competence and guide type; numbers only), the lots
 * generated, and their cancellation with a reason.
 */
class BillingLotController extends Controller
{
    /** ponytail: newest first and capped; paginate when a unit's lots outgrow it. */
    private const LIST_LIMIT = 100;

    public function index(Request $request): Response
    {
        $units = Unit::query()->whereKey(BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())))->orderBy('name')->get(['id', 'name', 'timezone']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $timezone = $unit->timezone ?? 'UTC';
        $ready = DB::query()->fromSub(BillingLot::ready($unit->id, $timezone), 'ready')
            ->select('insurer_id', 'competence', 'guide_type')->selectRaw('count(*) AS accounts, sum(total_cents) AS total_cents')
            ->groupBy('insurer_id', 'competence', 'guide_type')->orderBy('competence')->orderBy('insurer_id')->orderBy('guide_type')->get();
        $lots = BillingLot::query()->where('unit_id', $unit->id)->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();
        $insurers = Insurer::query()->whereKey($ready->pluck('insurer_id')->merge($lots->pluck('insurer_id'))->unique()->all())->pluck('name', 'id');
        $users = User::query()->whereKey($lots->pluck('created_by_user_id')->merge($lots->pluck('cancelled_by_user_id'))->filter()->unique()->all())->pluck('name', 'id');
        $conciliations = BillingLot::conciliations($lots->pluck('id')->all());

        return Inertia::render('billing-lots/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unitId' => $unit->id,
            'ready' => $ready->map(fn ($group) => [
                'insurerId' => (int) $group->insurer_id,
                'insurer' => $insurers->get($group->insurer_id),
                'competence' => substr((string) $group->competence, 0, 7),
                'guideType' => $group->guide_type,
                'accounts' => (int) $group->accounts,
                'totalCents' => (int) $group->total_cents,
            ])->values(),
            'lots' => $lots->map(fn (BillingLot $lot) => [
                'id' => $lot->id,
                'insurer' => $insurers->get($lot->insurer_id),
                'competence' => $lot->competence->format('m/Y'),
                'guideType' => $lot->guide_type,
                'guideCount' => $lot->guide_count,
                'totalCents' => $lot->total_cents,
                'status' => $lot->status,
                'createdAt' => $lot->created_at->setTimezone($timezone)->format('d/m/Y H:i'),
                'createdBy' => $users->get($lot->created_by_user_id),
                'cancelledAt' => $lot->cancelled_at?->setTimezone($timezone)->format('d/m/Y H:i'),
                'cancelledBy' => $lot->cancelled_by_user_id === null ? null : $users->get($lot->cancelled_by_user_id),
                'cancelReason' => $lot->cancel_reason,
                // Step 6.5b2a: the TISS file, or what keeps a generated lot from it.
                'file' => $lot->file_path === null ? null : [
                    'sha256' => $lot->file_sha256,
                    'generatedAt' => $lot->file_generated_at?->setTimezone($timezone)->format('d/m/Y H:i'),
                ],
                'problems' => $lot->status === 'generated' && $lot->file_path === null ? (new TissLotXml($lot))->problems() : [],
                // Steps 6.5c1 and 6.5c2: the conciliation once the insurer's return is recorded, billed = paid + glossed, and
                // of the glossed what is in appeal and what the appeals recovered.
                'return' => $conciliations->get($lot->id),
            ])->values(),
            'guideTypes' => BillingLot::GUIDE_TYPES,
            'maxGuides' => BillingLot::MAX_GUIDES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $unitIds = BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user()));
        abort_if($unitIds === [], 403);
        $data = $request->validateWithBag('lot', [
            'unit_id' => ['required', 'integer', Rule::in($unitIds)],
            // The tenant scope keeps another network's insurer out.
            'insurer_id' => ['bail', 'required', 'integer', fn (string $attribute, mixed $value, \Closure $fail) => Insurer::query()->whereKey($value)->exists() || $fail('Convênio inválido.')],
            'competence' => ['required', 'date_format:Y-m'],
            'guide_type' => ['required', Rule::in(array_keys(BillingLot::GUIDE_TYPES))],
        ], [], ['insurer_id' => 'convênio', 'competence' => 'competência', 'guide_type' => 'tipo de guia']);

        try {
            $lot = BillingLot::generate(Unit::query()->findOrFail($data['unit_id']), (int) $data['insurer_id'],
                CarbonImmutable::createFromFormat('!Y-m', $data['competence']), $data['guide_type'], $request->user());
        } catch (UniqueConstraintViolationException) {
            // Another lot took these accounts at the same time (one active lot per account).
            throw ValidationException::withMessages(['lot' => 'Outro lote acabou de levar estas contas. Atualize a página e confira.'])->errorBag('lot');
        }
        if ($lot === null) {
            throw ValidationException::withMessages(['lot' => 'Não há contas prontas para este lote.'])->errorBag('lot');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Lote {$lot->id} gerado com {$lot->guide_count} guia(s)."]);

        return to_route('billing-lots.index', ['unit' => $lot->unit_id]);
    }

    public function cancel(Request $request, BillingLot $lot): RedirectResponse
    {
        abort_unless(in_array($lot->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $bag = "cancel-{$lot->id}";
        $data = $request->validateWithBag($bag, ['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        if (($refusal = $lot->cancel(trim((string) $data['reason']), $request->user())) !== null) {
            throw ValidationException::withMessages(['reason' => $refusal])->errorBag($bag);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Lote {$lot->id} cancelado; as contas voltaram para as prontas."]);

        return to_route('billing-lots.index', ['unit' => $lot->unit_id]);
    }

    /**
     * Step 6.5b2a: writes the lot's TISS file once, validated against the official schema, with its SHA-256; refused
     * while anything is missing (listed on the screen).
     */
    public function file(Request $request, BillingLot $lot): RedirectResponse
    {
        abort_unless(in_array($lot->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $errors = $lot->writeFile($request->user());
        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => $errors])->errorBag("file-{$lot->id}");
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Arquivo do lote {$lot->id} gerado e validado no padrão TISS."]);

        return to_route('billing-lots.index', ['unit' => $lot->unit_id]);
    }

    /**
     * Step 6.5b2a: the file to send on the insurer's portal. It carries every guide's patient: each one is logged in
     * the access trail ("billing").
     */
    public function download(Request $request, BillingLot $lot): SymfonyResponse
    {
        abort_unless(in_array($lot->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        // A cancelled lot's accounts may be in another lot: its file is never sent again.
        abort_if($lot->status !== 'generated' || $lot->file_path === null || ! Storage::disk('local')->exists($lot->file_path), 404);
        $accounts = BillingAccount::query()->whereIn('id', DB::table('billing_lot_accounts')->where('billing_lot_id', $lot->id)->select('billing_account_id'))->get();
        $patients = Patient::query()->whereKey($accounts->pluck('patient_id')->unique()->all())->get()->keyBy('id');
        foreach ($accounts as $account) {
            ClinicalAccess::recordRead($request->user(), $patients->get($account->patient_id), $account);
        }
        AuditEvent::record('billing_lot.file_downloaded', $lot->tenant_id, $lot, ['sha256' => $lot->file_sha256]);

        return response((string) Storage::disk('local')->get($lot->file_path), 200, [
            'Content-Type' => 'application/xml; charset=ISO-8859-1',
            'Content-Disposition' => "attachment; filename=\"lote-{$lot->id}-tiss-{$lot->tiss_version}.xml\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Step 6.5c1: the guide items of a sent lot, to record the insurer's return (what each item had glossed, with the
     * TUSS table 38 code and the reason), or the return recorded. It shows what was done to each guide's patient: each
     * one is logged in the access trail ("billing").
     */
    public function showReturn(Request $request, BillingLot $lot): Response
    {
        abort_unless(in_array($lot->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        abort_if($lot->status !== 'generated' || $lot->file_path === null, 404);
        $items = $lot->guideItems();
        $returns = DB::table('billing_item_returns')->where('billing_lot_id', $lot->id)->get()->keyBy('billing_account_item_id');
        $appeals = DB::table('billing_glosa_appeals')->where('billing_lot_id', $lot->id)->orderBy('sequence')->get()->keyBy('id');
        $appealed = DB::table('billing_glosa_appeal_items')->whereIn('billing_glosa_appeal_id', $appeals->keys()->all())->get()->keyBy('billing_item_return_id');
        $schemaCodes = TissLotXml::schemaGlosaCodes();
        $accounts = BillingAccount::query()->whereKey($items->pluck('billing_account_id')->unique()->all())->get();
        $patients = Patient::query()->whereKey($accounts->pluck('patient_id')->unique()->all())->get()->keyBy('id');
        foreach ($accounts as $account) {
            ClinicalAccess::recordRead($request->user(), $patients->get($account->patient_id), $account);
        }
        $first = $returns->first();
        $timezone = Unit::query()->whereKey($lot->unit_id)->value('timezone') ?? 'UTC';

        return Inertia::render('billing-lots/return', [
            'lot' => [
                'id' => $lot->id, 'insurer' => Insurer::query()->whereKey($lot->insurer_id)->value('name'), 'competence' => $lot->competence->format('m/Y'),
                'guideType' => BillingLot::GUIDE_TYPES[$lot->guide_type] ?? $lot->guide_type, 'totalCents' => $lot->total_cents, 'unitId' => $lot->unit_id,
            ],
            'accounts' => $items->groupBy('billing_account_id')->map(fn ($own, $accountId) => [
                'id' => (int) $accountId,
                'items' => $own->map(fn ($item) => [
                    'id' => $item->id, 'code' => $item->code, 'name' => $item->name, 'quantity' => $item->quantity, 'billedCents' => $item->billed_cents,
                    'return' => ($return = $returns->get($item->id)) === null ? null : [
                        'paidCents' => (int) $return->paid_cents, 'glossedCents' => (int) $return->glossed_cents, 'code' => $return->glosa_code, 'reason' => $return->glosa_reason,
                        // Step 6.5c2: a glosa the 4.03.00 schema knows can be appealed once.
                        'appealable' => $return->glossed_cents > 0 && ! $appealed->has($return->id) && in_array($return->glosa_code, $schemaCodes, true),
                        'appeal' => ($appeal = $appealed->get($return->id)) === null ? null : [
                            'sequence' => $appeals[$appeal->billing_glosa_appeal_id]->sequence, 'appealedCents' => (int) $appeal->appealed_cents,
                            'justification' => $appeal->justification, 'recoveredCents' => $appeal->recovered_cents === null ? null : (int) $appeal->recovered_cents,
                        ],
                    ],
                ])->values(),
            ])->values(),
            'recorded' => $first === null ? null : [
                'at' => CarbonImmutable::parse($first->recorded_at)->setTimezone($timezone)->format('d/m/Y H:i'),
                'by' => User::query()->whereKey($first->recorded_by_user_id)->value('name'),
                ...BillingLot::conciliations([$lot->id])->get($lot->id),
            ],
            'appeals' => $appeals->map(fn ($appeal) => [
                'sequence' => $appeal->sequence, 'protocol' => $appeal->protocol_number, 'appealedCents' => (int) $appeal->appealed_cents,
                'at' => CarbonImmutable::parse($appeal->created_at)->setTimezone($timezone)->format('d/m/Y H:i'),
            ])->values(),
            'glosaCodes' => BillingLot::glosaCodes(),
        ]);
    }

    /**
     * Step 6.5c1: records the insurer's return once. Only the glossed items are sent (value, code, reason); every other
     * item of the guides is paid in full.
     */
    public function storeReturn(Request $request, BillingLot $lot): RedirectResponse
    {
        abort_unless(in_array($lot->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $data = $request->validateWithBag('return', [
            'glosas' => ['sometimes', 'array', 'max:300'],
            'glosas.*' => ['array:amount,code,reason'],
            'glosas.*.amount' => ['nullable', 'string', 'max:20'],
            'glosas.*.code' => ['nullable', 'string', 'max:4'],
            'glosas.*.reason' => ['nullable', 'string', 'max:500'],
        ], [], ['glosas.*.reason' => 'motivo']);
        $codes = BillingLot::glosaCodes();
        $glosas = [];
        $errors = [];
        foreach ($data['glosas'] ?? [] as $id => $glosa) {
            $amount = trim((string) ($glosa['amount'] ?? ''));
            $code = trim((string) ($glosa['code'] ?? ''));
            $reason = trim((string) ($glosa['reason'] ?? ''));
            if ($amount === '' && $code === '' && $reason === '') {
                continue;
            }
            $cents = PriceEntry::cents($amount);
            if (! ctype_digit((string) $id)) {
                $errors['return'] = 'Item inválido.';
            } elseif ($cents === null || $cents === 0) {
                $errors["glosas.{$id}.amount"] = 'Informe o valor glosado (ex.: 12,50).';
            } elseif (! isset($codes[$code])) {
                $errors["glosas.{$id}.code"] = 'Informe o código da glosa da tabela TUSS 38.';
            } else {
                $glosas[(int) $id] = ['cents' => $cents, 'code' => $code, 'reason' => $reason === '' ? null : $reason];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors)->errorBag('return');
        }
        if (($refusal = $lot->recordReturn($glosas, $request->user())) !== []) {
            throw ValidationException::withMessages(['return' => $refusal])->errorBag('return');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Retorno do lote {$lot->id} registrado."]);

        return to_route('billing-lots.return', $lot);
    }

    /**
     * Step 6.5c2: the glosa appeal of glossed items, with the insurer's protocol of the lot; its TISS file is written
     * with it. Only the items marked are sent (a justification each; the value is the whole glosa unless given).
     */
    public function storeAppeal(Request $request, BillingLot $lot): RedirectResponse
    {
        abort_unless(in_array($lot->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $data = $request->validateWithBag('appeal', [
            'protocol' => ['required', 'string', 'regex:/^\d{1,12}$/'],
            'items' => ['sometimes', 'array', 'max:300'],
            'items.*' => ['array:amount,justification'],
            'items.*.amount' => ['nullable', 'string', 'max:20'],
            'items.*.justification' => ['nullable', 'string', 'max:500'],
        ], ['protocol.required' => 'Informe o número do protocolo do lote na operadora.', 'protocol.regex' => 'O protocolo tem só números (até 12).'],
            ['items.*.justification' => 'justificativa']);
        $items = [];
        $errors = [];
        foreach ($data['items'] ?? [] as $id => $item) {
            $amount = trim((string) ($item['amount'] ?? ''));
            // Control characters would fail the TISS schema: the file's text is one line.
            $justification = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($item['justification'] ?? '')));
            if ($amount === '' && $justification === '') {
                continue;
            }
            $cents = $amount === '' ? null : PriceEntry::cents($amount);
            if (! ctype_digit((string) $id)) {
                $errors['appeal'] = 'Item inválido.';
            } elseif ($amount !== '' && ($cents === null || $cents === 0)) {
                $errors["items.{$id}.amount"] = 'Informe o valor do recurso (ex.: 12,50) ou deixe em branco para recorrer da glosa toda.';
            } elseif ($justification === '') {
                $errors["items.{$id}.justification"] = 'Escreva a justificativa do recurso.';
            } else {
                $items[(int) $id] = ['cents' => $cents, 'justification' => $justification];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors)->errorBag('appeal');
        }
        if (($refusal = $lot->recordAppeal($data['protocol'], $items, $request->user())) !== []) {
            throw ValidationException::withMessages(['appeal' => $refusal])->errorBag('appeal');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Recurso de glosa do lote {$lot->id} gerado."]);

        return to_route('billing-lots.return', $lot);
    }

    /**
     * Step 6.5c2: the insurer's answer to appealed items, what it recovered (0 when denied), once per item.
     */
    public function storeAppealResults(Request $request, BillingLot $lot): RedirectResponse
    {
        abort_unless(in_array($lot->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $data = $request->validateWithBag('appealResult', [
            'results' => ['sometimes', 'array', 'max:300'],
            'results.*' => ['nullable', 'string', 'max:20'],
        ]);
        $recovered = [];
        $errors = [];
        foreach ($data['results'] ?? [] as $id => $amount) {
            $amount = trim((string) $amount);
            if ($amount === '') {
                continue;
            }
            $cents = PriceEntry::cents($amount);
            if (! ctype_digit((string) $id)) {
                $errors['appealResult'] = 'Item inválido.';
            } elseif ($cents === null) {
                $errors["results.{$id}"] = 'Informe o valor recuperado (ex.: 12,50; 0 quando negado).';
            } else {
                $recovered[(int) $id] = $cents;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors)->errorBag('appealResult');
        }
        if (($refusal = $lot->recordAppealResults($recovered, $request->user())) !== []) {
            throw ValidationException::withMessages(['appealResult' => $refusal])->errorBag('appealResult');
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Resultado do recurso do lote {$lot->id} registrado."]);

        return to_route('billing-lots.return', $lot);
    }

    /**
     * Step 6.5c2: the appeal's TISS file, for the insurer's portal; each patient of its guides is logged in the trail.
     */
    public function downloadAppeal(Request $request, BillingLot $lot, int $sequence): SymfonyResponse
    {
        abort_unless(in_array($lot->unit_id, BillingAccountController::unitIds(UnitPolicy::currentMembership($request->user())), true), 403);
        $appeal = DB::table('billing_glosa_appeals')->where('billing_lot_id', $lot->id)->where('sequence', $sequence)->first();
        abort_if($appeal === null || ! Storage::disk('local')->exists($appeal->file_path), 404);
        $accounts = BillingAccount::query()->whereIn('id', DB::table('billing_glosa_appeal_items as a')->join('billing_item_returns as r', 'r.id', '=', 'a.billing_item_return_id')
            ->join('billing_account_items as i', 'i.id', '=', 'r.billing_account_item_id')->where('a.billing_glosa_appeal_id', $appeal->id)->select('i.billing_account_id'))->get();
        $patients = Patient::query()->whereKey($accounts->pluck('patient_id')->unique()->all())->get()->keyBy('id');
        foreach ($accounts as $account) {
            ClinicalAccess::recordRead($request->user(), $patients->get($account->patient_id), $account);
        }
        AuditEvent::record('billing_lot.appeal_downloaded', $lot->tenant_id, $lot, ['sequence' => $sequence, 'sha256' => $appeal->file_sha256]);

        return response((string) Storage::disk('local')->get($appeal->file_path), 200, [
            'Content-Type' => 'application/xml; charset=ISO-8859-1',
            'Content-Disposition' => "attachment; filename=\"lote-{$lot->id}-recurso-{$sequence}-tiss-{$lot->tiss_version}.xml\"",
            'Cache-Control' => 'no-store',
        ]);
    }
}
