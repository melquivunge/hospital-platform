import { Form, Head, Link } from '@inertiajs/react';
import BillingLotController from '@/actions/App/Http/Controllers/BillingLotController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { money } from '@/lib/utils';
import { index } from '@/routes/billing-lots';

type Item = {
    id: number;
    code: string | null;
    name: string;
    quantity: number;
    billedCents: number;
    return: {
        paidCents: number;
        glossedCents: number;
        code: string | null;
        reason: string | null;
        appealable: boolean;
        appeal: {
            sequence: number;
            appealedCents: number;
            justification: string;
            recoveredCents: number | null;
        } | null;
    } | null;
};

type Account = { id: number; items: Item[] };

/** Only the rows with something typed are sent (PHP's max_input_vars would cut a long lot short). */
function filled(
    rows: unknown,
): Record<string, string | Record<string, string>> {
    return Object.fromEntries(
        Object.entries((rows ?? {}) as Record<string, unknown>).filter(
            ([, row]) =>
                (typeof row === 'string'
                    ? [row]
                    : Object.values(row as object)
                ).some((value) => String(value ?? '').trim() !== ''),
        ),
    ) as Record<string, string | Record<string, string>>;
}

/**
 * Step 6.5c1 (ADR-0010): the insurer's return of a sent lot, recorded once. Only the glossed items are filled in
 * (value, TUSS table 38 code, reason); every other item is paid in full. Then the conciliation: billed = paid + glossed.
 * Step 6.5c2: the glosa appeal of glossed items (a TISS file per appeal) and the insurer's answer to each.
 */
