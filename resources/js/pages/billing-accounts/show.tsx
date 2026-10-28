import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import BillingAccountController from '@/actions/App/Http/Controllers/BillingAccountController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SigtapProcedureField } from '@/components/sigtap-procedure-field';
import { money } from '@/lib/utils';
import { index } from '@/routes/billing-accounts';
import type { AccountSummary } from './index';
import { stayModality } from './index';

type Origin =
    | 'visit'
    | 'exam_order_item'
    | 'surgery'
    | 'admission_day'
    | 'stock_movement'
    | 'dispensing'
    | 'dispensing_return'
    | 'manual'
    | 'compensation'
    | 'aih_principal';

type Item = {
    id: number;
    day: string;
    origin: Origin;
    code: string | null;
    name: string;
    quantity: number;
    unitPriceCents: number | null;
    needsAuthorization: boolean;
    reason: string | null;
    author: string | null;
    compensated: boolean;
    authorization: {
        status: 'authorized' | 'denied' | 'urgency';
        number: string | null;
        validUntil: string | null;
        reason: string | null;
        author: string | null;
    } | null;
};

/** Where each item came from, by its type only (ADR-0010: never the record's content). */
const originLabels: Record<Origin, string> = {
    visit: 'Consulta',
    exam_order_item: 'Exame liberado',
    surgery: 'Cirurgia concluída',
    admission_day: 'Diária',
    stock_movement: 'Material da cirurgia',
    dispensing: 'Medicamento dispensado',
    dispensing_return: 'Devolução de medicamento',
    manual: 'Item manual',
    compensation: 'Estorno',
    aih_principal: 'Procedimento principal da AIH',
};

/**
 * One account (step 6.2c, ADR-0010): the patient, the stay, the modality and card, and the items with their day,
 * code, quantity, frozen price and origin, with what is pending (no price; an authorization the contract asks for).
 */
