import { Head, router } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/census';

type Row = {
    beds: number;
    free: number;
    reserved: number;
    occupied: number;
    cleaning: number;
    blocked: number;
    occupancy: number | null;
    discharges: number;
    meanStayDays: number | null;
};

const columns: { key: keyof Row; label: string }[] = [
    { key: 'beds', label: 'Leitos' },
    { key: 'occupied', label: 'Ocupados' },
    { key: 'free', label: 'Livres' },
    { key: 'reserved', label: 'Reservados' },
    { key: 'cleaning', label: 'Em higienização' },
    { key: 'blocked', label: 'Bloqueados' },
    { key: 'occupancy', label: 'Ocupação' },
    { key: 'discharges', label: 'Altas' },
    { key: 'meanStayDays', label: 'Tempo médio' },
];

const number = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });

function cell(row: Row, key: keyof Row): string {
    const value = row[key];
    if (value === null) {
        return '—';
    }
    if (key === 'occupancy') {
        return `${number.format(value)}%`;
    }
    if (key === 'meanStayDays') {
        return `${number.format(value)} d`;
    }

    return String(value);
}

/** Daily bed census (step 4.7): numbers by sector, no patient data. */
export default function Census({
    units,
    unit,
    at,
    windowDays,
    sectors,
    total,
}: {
    units: { id: number; name: string }[];
    unit: { id: number; name: string };
    at: string;
    windowDays: number;
    sectors: (Row & { name: string })[];
    total: Row;
}) {
    return (
        <>
            <Head title="Censo" />
            <Page>
                <PageHeader
                    eyebrow={unit.name}
                    title="Censo de leitos"
                    description={`Situação às ${at}. Ocupação: ocupados sobre leitos ativos não bloqueados. Altas e tempo médio de internação: últimos ${windowDays} dias, pelo setor do último leito.`}
                />
                {units.length > 1 && (
                    <div className="mb-6 grid max-w-sm gap-2">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            value={unit.id}
                            onChange={(event) =>
                                router.get(
                                    index({
                                        query: { unit: event.target.value },
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
                {sectors.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Nenhum leito cadastrado nesta unidade.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border bg-card">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Censo de leitos por setor
                            </caption>
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th
                                        scope="col"
                                        className="px-4 py-3 font-medium"
                                    >
                                        Setor
                                    </th>
                                    {columns.map((column) => (
                                        <th
                                            key={column.key}
                                            scope="col"
                                            className="px-4 py-3 text-right font-medium whitespace-nowrap"
                                        >
                                            {column.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {sectors.map((sector) => (
                                    <tr key={sector.name} className="border-b">
                                        <th
                                            scope="row"
                                            className="px-4 py-3 text-left font-medium"
                                        >
                                            {sector.name}
                                        </th>
                                        {columns.map((column) => (
                                            <td
                                                key={column.key}
                                                className="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                                            >
                                                {cell(sector, column.key)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="font-semibold">
                                    <th
                                        scope="row"
                                        className="px-4 py-3 text-left"
                                    >
                                        Unidade
                                    </th>
                                    {columns.map((column) => (
                                        <td
                                            key={column.key}
                                            className="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                                        >
                                            {cell(total, column.key)}
                                        </td>
                                    ))}
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </Page>
        </>
    );
}

Census.layout = {
    breadcrumbs: [{ title: 'Censo', href: index() }],
};
