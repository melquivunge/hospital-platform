import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { Page, PageHeader, WarningBadge } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { index, show } from '@/routes/shifts';
import { create } from '@/routes/units/shifts';

export type ShiftSummary = {
    id: number;
    date: string;
    start: string;
    end: string;
    endDate: string;
    sector: string | null;
    function: string | null;
    professional: string | null;
    status: 'draft' | 'published' | 'cancelled';
};

type Unit = {
    id: number;
    name: string;
    timezone: string | null;
    complete: boolean;
};

const selectClassName =
    'h-11 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export const statusLabels: Record<ShiftSummary['status'], string> = {
    draft: 'Rascunho',
    published: 'Publicado',
    cancelled: 'Cancelado',
};

/** Local calendar dates (YYYY-MM-DD) handled as UTC noon to avoid any timezone shift. */
function addDays(date: string, days: number) {
    const value = new Date(`${date}T12:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

export function formatDay(date: string) {
    return new Date(`${date}T12:00:00Z`).toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: '2-digit',
        month: '2-digit',
        timeZone: 'UTC',
    });
}

export function timeRange(shift: ShiftSummary) {
    const nextDay = shift.endDate !== shift.date;

    return `${shift.start} – ${shift.end}${nextDay ? ` (${formatDay(shift.endDate)})` : ''}`;
}

export function StatusBadge({ status }: { status: ShiftSummary['status'] }) {
    return status === 'published' ? (
        <span className="text-sm font-medium">{statusLabels[status]}</span>
    ) : (
        <WarningBadge>{statusLabels[status]}</WarningBadge>
    );
}

export default function ShiftsIndex({
    units,
    unit,
    week,
    shifts,
    canManage,
}: {
    units: { id: number; name: string }[];
    unit: Unit | null;
    week: string | null;
    shifts: ShiftSummary[];
    canManage: boolean;
}) {
    if (unit === null || week === null) {
        return (
            <>
                <Head title="Escala" />
                <Page>
                    <PageHeader eyebrow="Escala" title="Plantões" />
                    <p className="text-sm text-muted-foreground">
                        Nenhuma unidade disponível para consulta.
                    </p>
                </Page>
            </>
        );
    }

    const days = Array.from({ length: 7 }, (_, offset) =>
        addDays(week, offset),
    );
    const go = (query: { unit?: number; week?: string }) =>
        router.get(
            index({ query: { unit: unit.id, week, ...query } }).url,
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Escala" />
            <Page>
                <PageHeader
                    eyebrow="Escala"
                    title="Plantões"
                    description={`Horários no fuso da unidade (${unit.timezone?.replaceAll('_', ' ') ?? 'não informado'}). Rascunhos não são escala confirmada.`}
                    actions={
                        canManage &&
                        unit.complete && (
                            <Button asChild className="min-h-11">
                                <Link href={create(unit.id)}>
                                    <Plus aria-hidden="true" />
                                    Novo plantão
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="mb-6 flex flex-wrap items-end gap-4">
                    <div className="grid min-w-56 flex-1 gap-2 sm:flex-none">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            value={unit.id}
                            onChange={(event) =>
                                go({ unit: Number(event.target.value) })
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
                    <nav
                        aria-label="Semana"
                        className="flex flex-wrap items-center gap-2"
                    >
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() => go({ week: addDays(week, -7) })}
                        >
                            <ChevronLeft aria-hidden="true" />
                            <span className="sr-only sm:not-sr-only">
                                Semana anterior
                            </span>
                        </Button>
                        <p className="px-2 text-sm font-medium tabular-nums">
                            {formatDay(days[0])} a {formatDay(days[6])}
                        </p>
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() => go({ week: addDays(week, 7) })}
                        >
                            <span className="sr-only sm:not-sr-only">
                                Próxima semana
                            </span>
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    </nav>
                </div>

                {!unit.complete && (
                    <p className="mb-6">
                        <WarningBadge>
                            Unidade sem UF ou fuso horário: não é possível
                            montar escala.
                        </WarningBadge>
                    </p>
                )}

                <div className="grid gap-4">
                    {days.map((day) => {
                        const dayShifts = shifts.filter(
                            (shift) => shift.date === day,
                        );

                        return (
                            <section
                                key={day}
                                aria-labelledby={`day-${day}`}
                                className="overflow-hidden rounded-lg border bg-card"
                            >
                                <h2
                                    id={`day-${day}`}
                                    className="flex items-center gap-2 border-b bg-muted/40 px-5 py-3 text-sm font-semibold"
                                >
                                    <CalendarDays
                                        className="size-4 text-primary"
                                        aria-hidden="true"
                                    />
                                    <span className="inline-block first-letter:uppercase">
                                        {formatDay(day)}
                                    </span>
                                </h2>
                                {dayShifts.length === 0 ? (
                                    <p className="px-5 py-4 text-sm text-muted-foreground">
                                        Nenhum plantão.
                                    </p>
                                ) : (
                                    <ul className="divide-y">
                                        {dayShifts.map((shift) => (
                                            <li key={shift.id}>
                                                <Link
                                                    href={show(shift.id)}
                                                    className="grid gap-1 px-5 py-3 text-sm hover:bg-muted/40 sm:grid-cols-[12rem_1fr_auto] sm:items-center sm:gap-4"
                                                >
                                                    <span className="font-medium tabular-nums">
                                                        {timeRange(shift)}
                                                    </span>
                                                    <span>
                                                        {shift.sector} ·{' '}
                                                        {shift.function} ·{' '}
                                                        {shift.professional ?? (
                                                            <strong className="font-semibold">
                                                                Vaga aberta
                                                            </strong>
                                                        )}
                                                    </span>
                                                    <StatusBadge
                                                        status={shift.status}
                                                    />
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        );
                    })}
                </div>
            </Page>
        </>
    );
}

ShiftsIndex.layout = {
    breadcrumbs: [{ title: 'Escala', href: index() }],
};
