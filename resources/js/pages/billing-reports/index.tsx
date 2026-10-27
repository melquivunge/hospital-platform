import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/utils';
import { csv, index } from '@/routes/billing-reports';

type Production = {
    group: string;
    accounts: number;
    items: number;
    totalCents: number;
    pricelessItems: number;
};
type Pending = { label: string; accounts: number; totalCents: number };
type Glosa = {
    code: string;
    term: string;
    items: number;
    glossedCents: number;
    appealingCents: number;
    recoveredCents: number;
};
type Received = {
    group: string;
    label: string;
    count: number;
    totalCents: number;
};

/**
 * Step 6.6 (ADR-0010): the unit manager's billing reports, numbers only: production by modality in the period, the
 * accounts pending now, the glosas by reason and what was received; the CSV has the same numbers.
 */
export default function BillingReports({
    units,
    unitId,
    filters,
    production,
    pending,
    glosas,
    received,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    filters: { from: string; to: string };
    production: Production[];
    pending: Pending[];
    glosas: Glosa[];
    received: Received[];
}) {
    const unit = units.find((option) => option.id === unitId);
    const query = { unit: unitId, from: filters.from, to: filters.to };
    const apply = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const field = (name: string) => {
            const value = data.get(name);

            return typeof value === 'string' ? value : '';
        };
        router.get(
            index({
                query: {
                    unit: field('unit') || unitId,
                    from: field('from'),
                    to: field('to'),
                },
            }).url,
            {},
            { preserveScroll: true },
        );
    };
    const sum = <T,>(rows: T[], value: (row: T) => number) =>
        rows.reduce((total, row) => total + value(row), 0);

    return (
        <>
            <Head title="Relatórios de faturamento" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Relatórios de faturamento"
                    description="Produção, glosas e recebido no período, no horário da unidade; contas pendentes hoje. Só números, sem dado de paciente."
                    actions={
                        <Button asChild variant="outline" className="min-h-11">
                            <a href={csv({ query }).url} download>
                                <Download aria-hidden="true" />
                                Exportar planilha (CSV)
                            </a>
                        </Button>
                    }
                />

                <form
                    onSubmit={apply}
                    className="mb-8 grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2 lg:grid-cols-4"
                >
                    {units.length > 1 && (
                        <div className="grid gap-2">
                            <Label htmlFor="unit">Unidade</Label>
                            <select
                                id="unit"
                                name="unit"
                                defaultValue={unitId}
                                className={selectClassName}
                            >
                                {units.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="from">De</Label>
                        <Input
                            id="from"
                            name="from"
                            type="date"
                            defaultValue={filters.from}
                            className="h-11"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">Até</Label>
                        <Input
                            id="to"
                            name="to"
                            type="date"
                            defaultValue={filters.to}
                            className="h-11"
                        />
                    </div>
                    <div className="self-end">
                        <Button type="submit" className="min-h-11">
                            Aplicar
                        </Button>
                    </div>
                </form>

                <Section
                    title="Produção por modalidade"
                    note="Itens realizados no período; estornos descontados."
                    empty={
                        production.length === 0 &&
                        'Nenhum item realizado no período.'
                    }
                    total={sum(production, (row) => row.totalCents)}
                >
                    {production.map((row, position) => (
                        <Row
                            key={position}
                            title={row.group}
                            detail={`${row.accounts} ${row.accounts === 1 ? 'conta' : 'contas'} · ${row.items} ${row.items === 1 ? 'item' : 'itens'}${row.pricelessItems > 0 ? ` · ${row.pricelessItems} sem preço` : ''}`}
                            cents={row.totalCents}
                        />
                    ))}
                </Section>

                <Section
                    title="Contas pendentes hoje"
                    note="Independe do período."
                >
                    {pending.map((row) => (
                        <Row
                            key={row.label}
                            title={row.label}
                            detail={`${row.accounts} ${row.accounts === 1 ? 'conta' : 'contas'}`}
                            cents={row.totalCents}
                        />
                    ))}
                </Section>

                <Section
                    title="Glosas por motivo"
                    note="Retornos registrados no período, pela tabela TUSS 38."
                    empty={glosas.length === 0 && 'Nenhuma glosa no período.'}
                    total={sum(glosas, (row) => row.glossedCents)}
                >
                    {glosas.map((row) => (
                        <Row
                            key={row.code}
                            title={`${row.code} ${row.term}`}
                            detail={`${row.items} ${row.items === 1 ? 'item' : 'itens'} · em recurso ${money(row.appealingCents)} · recuperado ${money(row.recoveredCents)}`}
                            cents={row.glossedCents}
                        />
                    ))}
                </Section>

                <Section
                    title="Recebido"
                    note="Pagamentos particulares, valores pagos nos retornos e recuperados em recurso, registrados no período."
                    total={sum(received, (row) => row.totalCents)}
                >
                    {received.map((row, position) => (
                        <Row
                            key={position}
                            title={row.group}
                            detail={`${row.label} · ${row.count}`}
                            cents={row.totalCents}
                        />
                    ))}
                </Section>
            </Page>
        </>
    );
}

function Section({
    title,
    note,
    empty,
    total,
    children,
}: {
    title: string;
    note: string;
    empty?: string | false;
    total?: number;
    children: ReactNode;
}) {
    return (
        <section className="mb-8 grid gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-lg font-semibold">{title}</h2>
                {total !== undefined && !empty && (
                    <p className="font-semibold tabular-nums">
                        Total {money(total)}
                    </p>
                )}
            </div>
            <p className="text-sm text-muted-foreground">{note}</p>
            {empty ? (
                <p className="rounded-xl border bg-card px-5 py-8 text-center text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <ul className="divide-y rounded-xl border bg-card">
                    {children}
                </ul>
            )}
        </section>
    );
}

function Row({
    title,
    detail,
    cents,
}: {
    title: string;
    detail: string;
    cents: number;
}) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <span className="grid gap-0.5">
                <span className="font-medium">{title}</span>
                <span className="text-sm text-muted-foreground">{detail}</span>
            </span>
            <span className="font-medium tabular-nums">{money(cents)}</span>
        </li>
    );
}

BillingReports.layout = {
    breadcrumbs: [{ title: 'Relatórios de faturamento', href: index() }],
};
