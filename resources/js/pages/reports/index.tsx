import { Head, router } from '@inertiajs/react';
import { ChartColumn, Download } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { attendance, index, schedule } from '@/routes/reports';

type Counts = Record<string, number>;

type Props = {
    units: { id: number; name: string }[];
    unit: { id: number; name: string } | null;
    from: string | null;
    to: string | null;
    coverage: Counts | null;
    requests: Counts | null;
    attendance: Counts | null;
    hours: { id: number; name: string; shifts: number; hours: number }[];
};

const number = new Intl.NumberFormat('pt-BR');
const decimal = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">{title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
            {children}
        </section>
    );
}

/** One headline figure, then the parts that add up to it. */
function Figures({
    total,
    parts,
}: {
    total: [string, number];
    parts: [string, number][];
}) {
    return (
        <div className="mt-5 grid gap-5 sm:grid-cols-[minmax(9rem,auto)_1fr] sm:items-end">
            <div>
                <p className="text-sm text-muted-foreground">{total[0]}</p>
                <p className="text-4xl font-semibold tracking-tight tabular-nums">
                    {number.format(total[1])}
                </p>
            </div>
            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
                {parts.map(([label, value]) => (
                    <div key={label} className="border-l pl-3">
                        <dt className="text-xs text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="text-lg font-medium tabular-nums">
                            {number.format(value)}
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

/** Operational reports (step 9b): coverage, requests, presence and hours, with CSV exports. */
export default function Reports(props: Props) {
    const { units, unit, from, to, coverage, requests, hours } = props;

    if (unit === null || coverage === null || requests === null) {
        return (
            <>
                <Head title="Relatórios" />
                <Page>
                    <PageHeader title="Relatórios" />
                    <div className="rounded-lg border bg-card px-5 py-12 text-center">
                        <ChartColumn
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">
                            Nenhuma unidade disponível
                        </p>
                    </div>
                </Page>
            </>
        );
    }

    const presence = props.attendance ?? {};
    const query = { unit: unit.id, from: from ?? '', to: to ?? '' };
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
                    unit: field('unit'),
                    from: field('from'),
                    to: field('to'),
                },
            }).url,
            {},
            { preserveScroll: true },
        );
    };
    const requestTotal =
        requests.pending +
        requests.completed +
        requests.refused +
        requests.cancelled +
        requests.expired;

    return (
        <>
            <Head title="Relatórios" />
            <Page>
                <PageHeader
                    eyebrow={unit.name}
                    title="Relatórios"
                    description="Plantões que começam no período, no horário da unidade. Os arquivos trazem exatamente as linhas contadas aqui."
                    actions={
                        <>
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <a href={schedule({ query }).url} download>
                                    <Download aria-hidden="true" />
                                    Escala (CSV)
                                </a>
                            </Button>
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <a href={attendance({ query }).url} download>
                                    <Download aria-hidden="true" />
                                    Presença (CSV)
                                </a>
                            </Button>
                        </>
                    }
                />

                <form
                    key={`${unit.id}-${from}-${to}`}
                    onSubmit={apply}
                    className="mb-8 flex flex-wrap items-end gap-4"
                >
                    <div className="grid min-w-56 flex-1 gap-2 sm:flex-none">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            name="unit"
                            defaultValue={unit.id}
                            className={selectClassName}
                        >
                            {units.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="from">De</Label>
                        <Input
                            id="from"
                            name="from"
                            type="date"
                            className="min-h-11"
                            defaultValue={from ?? ''}
                            required
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">Até</Label>
                        <Input
                            id="to"
                            name="to"
                            type="date"
                            className="min-h-11"
                            defaultValue={to ?? ''}
                            required
                        />
                    </div>
                    <Button type="submit" className="min-h-11">
                        Aplicar
                    </Button>
                    <p className="w-full text-xs text-muted-foreground">
                        Período de até 92 dias.
                    </p>
                </form>

                <div className="grid gap-6">
                    <Section
                        title="Cobertura da escala"
                        description="Plantões publicados divididos entre preenchidos, anunciados para substituição e vagas abertas."
                    >
                        <Figures
                            total={['Publicados', coverage.published]}
                            parts={[
                                ['Preenchidos', coverage.filled],
                                ['Anunciados', coverage.announced],
                                ['Vagas abertas', coverage.open],
                                ['Rascunhos', coverage.drafts],
                                ['Cancelados', coverage.cancelled],
                            ]}
                        />
                    </Section>

                    <Section
                        title="Solicitações"
                        description="Transferências e trocas dos plantões do período, pela situação atual."
                    >
                        <Figures
                            total={['Total', requestTotal]}
                            parts={[
                                ['Pendentes', requests.pending],
                                ['Concluídas', requests.completed],
                                ['Recusadas', requests.refused],
                                ['Canceladas', requests.cancelled],
                                ['Expiradas', requests.expired],
                            ]}
                        />
                    </Section>

                    <Section
                        title="Presença"
                        description="Plantões publicados já iniciados, com responsável. Sem dados de localização."
                    >
                        <Figures
                            total={['Iniciados', presence.started ?? 0]}
                            parts={[
                                ['Com entrada', presence.checkedIn ?? 0],
                                ['Sem entrada', presence.noEntry ?? 0],
                                ['Saída registrada', presence.checkedOut ?? 0],
                                ['Saída ajustada', presence.adjustedExit ?? 0],
                                [
                                    'Ocorrências abertas',
                                    presence.openOccurrences ?? 0,
                                ],
                            ]}
                        />
                    </Section>

                    <Section
                        title="Horas por profissional"
                        description="Plantões publicados com responsável, somando a duração de cada um."
                    >
                        {hours.length === 0 ? (
                            <p className="mt-5 text-sm text-muted-foreground">
                                Nenhum plantão publicado com responsável no
                                período.
                            </p>
                        ) : (
                            <div className="mt-5 overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="py-2 pr-4 font-medium">
                                                Profissional
                                            </th>
                                            <th className="py-2 pr-4 text-right font-medium">
                                                Plantões
                                            </th>
                                            <th className="py-2 text-right font-medium">
                                                Horas
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {hours.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-2.5 pr-4">
                                                    {row.name}
                                                </td>
                                                <td className="py-2.5 pr-4 text-right tabular-nums">
                                                    {number.format(row.shifts)}
                                                </td>
                                                <td className="py-2.5 text-right tabular-nums">
                                                    {decimal.format(row.hours)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Section>
                </div>
            </Page>
        </>
    );
}

Reports.layout = {
    breadcrumbs: [{ title: 'Relatórios', href: index() }],
};
