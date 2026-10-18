import { Head } from '@inertiajs/react';
import { CalendarCheck } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import type { ShiftSummary } from '@/pages/shifts/index';
import { formatDay, PeriodLabel, timeRange } from '@/pages/shifts/index';
import { mine } from '@/routes/shifts';

type MyShift = ShiftSummary & { unit: string };

/** Professional's own published shifts, grouped by local start day (reference IMG_0911). */
export default function MyShifts({
    professional,
    shifts,
}: {
    professional: string;
    shifts: MyShift[];
}) {
    const days = [...new Set(shifts.map((shift) => shift.date))];

    return (
        <>
            <Head title="Meus plantões" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={professional}
                    title="Meus plantões"
                    description="Plantões publicados para você, no horário de cada unidade. Rascunhos não aparecem aqui."
                />

                {shifts.length === 0 ? (
                    <div className="rounded-lg border bg-card px-5 py-12 text-center">
                        <CalendarCheck
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">
                            Nenhum plantão publicado para você
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-6">
                        {days.map((day) => (
                            <section key={day} aria-labelledby={`my-${day}`}>
                                <h2
                                    id={`my-${day}`}
                                    className="mb-2 text-sm font-semibold first-letter:uppercase"
                                >
                                    {formatDay(day)}
                                </h2>
                                <ul className="grid gap-3">
                                    {shifts
                                        .filter((shift) => shift.date === day)
                                        .map((shift) => (
                                            <li
                                                key={shift.id}
                                                className="grid gap-1 rounded-lg border bg-card p-4 text-sm"
                                            >
                                                <p className="font-medium">
                                                    {shift.unit} ·{' '}
                                                    {shift.sector}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {shift.function}
                                                </p>
                                                <p className="mt-1 flex flex-wrap items-center justify-between gap-2 border-t pt-2">
                                                    <PeriodLabel
                                                        period={shift.period}
                                                    />
                                                    <span className="tabular-nums">
                                                        {timeRange(shift)}
                                                    </span>
                                                </p>
                                            </li>
                                        ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
            </Page>
        </>
    );
}

MyShifts.layout = {
    breadcrumbs: [{ title: 'Meus plantões', href: mine() }],
};
