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
    } | null;
};

/**
 * Step 6.5c1 (ADR-0010): the insurer's return of a sent lot, recorded once. Only the glossed items are filled in
 * (value, TUSS table 38 code, reason); every other item is paid in full. Then the conciliation: billed = paid + glossed.
 */
export default function BillingLotReturn({
    lot,
    accounts,
    recorded,
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
    accounts: { id: number; items: Item[] }[];
    recorded: {
        at: string;
        by: string | null;
        paidCents: number;
        glossedCents: number;
    } | null;
    glosaCodes: Record<string, string>;
}) {
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
                    </dl>
                )}
                {recorded ? (
                    <Accounts accounts={accounts} glosaCodes={glosaCodes} />
                ) : (
                    <Form
                        {...BillingLotController.storeReturn.form(lot.id)}
                        errorBag="return"
                        // Only the glossed items go (PHP's max_input_vars would cut a long lot short).
                        transform={(data) => ({
                            glosas: Object.fromEntries(
                                Object.entries(
                                    (data.glosas ?? {}) as Record<
                                        string,
                                        Record<string, string>
                                    >,
                                ).filter(([, glosa]) =>
                                    Object.values(glosa).some(
                                        (value) => value.trim() !== '',
                                    ),
                                ),
                            ),
                        })}
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
    accounts: { id: number; items: Item[] }[];
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
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <span className="grid gap-0.5">
                                <span className="font-medium">{item.name}</span>
                                <span className="text-sm text-muted-foreground">
                                    {item.code ?? 'Sem código'} · quantidade{' '}
                                    {item.quantity}
                                </span>
                            </span>
                            <span className="font-medium tabular-nums">
                                {money(item.billedCents)}
                            </span>
                        </div>
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

    return (
        <p className="text-sm">
            Pago {money(value.paidCents)} · glosado{' '}
            <span className="font-medium">{money(value.glossedCents)}</span> ·{' '}
            {value.code} {value.code && glosaCodes[value.code]}
            {value.reason && ` · ${value.reason}`}
        </p>
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

BillingLotReturn.layout = {
    breadcrumbs: [
        { title: 'Lotes TISS', href: index() },
        { title: 'Retorno', href: '#' },
    ],
};