export default function BillingAccountShow({
    account,
    items,
    canChange,
    canCompensate,
    canClose,
    complements,
    payment,
    canReceive,
    methods,
    billableItems,
    today,
    diagnoses,
    susPending,
    aih,
    canChangePrincipal,
}: {
    account: AccountSummary & {
        card: string | null;
        cardValidUntil: string | null;
    };
    items: Item[];
    canChange: boolean;
    canCompensate: boolean;
    canClose: boolean;
    complements: number[];
    payment: {
        method: string;
        amountCents: number;
        receivedAt: string;
        by: string | null;
    } | null;
    canReceive: boolean;
    methods: Record<string, string>;
    billableItems: { id: number; name: string; code: string | null }[];
    today: string;
    diagnoses: { code: string; name: string | null; principal: boolean }[];
    susPending: string[];
    aih: AihData | null;
    canChangePrincipal: boolean;
}) {
    // A compensation, and the item it compensates, no longer count as pending.
    const counts = items.filter(
        (item) => item.origin !== 'compensation' && !item.compensated,
    );
    const withoutPrice = counts.filter(
        (item) => item.unitPriceCents === null,
    ).length;
    const authorizations = counts.filter(waitsForAuthorization).length;

    return (
        <>
            <Head title="Conta" />
            <Page>
                <PageHeader
                    eyebrow={`Conta ${account.id} · aberta em ${account.openedAt}`}
                    title={account.patient ?? 'Paciente'}
                    description={`${account.stay} · ${stayModality(account)}`}
                />
                {account.modality === 'sus' && (
                    <section
                        aria-labelledby="diagnoses"
                        className="mb-6 rounded-xl border bg-card px-5 py-4"
                    >
                        <h2 id="diagnoses" className="font-medium">
                            CID-10
                        </h2>
                        {diagnoses.length === 0 ? (
                            <p className="mt-1 text-sm text-muted-foreground">
                                Ainda sem CID: quem atende informa ao encerrar o
                                atendimento ou na alta.
                            </p>
                        ) : (
                            <ul className="mt-1 grid gap-1 text-sm">
                                {diagnoses.map((diagnosis) => (
                                    <li key={diagnosis.code}>
                                        <span className="font-medium tabular-nums">
                                            {diagnosis.code}
                                        </span>{' '}
                                        {diagnosis.name}
                                        {diagnosis.principal && ' (principal)'}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
                {aih && (
                    <AihNumber
                        accountId={account.id}
                        aih={aih}
                        canChangePrincipal={canChangePrincipal}
                    />
                )}
                {susPending.length > 0 && (
                    <section
                        aria-labelledby="sus-pending"
                        className="mb-6 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4 dark:border-amber-800 dark:bg-amber-950/40"
                    >
                        <h2 id="sus-pending" className="font-medium">
                            Pendências do SUS
                        </h2>
                        <ul className="mt-1 grid list-disc gap-1 pl-5 text-sm">
                            {susPending.map((problem) => (
                                <li key={problem}>{problem}</li>
                            ))}
                        </ul>
                    </section>
                )}
                <CloseBar
                    account={account}
                    canClose={canClose}
                    complements={complements}
                />
                {(payment !== null || canReceive) && (
                    <Payment
                        accountId={account.id}
                        payment={payment}
                        methods={methods}
                    />
                )}
                <dl className="mb-8 grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-3">
                    <div className="grid gap-1">
                        <dt className="text-sm text-muted-foreground">Total</dt>
                        <dd className="text-2xl font-semibold tabular-nums">
                            {money(account.totalCents)}
                        </dd>
                    </div>
                    <div className="grid gap-1">
                        <dt className="text-sm text-muted-foreground">
                            Pendências
                        </dt>
                        <dd>
                            {withoutPrice === 0 && authorizations === 0 ? (
                                'Nenhuma'
                            ) : (
                                <ul className="grid gap-0.5">
                                    {withoutPrice > 0 && (
                                        <li>
                                            {withoutPrice}{' '}
                                            {withoutPrice === 1
                                                ? 'item sem preço'
                                                : 'itens sem preço'}
                                        </li>
                                    )}
                                    {authorizations > 0 && (
                                        <li>
                                            {authorizations}{' '}
                                            {authorizations === 1
                                                ? 'item exige autorização'
                                                : 'itens exigem autorização'}
                                        </li>
                                    )}
                                </ul>
                            )}
                        </dd>
                    </div>
                    {account.card !== null && (
                        <div className="grid gap-1">
                            <dt className="text-sm text-muted-foreground">
                                Carteira
                            </dt>
                            <dd className="tabular-nums">
                                {account.card}
                                {account.cardValidUntil &&
                                    ` · válida até ${account.cardValidUntil}`}
                            </dd>
                        </div>
                    )}
                </dl>

                <section aria-labelledby="items-heading" className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2
                            id="items-heading"
                            className="text-lg font-semibold"
                        >
                            Itens
                        </h2>
                    </div>
                    {canChange && account.insurer !== null && (
                        <Authorize
                            accountId={account.id}
                            items={items.filter(
                                (item) =>
                                    item.needsAuthorization &&
                                    item.origin !== 'compensation' &&
                                    !item.compensated,
                            )}
                        />
                    )}
                    {canChange && (
                        <AddItem
                            accountId={account.id}
                            billableItems={billableItems}
                            today={today}
                        />
                    )}
                    {items.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                            Nenhum item ainda. Os itens entram quando o
                            atendimento registra consulta, exame, cirurgia,
                            diária, material ou medicamento.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border bg-card">
                            {items.map((item) => (
                                <li
                                    key={item.id}
                                    className="grid gap-2 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {item.name}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {item.day} ·{' '}
                                            {originLabels[item.origin]}
                                            {item.code && ` · ${item.code}`}
                                        </span>
                                        {item.reason !== null && (
                                            <span className="text-sm text-muted-foreground">
                                                Motivo: {item.reason}
                                                {item.author &&
                                                    ` · por ${item.author}`}
                                            </span>
                                        )}
                                        {(isPending(item) ||
                                            item.compensated ||
                                            item.authorization !== null) && (
                                            <span className="flex flex-wrap gap-2 pt-1">
                                                {isPending(item) &&
                                                    item.unitPriceCents ===
                                                        null && (
                                                        <Pending>
                                                            Sem preço
                                                        </Pending>
                                                    )}
                                                {item.authorization && (
                                                    <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                                        {
                                                            answerLabels[
                                                                item
                                                                    .authorization
                                                                    .status
                                                            ]
                                                        }
                                                        {item.authorization
                                                            .number &&
                                                            ` nº ${item.authorization.number} até ${item.authorization.validUntil}`}
                                                        {item.authorization
                                                            .reason &&
                                                            `: ${item.authorization.reason}`}
                                                    </span>
                                                )}
                                                {isPending(item) &&
                                                    waitsForAuthorization(
                                                        item,
                                                    ) && (
                                                        <Pending>
                                                            Exige autorização
                                                        </Pending>
                                                    )}
                                                {item.compensated && (
                                                    <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                                        Estornado
                                                    </span>
                                                )}
                                            </span>
                                        )}
                                    </span>
                                    <span className="grid text-sm tabular-nums sm:text-right">
                                        <span className="font-medium">
                                            {item.unitPriceCents === null
                                                ? '—'
                                                : money(
                                                      item.quantity *
                                                          item.unitPriceCents,
                                                  )}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {item.quantity} ×{' '}
                                            {item.unitPriceCents === null
                                                ? 'sem preço'
                                                : money(item.unitPriceCents)}
                                        </span>
                                    </span>
                                    {canCompensate &&
                                        item.origin !== 'compensation' &&
                                        !item.compensated && (
                                            <Compensate item={item} />
                                        )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}

/** Step 6.3a: a manual item from the network's billable items, with the day, quantity and reason. */
function AddItem({
    accountId,
    billableItems,
    today,
}: {
    accountId: number;
    billableItems: { id: number; name: string; code: string | null }[];
    today: string;
}) {
    return (
        <details className="rounded-xl border bg-card px-5 py-2">
            <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                Incluir item manual
            </summary>
            <Form
                {...BillingAccountController.storeItem.form(accountId)}
                errorBag="item"
                resetOnSuccess
                options={{ preserveScroll: true }}
                className="grid gap-4 py-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-4 sm:grid-cols-[1fr_auto_auto]">
                            <Field
                                id="billing_item_id"
                                label="Item"
                                error={errors.billing_item_id}
                            >
                                <select
                                    id="billing_item_id"
                                    name="billing_item_id"
                                    required
                                    defaultValue=""
                                    className={selectClassName}
                                >
                                    <option value="" disabled>
                                        {billableItems.length === 0
                                            ? 'Nenhum item cobrável ativo'
                                            : 'Selecione'}
                                    </option>
                                    {billableItems.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                            {option.code && ` (${option.code})`}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                id="performed_on"
                                label="Data"
                                error={errors.performed_on}
                            >
                                <Input
                                    id="performed_on"
                                    name="performed_on"
                                    type="date"
                                    required
                                    max={today}
                                    defaultValue={today}
                                    className="h-11"
                                />
                            </Field>
                            <Field
                                id="quantity"
                                label="Quantidade"
                                error={errors.quantity}
                            >
                                <Input
                                    id="quantity"
                                    name="quantity"
                                    type="number"
                                    required
                                    min={1}
                                    max={1000}
                                    defaultValue={1}
                                    inputMode="numeric"
                                    className="h-11 sm:w-28"
                                />
                            </Field>
                        </div>
                        <Field id="reason" label="Motivo" error={errors.reason}>
                            <Input
                                id="reason"
                                name="reason"
                                required
                                maxLength={500}
                                className="h-11"
                            />
                        </Field>
                        <p className="text-sm text-muted-foreground">
                            O preço vem da tabela do dia para a modalidade da
                            conta. O item fica com o motivo e o seu nome.
                        </p>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            Incluir item
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}

/** Step 6.3a: an item is never deleted; it is compensated once, with a reason. */
function Compensate({ item }: { item: Item }) {
    const id = `compensate-${item.id}`;

    return (
        <details className="sm:col-span-2">
            <summary className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary">
                Estornar
            </summary>
            <Form
                {...BillingAccountController.compensate.form(item.id)}
                errorBag={`compensate${item.id}`}
                options={{ preserveScroll: true }}
                aria-label={`Estornar: ${item.name}, ${item.day}`}
                className="grid gap-3 pt-2 sm:grid-cols-[1fr_auto] sm:items-end"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            id={id}
                            label="Motivo do estorno"
                            error={errors.reason}
                        >
                            <Input
                                id={id}
                                name="reason"
                                required
                                maxLength={500}
                                className="h-11"
                            />
                        </Field>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Estornar item
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

/**
 * Step 6.4a: the account's state and its closing (once the stay ended and nothing is pending), and the complementary
 * accounts that receive what comes after it closed.
 */
function CloseBar({
    account,
    canClose,
    complements,
}: {
    account: AccountSummary;
    canClose: boolean;
    complements: number[];
}) {
    const others = complements.filter((id) => id !== account.id);

    return (
        <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-5 py-4">
            <div className="grid gap-1 text-sm">
                <span className="font-medium">
                    {account.status === 'closed'
                        ? `Fechada em ${account.closedAt}`
                        : 'Aberta'}
                    {account.complementOf !== null && (
                        <>
                            {' · complementar da '}
                            <AccountLink id={account.complementOf} />
                        </>
                    )}
                </span>
                {account.status === 'closed' && (
                    <span className="text-muted-foreground">
                        Conta fechada não muda. O que chegar depois vai para a
                        conta complementar; um estorno também.
                    </span>
                )}
                {others.length > 0 && (
                    <span className="text-muted-foreground">
                        Complementares:{' '}
                        {others.map((id, index) => (
                            <span key={id}>
                                {index > 0 && ', '}
                                <AccountLink id={id} />
                            </span>
                        ))}
                    </span>
                )}
            </div>
            {account.status === 'open' && (
                <Form
                    {...BillingAccountController.close.form(account.id)}
                    errorBag="close"
                    options={{ preserveScroll: true }}
                    onBefore={() =>
                        window.confirm(
                            'Fechar a conta? Depois de fechada, ela não muda.',
                        )
                    }
                    className="grid justify-items-end gap-1"
                >
                    {({ processing, errors }) => (
                        <>
                            <Button
                                type="submit"
                                disabled={processing || !canClose}
                                className="min-h-11"
                            >
                                Fechar conta
                            </Button>
                            {!canClose && (
                                <span className="text-xs text-muted-foreground">
                                    Fecha depois do encerramento ou da alta.
                                </span>
                            )}
                            <InputError message={errors.close} />
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

/** Step 6.4b: a closed private account's payment (informative) and its receipt. */
function Payment({
    accountId,
    payment,
    methods,
}: {
    accountId: number;
    payment: {
        method: string;
        amountCents: number;
        receivedAt: string;
        by: string | null;
    } | null;
    methods: Record<string, string>;
}) {
    return (
        <section
            aria-label="Recebimento"
            className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-5 py-4"
        >
            {payment !== null ? (
                <>
                    <span className="grid gap-1 text-sm">
                        <span className="font-medium">
                            Recebido {money(payment.amountCents)} ·{' '}
                            {payment.method}
                        </span>
                        <span className="text-muted-foreground">
                            Em {payment.receivedAt}
                            {payment.by && ` · por ${payment.by}`}
                        </span>
                    </span>
                    <Button asChild variant="outline" className="min-h-11">
                        <a
                            href={BillingAccountController.receipt.url(
                                accountId,
                            )}
                        >
                            Baixar recibo (PDF)
                        </a>
                    </Button>
                </>
            ) : (
                <Form
                    {...BillingAccountController.receive.form(accountId)}
                    errorBag="payment"
                    options={{ preserveScroll: true }}
                    className="flex w-full flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                id="method"
                                label="Forma de pagamento"
                                error={errors.method}
                            >
                                <select
                                    id="method"
                                    name="method"
                                    required
                                    defaultValue=""
                                    className={selectClassName}
                                >
                                    <option value="" disabled>
                                        Selecione
                                    </option>
                                    {Object.entries(methods).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </Field>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Registrar recebimento
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}

function AccountLink({ id }: { id: number }) {
    return (
        <Link
            href={BillingAccountController.show.url(id)}
            className="text-primary underline-offset-4 hover:underline"
        >
            conta {id}
        </Link>
    );
}

/** What still holds the account: not a compensation, nor an item already compensated. */
function isPending(item: Item): boolean {
    return (
        item.origin !== 'compensation' &&
        !item.compensated &&
        (item.unitPriceCents === null || waitsForAuthorization(item))
    );
}

/** Step 6.3b: needs the insurer's authorization and has neither it nor an urgency. */
function waitsForAuthorization(item: Item): boolean {
    return (
        item.needsAuthorization &&
        item.authorization?.status !== 'authorized' &&
        item.authorization?.status !== 'urgency'
    );
}

const answerLabels = {
    authorized: 'Autorizada',
    denied: 'Negada',
    urgency: 'Urgência',
} as const;

/** Step 6.3b: the insurer's answer, by item or by guide (several items, one number). */
function Authorize({ accountId, items }: { accountId: number; items: Item[] }) {
    const [status, setStatus] = useState<'authorized' | 'denied' | 'urgency'>(
        'authorized',
    );

    if (items.length === 0) {
        return null;
    }

    return (
        <details className="rounded-xl border bg-card px-5 py-2">
            <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                Registrar resposta do convênio
            </summary>
            <Form
                {...BillingAccountController.authorizeItems.form(accountId)}
                errorBag="authorization"
                resetOnSuccess
                // The form resets to "Autorizada"; the fields shown follow it.
                onSuccess={() => setStatus('authorized')}
                options={{ preserveScroll: true }}
                className="grid gap-4 py-3"
            >
                {({ processing, errors }) => (
                    <>
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                Itens (uma guia pode cobrir vários)
                            </legend>
                            {items.map((item) => (
                                <label
                                    key={item.id}
                                    className="flex min-h-11 items-center gap-3 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        name="item_ids[]"
                                        value={item.id}
                                        className="size-4"
                                    />
                                    <span>
                                        {item.name} · {item.day}
                                        {item.authorization &&
                                            ` · ${answerLabels[item.authorization.status]}`}
                                    </span>
                                </label>
                            ))}
                            <InputError message={errors.item_ids} />
                        </fieldset>
                        <Field
                            id="status"
                            label="Resposta"
                            error={errors.status}
                        >
                            <select
                                id="status"
                                name="status"
                                value={status}
                                onChange={(event) =>
                                    setStatus(
                                        event.target.value as typeof status,
                                    )
                                }
                                className={selectClassName}
                            >
                                <option value="authorized">Autorizada</option>
                                <option value="denied">Negada</option>
                                <option value="urgency">
                                    Urgência (sem autorização)
                                </option>
                            </select>
                        </Field>
                        {status === 'authorized' ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="number"
                                    label="Número da autorização ou guia"
                                    error={errors.number}
                                >
                                    <Input
                                        id="number"
                                        name="number"
                                        required
                                        maxLength={40}
                                        autoComplete="off"
                                        className="h-11"
                                    />
                                </Field>
                                <Field
                                    id="valid_until"
                                    label="Validade"
                                    error={errors.valid_until}
                                >
                                    <Input
                                        id="valid_until"
                                        name="valid_until"
                                        type="date"
                                        required
                                        className="h-11"
                                    />
                                </Field>
                            </div>
                        ) : (
                            <Field
                                id="authorization-reason"
                                label="Motivo"
                                error={errors.reason}
                            >
                                <Input
                                    id="authorization-reason"
                                    name="reason"
                                    required
                                    maxLength={500}
                                    className="h-11"
                                />
                            </Field>
                        )}
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            Registrar resposta
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}

type AihData = {
    number: string | null;
    authorizer: string | null;
    canRecord: boolean;
    principal: {
        procedure: string;
        fromReport: boolean;
        reason: string | null;
    } | null;
};

/**
 * Step 6b.5b1: the number of the AIH the manager authorized; corrected while the account is open. Step 6b.5b2: the
 * principal procedure (the report's, or billing's change with its reason).
 */
function AihNumber({
    accountId,
    aih,
    canChangePrincipal,
}: {
    accountId: number;
    aih: AihData;
    canChangePrincipal: boolean;
}) {
    return (
        <section
            aria-labelledby="aih-heading"
            className="mb-6 grid gap-3 rounded-xl border bg-card px-5 py-4"
        >
            <div>
                <h2 id="aih-heading" className="font-medium">
                    AIH
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {aih.number ? (
                        <>
                            Número autorizado:{' '}
                            <span className="font-medium text-foreground tabular-nums">
                                {aih.number}
                            </span>
                            {aih.authorizer &&
                                ` · autorizada pelo CNS ${aih.authorizer}`}
                        </>
                    ) : (
                        'Aguardando o número da AIH autorizada pelo gestor.'
                    )}
                </p>
                <p className="mt-1 text-sm text-muted-foreground">
                    {aih.principal ? (
                        <>
                            Procedimento principal:{' '}
                            <span className="font-medium text-foreground">
                                {aih.principal.procedure}
                            </span>{' '}
                            {aih.principal.fromReport
                                ? '(do laudo)'
                                : `(trocado pelo faturamento: ${aih.principal.reason})`}
                        </>
                    ) : (
                        'Sem procedimento principal: aguardando o laudo da AIH.'
                    )}
                </p>
            </div>
            {aih.canRecord && (
                <Form
                    {...BillingAccountController.recordAihNumber.form(
                        accountId,
                    )}
                    resetOnSuccess
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="aih_number">
                                    {aih.number
                                        ? 'Corrigir o número'
                                        : 'Número da AIH (13 dígitos)'}
                                </Label>
                                <Input
                                    id="aih_number"
                                    name="aih_number"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    required
                                    maxLength={20}
                                    aria-invalid={!!errors.aih_number}
                                    aria-describedby={
                                        errors.aih_number
                                            ? 'aih_number-error'
                                            : undefined
                                    }
                                    className="h-11 w-56 tabular-nums"
                                />
                                <InputError
                                    id="aih_number-error"
                                    message={errors.aih_number}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="aih_authorizer_cns">
                                    CNS do médico autorizador
                                </Label>
                                <Input
                                    id="aih_authorizer_cns"
                                    name="aih_authorizer_cns"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    required
                                    maxLength={20}
                                    aria-invalid={!!errors.aih_authorizer_cns}
                                    className="h-11 w-56 tabular-nums"
                                />
                                <InputError
                                    message={errors.aih_authorizer_cns}
                                />
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Registrar
                            </Button>
                        </>
                    )}
                </Form>
            )}
            {canChangePrincipal && (
                <details className="rounded-lg border px-4 py-2">
                    <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                        Trocar o procedimento principal
                    </summary>
                    <Form
                        {...BillingAccountController.changeAihPrincipal.form(
                            accountId,
                        )}
                        errorBag="principal"
                        resetOnSuccess
                        className="grid gap-4 py-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <SigtapProcedureField
                                    label="Novo procedimento principal (SIGTAP)"
                                    error={errors.procedure_code}
                                />
                                <Field
                                    id="principal_reason"
                                    label="Motivo"
                                    error={errors.reason}
                                >
                                    <Input
                                        id="principal_reason"
                                        name="reason"
                                        required
                                        maxLength={500}
                                        placeholder="Ex.: cirurgia realizada no lugar do tratamento clínico"
                                        className="h-11"
                                    />
                                </Field>
                                <p className="text-sm text-muted-foreground">
                                    O item do procedimento anterior é estornado
                                    e o novo entra pelo valor da competência da
                                    internação.
                                </p>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    className="min-h-11 justify-self-start"
                                >
                                    Trocar procedimento
                                </Button>
                            </>
                        )}
                    </Form>
                </details>
            )}
        </section>
    );
}

function Pending({ children }: { children: string }) {
    return (
        <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">
            {children}
        </span>
    );
}

BillingAccountShow.layout = {
    breadcrumbs: [
        { title: 'Contas', href: index() },
        { title: 'Conta', href: '#' },
    ],
};
