import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as stock } from '@/routes/stock';
import { index } from '@/routes/stock/indicators';

type UnitFigures = {
    id: number;
    name: string;
    below: number;
    expiring: number;
    expired: number;
    losses: Record<string, number>;
};

type LotRow = {
    id: number;
    medication: string;
    lot: string;
    expiresOn: string;
    balance: number;
    stockUnit: string;
};

type Detail = {
    below: {
        id: string;
        medication: string;
        stockUnit: string;
        valid: number;
        minimum: number;
    }[];
    expiring: LotRow[];
    expired: LotRow[];
    losses: {
        medication: string;
        stockUnit: string;
        reason: string;
        quantity: number;
    }[];
};

function Figure({ label, value }: { label: string; value: number }) {
    return (
        <div>
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={`text-lg font-semibold tabular-nums ${value > 0 ? 'text-destructive' : ''}`}
            >
                {value}
            </dd>
        </div>
    );
}

function Section({
    title,
    empty,
    children,
}: {
    title: string;
    empty: boolean;
    children: ReactNode;
}) {
    return (
        <section className="grid content-start gap-3">
            <h3 className="font-semibold">{title}</h3>
            {empty ? (
                <p className="text-sm text-muted-foreground">Nada aqui.</p>
            ) : (
                <ul className="divide-y rounded-xl border bg-card text-sm">
                    {children}
                </ul>
            )}
        </section>
    );
}

/**
 * The stock indicators (step 4c.4c, ADR-0007): one card per unit (the network admin sees all of them) and the
 * chosen unit in detail. No patient data.
 */
export default function StockIndicators({
    unitId,
    stockUnitIds,
    filters,
    reasons,
    units,
    detail,
}: {
    unitId: number;
    stockUnitIds: number[];
    filters: { from: string; to: string };
    reasons: Record<string, string>;
    units: UnitFigures[];
    detail: Detail;
}) {
    const chosen = units.find((unit) => unit.id === unitId);
    const apply = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const field = (name: string) => {
            const value = data.get(name);

            return typeof value === 'string' ? value : '';
        };
        router.get(
            index({
                query: { unit: unitId, from: field('from'), to: field('to') },
            }).url,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Indicadores de estoque" />
            <Page>
                <PageHeader
                    title="Indicadores de estoque"
                    description="Abaixo do mínimo (só lotes válidos), lotes a vencer em 30 dias, vencidos com saldo e perdas no período por motivo."
                />

                <form
                    onSubmit={apply}
                    className="mb-8 flex flex-wrap items-end gap-4 rounded-xl border bg-card p-5"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="from">Perdas de</Label>
                        <Input
                            id="from"
                            name="from"
                            type="date"
                            defaultValue={filters.from}
                            className="h-11"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">até</Label>
                        <Input
                            id="to"
                            name="to"
                            type="date"
                            defaultValue={filters.to}
                            className="h-11"
                        />
                    </div>
                    <Button type="submit" className="min-h-11">
                        Aplicar
                    </Button>
                </form>

                <ul className="mb-10 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {units.map((unit) => {
                        const losses = Object.entries(unit.losses);

                        return (
                            <li key={unit.id}>
                                <Link
                                    href={
                                        index({
                                            query: {
                                                unit: unit.id,
                                                from: filters.from,
                                                to: filters.to,
                                            },
                                        }).url
                                    }
                                    preserveScroll
                                    aria-current={
                                        unit.id === unitId ? 'true' : undefined
                                    }
                                    className={`block rounded-xl border bg-card px-5 py-4 hover:bg-muted/50 ${unit.id === unitId ? 'ring-2 ring-primary' : ''}`}
                                >
                                    <h2 className="font-medium">{unit.name}</h2>
                                    <dl className="mt-2 grid grid-cols-3 gap-2 text-sm">
                                        <Figure
                                            label="Abaixo do mínimo"
                                            value={unit.below}
                                        />
                                        <Figure
                                            label="Vencem em 30 dias"
                                            value={unit.expiring}
                                        />
                                        <Figure
                                            label="Vencidos com saldo"
                                            value={unit.expired}
                                        />
                                    </dl>
                                    <p className="mt-2 text-sm text-muted-foreground">
                                        {losses.length === 0
                                            ? 'Sem perdas no período.'
                                            : `Perdas (registros): ${losses.map(([reason, records]) => `${reasons[reason] ?? reason} ${records}`).join(' · ')}`}
                                    </p>
                                </Link>
                            </li>
                        );
                    })}
                </ul>

                {chosen && (
                    <section className="grid gap-6">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="text-lg font-semibold">
                                {chosen.name}
                            </h2>
                            {stockUnitIds.includes(chosen.id) && (
                                <Link
                                    href={
                                        stock({ query: { unit: chosen.id } })
                                            .url
                                    }
                                    className="inline-flex min-h-11 items-center text-sm font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    Abrir o estoque da unidade
                                </Link>
                            )}
                        </div>
                        <div className="grid gap-8 lg:grid-cols-2">
                            <Section
                                title="Abaixo do mínimo"
                                empty={detail.below.length === 0}
                            >
                                {detail.below.map((row) => (
                                    <li
                                        key={row.id}
                                        className="flex flex-wrap justify-between gap-2 px-5 py-3"
                                    >
                                        <span>{row.medication}</span>
                                        <span className="tabular-nums">
                                            {row.valid} de {row.minimum}{' '}
                                            {row.stockUnit}
                                        </span>
                                    </li>
                                ))}
                            </Section>
                            <Section
                                title="Perdas no período"
                                empty={detail.losses.length === 0}
                            >
                                {detail.losses.map((row) => (
                                    <li
                                        key={`${row.medication}-${row.reason}`}
                                        className="flex flex-wrap justify-between gap-2 px-5 py-3"
                                    >
                                        <span>
                                            {row.medication} ·{' '}
                                            {reasons[row.reason] ?? row.reason}
                                        </span>
                                        <span className="tabular-nums">
                                            {row.quantity} {row.stockUnit}
                                        </span>
                                    </li>
                                ))}
                            </Section>
                            {(
                                [
                                    ['Vencem em 30 dias', detail.expiring],
                                    ['Vencidos com saldo', detail.expired],
                                ] as const
                            ).map(([title, rows]) => (
                                <Section
                                    key={title}
                                    title={title}
                                    empty={rows.length === 0}
                                >
                                    {rows.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex flex-wrap justify-between gap-2 px-5 py-3"
                                        >
                                            <span>
                                                {row.medication} · lote{' '}
                                                {row.lot} · {row.expiresOn}
                                            </span>
                                            <span className="tabular-nums">
                                                {row.balance} {row.stockUnit}
                                            </span>
                                        </li>
                                    ))}
                                </Section>
                            ))}
                        </div>
                    </section>
                )}
            </Page>
        </>
    );
}
