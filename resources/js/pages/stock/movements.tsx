import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { FormEvent } from 'react';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { csv, index } from '@/routes/stock/movements';

type Filters = {
    from: string;
    to: string;
    item: string | null;
    controlled: boolean;
};

type Summary = {
    key: string;
    name: string;
    stockUnit: string;
    controlled: boolean;
    entry: number;
    dispensing: number;
    return: number;
    discard: number;
    adjustment: number;
    surgery: number;
    surgery_return: number;
};

type Movement = {
    id: number;
    at: string;
    medication: string;
    controlled: boolean;
    lot: string;
    expiresOn: string;
    type: string;
    in: number | '';
    out: number | '';
    reason: string;
    user: string;
};

const signed = (value: number) =>
    value > 0 ? `+${value}` : value < 0 ? `−${-value}` : '0';

function Controlled() {
    return (
        <span className="ml-2 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
            Controlado
        </span>
    );
}

/**
 * The stock movements of a unit by period and medication or material (step 4c.4b, ADR-0007; step 5b.6b), controlled first,
 * for the manual bookkeeping; the CSV has the same rows. No patient data.
 */
export default function StockMovements({
    units,
    unitId,
    filters,
    options,
    summary,
    movements,
    pages,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    filters: Filters;
    options: { key: string; name: string; controlled: boolean }[];
    summary: Summary[];
    movements: Movement[];
    pages: { newer: string | null; older: string | null; total: number };
}) {
    const unit = units.find((option) => option.id === unitId);
    const query = {
        unit: unitId,
        from: filters.from,
        to: filters.to,
        ...(filters.item ? { item: filters.item } : {}),
        ...(filters.controlled ? { controlled: 1 } : {}),
    };
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
                    ...(field('item') ? { item: field('item') } : {}),
                    ...(field('controlled') ? { controlled: 1 } : {}),
                },
            }).url,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Movimentos de estoque" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Movimentos de estoque"
                    description="Entradas, dispensações, devoluções, descartes, ajustes e consumo em cirurgia no período, no horário da unidade. Apoio à escrituração; sem dado de paciente."
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
                    className="mb-8 grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2 lg:grid-cols-5"
                >
                    {units.length > 1 && (
                        <div className="grid gap-2">
                            <Label htmlFor="unit">Unidade</Label>
                            <select
                                id="unit"
                                name="unit"
                                defaultValue={unitId}
                                onChange={(event) =>
                                    // Another unit: its own medications, so the medication filter starts over.
                                    router.get(
                                        index({
                                            query: {
                                                unit: event.target.value,
                                                from: filters.from,
                                                to: filters.to,
                                            },
                                        }).url,
                                    )
                                }
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
                    <div className="grid gap-2">
                        <Label htmlFor="item">Medicamento ou material</Label>
                        <select
                            id="item"
                            name="item"
                            defaultValue={filters.item ?? ''}
                            className={selectClassName}
                        >
                            <option value="">Todos</option>
                            {options.map((option) => (
                                <option key={option.key} value={option.key}>
                                    {option.name}
                                    {option.controlled ? ' (controlado)' : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                    <label className="flex min-h-11 items-center gap-3 self-end text-sm">
                        <input
                            type="checkbox"
                            name="controlled"
                            value="1"
                            defaultChecked={filters.controlled}
                            className="size-5"
                        />
                        Só controlados
                    </label>
                    <div className="self-end">
                        <Button type="submit" className="min-h-11">
                            Aplicar
                        </Button>
                    </div>
                </form>

                <section className="mb-8 grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Resumo por medicamento
                    </h2>
                    {summary.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-8 text-center text-muted-foreground">
                            Nenhum movimento no período.
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {summary.map((row) => (
                                <li
                                    key={row.key}
                                    className="rounded-xl border bg-card px-5 py-4"
                                >
                                    <h3 className="font-medium">
                                        {row.name}
                                        {row.controlled && <Controlled />}
                                    </h3>
                                    <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4 lg:grid-cols-7">
                                        {(
                                            [
                                                ['Entradas', row.entry],
                                                ['Dispensado', row.dispensing],
                                                ['Devolvido', row.return],
                                                ['Descartado', row.discard],
                                                ['Ajustes', row.adjustment],
                                                [
                                                    'Consumo em cirurgia',
                                                    row.surgery,
                                                ],
                                                [
                                                    'Estorno de cirurgia',
                                                    row.surgery_return,
                                                ],
                                            ] as const
                                        ).map(([label, value]) => (
                                            <div key={label}>
                                                <dt className="text-muted-foreground">
                                                    {label}
                                                </dt>
                                                <dd className="font-medium tabular-nums">
                                                    {signed(value)}{' '}
                                                    {row.stockUnit}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Movimentos ({pages.total})
                    </h2>
                    {movements.length > 0 && (
                        <ul className="divide-y rounded-xl border bg-card text-sm">
                            {movements.map((movement) => (
                                <li
                                    key={movement.id}
                                    className="flex flex-wrap items-start justify-between gap-2 px-5 py-3"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {movement.type}
                                        </span>{' '}
                                        · {movement.at} · {movement.medication}
                                        {movement.controlled && <Controlled />}
                                        <span className="block text-muted-foreground">
                                            Lote {movement.lot} · validade{' '}
                                            {movement.expiresOn} ·{' '}
                                            {movement.user}
                                            {movement.reason &&
                                                ` · ${movement.reason}`}
                                        </span>
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {movement.in !== ''
                                            ? `+${movement.in}`
                                            : `−${movement.out}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {(pages.newer || pages.older) && (
                        <nav
                            aria-label="Páginas de movimentos"
                            className="flex flex-wrap gap-2"
                        >
                            {pages.newer && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link href={pages.newer} preserveScroll>
                                        Mais recentes
                                    </Link>
                                </Button>
                            )}
                            {pages.older && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link href={pages.older} preserveScroll>
                                        Anteriores
                                    </Link>
                                </Button>
                            )}
                        </nav>
                    )}
                </section>
            </Page>
        </>
    );
}
