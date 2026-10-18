import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Megaphone } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import type { ShiftSummary } from '@/components/shift-display';
import { formatDay, PeriodLabel, timeRange } from '@/components/shift-display';
import { index, show } from '@/routes/vacancies';

export type Vacancy = Pick<
    ShiftSummary,
    | 'id'
    | 'date'
    | 'start'
    | 'end'
    | 'endDate'
    | 'sector'
    | 'function'
    | 'period'
> & {
    unit: string;
    application: { status: string; label: string } | null;
    replacement: boolean;
};

/** Open vacancies the professional may apply to (references IMG_0928 to 0932). */
export default function Vacancies({
    professional,
    vacancies,
}: {
    professional: string;
    vacancies: Vacancy[];
}) {
    return (
        <>
            <Head title="Vagas" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={professional}
                    title="Vagas"
                    description="Plantões em aberto nas unidades e funções em que você tem habilitação. Candidatar-se não garante o plantão: o coordenador escolhe."
                />

                {vacancies.length === 0 ? (
                    <div className="rounded-lg border bg-card px-5 py-12 text-center">
                        <Megaphone
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Nenhuma vaga aberta agora</p>
                    </div>
                ) : (
                    <ul className="grid gap-3">
                        {vacancies.map((vacancy) => (
                            <li key={vacancy.id}>
                                <Link
                                    href={show(vacancy.id)}
                                    className="grid gap-1 rounded-lg border bg-card p-4 text-sm transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="flex items-center justify-between gap-2">
                                        <span className="font-medium first-letter:uppercase">
                                            {formatDay(vacancy.date)}
                                        </span>
                                        <ChevronRight
                                            className="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span>
                                        {vacancy.unit} · {vacancy.sector}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {vacancy.function}
                                        {vacancy.replacement &&
                                            ' · Substituição'}
                                    </span>
                                    <span className="mt-1 flex flex-wrap items-center justify-between gap-2 border-t pt-2">
                                        <PeriodLabel period={vacancy.period} />
                                        <span className="tabular-nums">
                                            {timeRange(vacancy)}
                                        </span>
                                    </span>
                                    {vacancy.application && (
                                        <span className="font-medium text-primary">
                                            {vacancy.application.label}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

Vacancies.layout = {
    breadcrumbs: [{ title: 'Vagas', href: index() }],
};