export default function BillingLotReturn({
    lot,
    accounts,
    recorded,
    appeals,
    glosaCodes,
}: {
    lot: {
        id: number;
        insurer: string | null;
        competence: string;
        guideType: string;
        totalCents: number;
        unitId: number;
    };
    accounts: Account[];
    recorded: {
        at: string;
        by: string | null;
        paidCents: number;
        glossedCents: number;
        appealingCents: number;
        recoveredCents: number;
    } | null;
    appeals: {
        sequence: number;
        protocol: string;
        appealedCents: number;
        at: string;
    }[];
    glosaCodes: Record<string, string>;
}) {
    const items = accounts.flatMap((account) => account.items);
    const appealable = items.filter((item) => item.return?.appealable);
    const pending = items.filter(
        (item) =>
            item.return?.appeal && item.return.appeal.recoveredCents === null,
    );

    return (
        <>
            <Head title={`Retorno do lote ${lot.id}`} />
            <Page>
                <PageHeader
                    eyebrow={`${lot.insurer ?? 'Convênio'} · ${lot.competence} · ${lot.guideType}`}
                    title={`Retorno do lote ${lot.id}`}
                    description={
                        recorded
                            ? `Registrado em ${recorded.at}${recorded.by ? ` por ${recorded.by}` : ''}.`
                            : 'Marque só os itens glosados no demonstrativo da operadora; os demais ficam pagos integralmente.'
                    }
                />
                {recorded && (
                    <dl className="mb-8 grid gap-3 rounded-xl border bg-card px-5 py-4 sm:grid-cols-3">
                        <Total label="Cobrado" cents={lot.totalCents} />
                        <Total label="Pago" cents={recorded.paidCents} />
                        <Total label="Glosado" cents={recorded.glossedCents} />
                        {appeals.length > 0 && (
                            <>
                                <Total
                                    label="Em recurso"
                                    cents={recorded.appealingCents}
                                />
                                <Total
                                    label="Recuperado no recurso"
                                    cents={recorded.recoveredCents}
                                />
                                <Total
                                    label="Glosa mantida"
                                    cents={
                                        recorded.glossedCents -
                                        recorded.appealingCents -
                                        recorded.recoveredCents
                                    }
                                />
                            </>
                        )}
                    </dl>
                )}
                {recorded ? (
                    <Accounts accounts={accounts} glosaCodes={glosaCodes} />
                ) : (
                    <Form
                        {...BillingLotController.storeReturn.form(lot.id)}
                        errorBag="return"
                        transform={(data) => ({ glosas: filled(data.glosas) })}
                        options={{ preserveScroll: true }}
                        className="grid gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <datalist id="glosa-codes">
                                    {Object.entries(glosaCodes).map(
                                        ([code, term]) => (
                                            <option key={code} value={code}>
                                                {term}
                                            </option>
                                        ),
                                    )}
                                </datalist>
                                <Accounts
                                    accounts={accounts}
                                    glosaCodes={glosaCodes}
                                    errors={errors}
                                />
                                <InputError message={errors.return} />
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11 justify-self-start"
                                >
                                    {processing && <Spinner />}
                                    Registrar retorno
                                </Button>
                                <p className="text-sm text-muted-foreground">
                                    O retorno é registrado uma vez e não muda;
                                    depois dele o lote não é mais cancelado.
                                </p>
                            </>
                        )}
                    </Form>
                )}
                {appeals.length > 0 && (
                    <section aria-labelledby="appeals" className="mb-8">
                        <h2
                            id="appeals"
                            className="mb-2 text-lg font-semibold tracking-tight"
                        >
                            Recursos de glosa
                        </h2>
                        <ul className="divide-y rounded-xl border bg-card">
                            {appeals.map((appeal) => (
                                <li
                                    key={appeal.sequence}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                >
                                    <span className="text-sm">
                                        Recurso {appeal.sequence} · protocolo{' '}
                                        {appeal.protocol} ·{' '}
                                        {money(appeal.appealedCents)} ·{' '}
                                        {appeal.at}
                                    </span>
                                    <a
                                        href={BillingLotController.downloadAppeal.url(
                                            {
                                                lot: lot.id,
                                                sequence: appeal.sequence,
                                            },
                                        )}
                                        className="inline-flex min-h-11 items-center rounded-md border px-4 text-sm font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        Baixar arquivo
                                        <span className="sr-only">
                                            {' '}
                                            do recurso {appeal.sequence}
                                        </span>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {pending.length > 0 && (
                    <AppealResults lotId={lot.id} items={pending} />
                )}
                {appealable.length > 0 && (
                    <AppealForm
                        lotId={lot.id}
                        items={appealable}
                        glosaCodes={glosaCodes}
                    />
                )}
                <Link
                    href={index({ query: { unit: lot.unitId } })}
                    className="mt-8 inline-flex min-h-11 items-center text-sm font-medium text-primary"
                >
                    Voltar aos lotes
                </Link>
            </Page>
        </>
    );
}

function Total({ label, cents }: { label: string; cents: number }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-lg font-semibold tabular-nums">
                {money(cents)}
            </dd>
        </div>
    );
}

function Accounts({
    accounts,
    glosaCodes,
    errors,
}: {
    accounts: Account[];
    glosaCodes: Record<string, string>;
    errors?: Record<string, string>;
}) {
    return accounts.map((account) => (
        <section
            key={account.id}
            aria-labelledby={`account-${account.id}`}
            className="mb-6"
        >
            <h2
                id={`account-${account.id}`}
                className="mb-2 text-lg font-semibold tracking-tight"
            >
                Guia da conta {account.id}
            </h2>
            <ul className="divide-y rounded-xl border bg-card">
                {account.items.map((item) => (
                    <li key={item.id} className="grid gap-2 px-5 py-4">
                        <ItemHeading item={item} />
                        {item.return ? (
                            <ItemReturn
                                value={item.return}
                                glosaCodes={glosaCodes}
                            />
                        ) : (
                            errors && (
                                <GlosaFields item={item} errors={errors} />
                            )
                        )}
                    </li>
                ))}
            </ul>
        </section>
    ));
}

function ItemHeading({ item }: { item: Item }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <span className="grid gap-0.5">
                <span className="font-medium">{item.name}</span>
                <span className="text-sm text-muted-foreground">
                    {item.code ?? 'Sem código'} · quantidade {item.quantity}
                </span>
            </span>
            <span className="font-medium tabular-nums">
                {money(item.billedCents)}
            </span>
        </div>
    );
}

function ItemReturn({
    value,
    glosaCodes,
}: {
    value: NonNullable<Item['return']>;
    glosaCodes: Record<string, string>;
}) {
    if (value.glossedCents === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Pago {money(value.paidCents)}
            </p>
        );
    }

    const appeal = value.appeal;

    return (
        <div className="grid gap-1 text-sm">
            <p>
                Pago {money(value.paidCents)} · glosado{' '}
                <span className="font-medium">{money(value.glossedCents)}</span>{' '}
                · {value.code} {value.code && glosaCodes[value.code]}
                {value.reason && ` · ${value.reason}`}
            </p>
            {appeal && (
                <p className="text-muted-foreground">
                    Recurso {appeal.sequence}: {money(appeal.appealedCents)} ·{' '}
                    {appeal.recoveredCents === null
                        ? 'aguardando a operadora'
                        : appeal.recoveredCents === 0
                          ? 'negado'
                          : `recuperado ${money(appeal.recoveredCents)}`}{' '}
                    · {appeal.justification}
                </p>
            )}
        </div>
    );
}

function GlosaFields({
    item,
    errors,
}: {
    item: Item;
    errors: Record<string, string>;
}) {
    const field = (name: string) => `glosas[${item.id}][${name}]`;
    const error = (name: string) => errors[`glosas.${item.id}.${name}`];
    const id = (name: string) => `glosa-${item.id}-${name}`;
    const invalid = !!(error('amount') || error('code') || error('reason'));

    return (
        <details open={invalid}>
            <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary">
                Item glosado
                <span className="sr-only">: {item.name}</span>
            </summary>
            <div className="mt-2 grid gap-3 sm:grid-cols-[10rem_12rem_1fr]">
                <div className="grid content-start gap-1">
                    <Label htmlFor={id('amount')}>
                        {'Valor glosado (R$)'}
                        <span className="sr-only">, {item.name}</span>
                    </Label>
                    <Input
                        id={id('amount')}
                        name={field('amount')}
                        inputMode="decimal"
                        placeholder={money(item.billedCents).replace(
                            /^R\$\s*/,
                            '',
                        )}
                        maxLength={20}
                        aria-invalid={!!error('amount')}
                        className="h-11"
                    />
                    <InputError message={error('amount')} />
                </div>
                <div className="grid content-start gap-1">
                    <Label htmlFor={id('code')}>
                        {'Código (TUSS 38)'}
                        <span className="sr-only">, {item.name}</span>
                    </Label>
                    <Input
                        id={id('code')}
                        name={field('code')}
                        list="glosa-codes"
                        inputMode="numeric"
                        maxLength={4}
                        aria-invalid={!!error('code')}
                        className="h-11"
                    />
                    <InputError message={error('code')} />
                </div>
                <div className="grid content-start gap-1">
                    <Label htmlFor={id('reason')}>
                        {'Motivo (opcional)'}
                        <span className="sr-only">, {item.name}</span>
                    </Label>
                    <Input
                        id={id('reason')}
                        name={field('reason')}
                        maxLength={500}
                        aria-invalid={!!error('reason')}
                        className="h-11"
                    />
                    <InputError message={error('reason')} />
                </div>
            </div>
        </details>
    );
}

/** Step 6.5c2: a glosa appeal of the items marked, with the insurer's protocol of the lot; one TISS file each. */
function AppealForm({
    lotId,
    items,
    glosaCodes,
}: {
    lotId: number;
    items: Item[];
    glosaCodes: Record<string, string>;
}) {
    return (
        <section aria-labelledby="appeal" className="mb-8">
            <h2
                id="appeal"
                className="mb-1 text-lg font-semibold tracking-tight"
            >
                Recorrer de glosas
            </h2>
            <p className="mb-3 text-sm text-muted-foreground">
                Abra os itens a recorrer e escreva a justificativa. Sem valor, o
                recurso é da glosa toda. Cada item vai em um recurso só.
            </p>
            <Form
                {...BillingLotController.storeAppeal.form(lotId)}
                errorBag="appeal"
                transform={(data) => ({
                    protocol: data.protocol,
                    items: filled(data.items),
                })}
                options={{ preserveScroll: true }}
                className="grid gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid max-w-xs gap-1">
                            <Label htmlFor="appeal-protocol">
                                Protocolo do lote na operadora
                            </Label>
                            <Input
                                id="appeal-protocol"
                                name="protocol"
                                inputMode="numeric"
                                maxLength={12}
                                required
                                aria-invalid={!!errors.protocol}
                                className="h-11"
                            />
                            <InputError message={errors.protocol} />
                        </div>
                        <ul className="divide-y rounded-xl border bg-card">
                            {items.map((item) => {
                                const error = (name: string) =>
                                    errors[`items.${item.id}.${name}`];
                                const glosa = item.return!;

                                return (
                                    <li
                                        key={item.id}
                                        className="grid gap-2 px-5 py-4"
                                    >
                                        <ItemHeading item={item} />
                                        <p className="text-sm">
                                            Glosado {money(glosa.glossedCents)}{' '}
                                            · {glosa.code}{' '}
                                            {glosa.code &&
                                                glosaCodes[glosa.code]}
                                        </p>
                                        <details
                                            open={
                                                !!(
                                                    error('amount') ||
                                                    error('justification')
                                                )
                                            }
                                        >
                                            <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary">
                                                Recorrer
                                                <span className="sr-only">
                                                    : {item.name}
                                                </span>
                                            </summary>
                                            <div className="mt-2 grid gap-3 sm:grid-cols-[10rem_1fr]">
                                                <div className="grid content-start gap-1">
                                                    <Label
                                                        htmlFor={`appeal-${item.id}-amount`}
                                                    >
                                                        {'Valor (R$)'}
                                                        <span className="sr-only">
                                                            , {item.name}
                                                        </span>
                                                    </Label>
                                                    <Input
                                                        id={`appeal-${item.id}-amount`}
                                                        name={`items[${item.id}][amount]`}
                                                        inputMode="decimal"
                                                        placeholder={money(
                                                            glosa.glossedCents,
                                                        ).replace(
                                                            /^R\$\s*/,
                                                            '',
                                                        )}
                                                        maxLength={20}
                                                        aria-invalid={
                                                            !!error('amount')
                                                        }
                                                        className="h-11"
                                                    />
                                                    <InputError
                                                        message={error(
                                                            'amount',
                                                        )}
                                                    />
                                                </div>
                                                <div className="grid content-start gap-1">
                                                    <Label
                                                        htmlFor={`appeal-${item.id}-justification`}
                                                    >
                                                        Justificativa
                                                        <span className="sr-only">
                                                            , {item.name}
                                                        </span>
                                                    </Label>
                                                    <Input
                                                        id={`appeal-${item.id}-justification`}
                                                        name={`items[${item.id}][justification]`}
                                                        maxLength={500}
                                                        aria-invalid={
                                                            !!error(
                                                                'justification',
                                                            )
                                                        }
                                                        className="h-11"
                                                    />
                                                    <InputError
                                                        message={error(
                                                            'justification',
                                                        )}
                                                    />
                                                </div>
                                            </div>
                                        </details>
                                    </li>
                                );
                            })}
                        </ul>
                        <InputError message={errors.appeal} />
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            {processing && <Spinner />}
                            Gerar recurso
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}

/** Step 6.5c2: the insurer's answer to each appealed item: what it recovered, 0 when denied; recorded once. */
function AppealResults({ lotId, items }: { lotId: number; items: Item[] }) {
    return (
        <section aria-labelledby="appeal-results" className="mb-8">
            <h2
                id="appeal-results"
                className="mb-1 text-lg font-semibold tracking-tight"
            >
                Resultado do recurso
            </h2>
            <p className="mb-3 text-sm text-muted-foreground">
                Informe o valor recuperado de cada item respondido (0 quando
                negado). O resultado é registrado uma vez.
            </p>
            <Form
                {...BillingLotController.storeAppealResults.form(lotId)}
                errorBag="appealResult"
                transform={(data) => ({ results: filled(data.results) })}
                options={{ preserveScroll: true }}
                className="grid gap-4"
            >
                {({ processing, errors }) => (
                    <>
                        <ul className="divide-y rounded-xl border bg-card">
                            {items.map((item) => (
                                <li
                                    key={item.id}
                                    className="grid gap-2 px-5 py-4 sm:grid-cols-[1fr_12rem] sm:items-start"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {item.name}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            Recurso{' '}
                                            {item.return!.appeal!.sequence} ·{' '}
                                            {money(
                                                item.return!.appeal!
                                                    .appealedCents,
                                            )}
                                        </span>
                                    </span>
                                    <div className="grid content-start gap-1">
                                        <Label htmlFor={`result-${item.id}`}>
                                            {'Recuperado (R$)'}
                                            <span className="sr-only">
                                                , {item.name}
                                            </span>
                                        </Label>
                                        <Input
                                            id={`result-${item.id}`}
                                            name={`results[${item.id}]`}
                                            inputMode="decimal"
                                            maxLength={20}
                                            aria-invalid={
                                                !!errors[`results.${item.id}`]
                                            }
                                            className="h-11"
                                        />
                                        <InputError
                                            message={
                                                errors[`results.${item.id}`]
                                            }
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <InputError message={errors.appealResult} />
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            {processing && <Spinner />}
                            Registrar resultado
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}

BillingLotReturn.layout = {
    breadcrumbs: [
        { title: 'Lotes TISS', href: index() },
        { title: 'Retorno', href: '#' },
    ],
};
