import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Moon,
    Plus,
    Sun,
} from 'lucide-react';
import { useState } from 'react';
import { Page, PageHeader, WarningBadge } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
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
    period: 'day' | 'night';
    valueCents: number | null;
};

type View = 'day' | 'week' | 'month';

type Unit = {
    id: number;
    name: string;
    timezone: string | null;
    complete: boolean;
};

const selectClassName =
    'h-11 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

const stepLabels: Record<View, [string, string]> = {
    day: ['Dia anterior', 'Próximo dia'],
    week: ['Semana anterior', 'Próxima semana'],
    month: ['Mês anterior', 'Próximo mês'],
};

/**
 * A shift is listed on every local day it covers, so one crossing midnight (or a week/month
 * boundary) stays visible on the day it ends. Ending exactly at 00:00 does not cover that day.
 */
function coversDay(shift: ShiftSummary, day: string) {
    return (
        shift.date <= day &&
        (day < shift.endDate ||
            (day === shift.endDate &&
                (day === shift.date || shift.end !== '00:00')))
    );
}

const viewLabels: Record<View, string> = {
    day: 'Dia',
    week: 'Semana',
    month: 'Mês',
};

export const statusLabels: Record<ShiftSummary['status'], string> = {
    draft: 'Rascunho',
    published: 'Publicado',
    cancelled: 'Cancelado',
};

/** Local calendar dates (YYYY-MM-DD) handled as UTC noon to avoid any timezone shift. */
function toDate(date: string) {
    return new Date(`${date}T12:00:00Z`);
}

function addDays(date: string, days: number) {
    const value = toDate(date);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

function addMonths(date: string, months: number) {
    const value = toDate(date.slice(0, 8) + '01');
    value.setUTCMonth(value.getUTCMonth() + months);

    return value.toISOString().slice(0, 10);
}

function daysBetween(from: string, to: string) {
    const days: string[] = [];

    for (let day = from; day <= to; day = addDays(day, 1)) {
        days.push(day);
    }

    return days;
}

export function formatDay(date: string) {
    return toDate(date).toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: '2-digit',
        month: '2-digit',
        timeZone: 'UTC',
    });
}

function formatMonth(date: string) {
    return toDate(date).toLocaleDateString('pt-BR', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
}

export function timeRange(shift: ShiftSummary) {
    const nextDay = shift.endDate !== shift.date;

    return `${shift.start} – ${shift.end}${nextDay ? ` (${formatDay(shift.endDate)})` : ''}`;
}

export function formatMoney(cents: number) {
    return (cents / 100).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    });
}

/** Shift period with icon and text (never the icon alone). */
export function PeriodLabel({ period }: { period: ShiftSummary['period'] }) {
    const Icon = period === 'day' ? Sun : Moon;

    return (
        <span className="inline-flex items-center gap-1.5 text-muted-foreground">
            <Icon className="size-4 text-primary" aria-hidden="true" />
            {period === 'day' ? 'Diurno' : 'Noturno'}
        </span>
    );
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
    view,
    from,
    to,
    weekFrom,
    markers,
    shifts: allShifts,
    canManage,
}: {
    units: { id: number; name: string }[];
    unit: Unit | null;
    view: View;
    from: string | null;
    to: string | null;
    weekFrom: string | null;
    markers: string[];
    shifts: ShiftSummary[];
    canManage: boolean;
}) {
    const [showVacancies, setShowVacancies] = useState(true);
    const shifts = showVacancies
        ? allShifts
        : allShifts.filter((shift) => shift.professional !== null);

    if (unit === null || from === null || to === null) {
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

    // Mid-week anchor: switching a week that straddles two months opens the month of its Thursday.
    const anchor = view === 'week' ? addDays(from, 3) : from;
    const go = (query: { unit?: number; view?: View; date?: string }) =>
        router.get(
            index({ query: { unit: unit.id, view, date: anchor, ...query } })
                .url,
            {},
            { preserveScroll: true },
        );
    const step = (direction: 1 | -1) =>
        view === 'month'
            ? addMonths(from, direction)
            : addDays(from, direction * (view === 'day' ? 1 : 7));
    const period =
        view === 'day'
            ? formatDay(from)
            : view === 'month'
              ? formatMonth(from)
              : `${formatDay(from)} a ${formatDay(to)}`;

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

                    <div
                        role="group"
                        aria-label="Visualização"
                        className="flex rounded-md border bg-card p-1"
                    >
                        {(Object.keys(viewLabels) as View[]).map((option) => (
                            <Button
                                key={option}
                                variant={option === view ? 'default' : 'ghost'}
                                aria-pressed={option === view}
                                className="min-h-11"
                                onClick={() => go({ view: option })}
                            >
                                {viewLabels[option]}
                            </Button>
                        ))}
                    </div>

                    <nav
                        aria-label="Período"
                        className="flex flex-wrap items-center gap-2"
                    >
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() => go({ date: step(-1) })}
                        >
                            <ChevronLeft aria-hidden="true" />
                            <span className="sr-only">
                                {stepLabels[view][0]}
                            </span>
                        </Button>
                        <p
                            className="px-2 text-sm font-medium tabular-nums first-letter:uppercase"
                            aria-live="polite"
                            aria-atomic="true"
                        >
                            {period}
                        </p>
                        <Button
                            variant="outline"
                            className="min-h-11"
                            onClick={() => go({ date: step(1) })}
                        >
                            <span className="sr-only">
                                {stepLabels[view][1]}
                            </span>
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    </nav>
                </div>

                <label className="mb-6 flex min-h-11 w-fit items-center gap-3 text-sm">
                    <input
                        type="checkbox"
                        checked={showVacancies}
                        onChange={(event) =>
                            setShowVacancies(event.target.checked)
                        }
                        className="size-4 accent-primary"
                    />
                    Mostrar também vagas abertas (sem profissional)
                </label>

                {view !== 'month' && weekFrom !== null && (
                    <DayStrip
                        days={daysBetween(weekFrom, addDays(weekFrom, 6))}
                        markers={markers}
                        current={view === 'day' ? from : null}
                        onOpenDay={(date) => go({ view: 'day', date })}
                    />
                )}

                {!unit.complete && (
                    <p className="mb-6">
                        <WarningBadge>
                            Unidade sem UF ou fuso horário: não é possível
                            montar escala.
                        </WarningBadge>
                    </p>
                )}

                {view === 'day' && (
                    <DaySection day={from} shifts={shifts} showAll />
                )}
                {view === 'week' && (
                    <div className="grid gap-4">
                        {daysBetween(from, to).map((day) => (
                            <DaySection
                                key={day}
                                day={day}
                                shifts={shifts.filter((shift) =>
                                    coversDay(shift, day),
                                )}
                                showAll
                            />
                        ))}
                    </div>
                )}
                {view === 'month' && (
                    <MonthView
                        from={from}
                        to={to}
                        shifts={shifts}
                        onOpenDay={(date) => go({ view: 'day', date })}
                    />
                )}
            </Page>
        </>
    );
}

function DaySection({
    day,
    shifts,
    showAll = false,
}: {
    day: string;
    shifts: ShiftSummary[];
    /** Label shifts that started on an earlier day ("Desde …"). */
    showAll?: boolean;
}) {
    return (
        <section
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
            {shifts.length === 0 ? (
                <p className="px-5 py-4 text-sm text-muted-foreground">
                    Nenhum plantão.
                </p>
            ) : (
                <ul className="divide-y">
                    {shifts.map((shift) => (
                        <li key={shift.id}>
                            <Link
                                href={show(shift.id)}
                                className="grid gap-1 px-5 py-3 text-sm hover:bg-muted/40 sm:grid-cols-[12rem_1fr_auto] sm:items-center sm:gap-4"
                            >
                                <span className="font-medium tabular-nums">
                                    {showAll && shift.date !== day
                                        ? `Desde ${formatDay(shift.date)}, ${shift.start} – ${shift.end}`
                                        : timeRange(shift)}
                                </span>
                                <span className="grid gap-0.5">
                                    <span>
                                        {shift.sector} · {shift.function} ·{' '}
                                        {shift.professional ?? (
                                            <strong className="font-semibold">
                                                Vaga aberta
                                            </strong>
                                        )}
                                    </span>
                                    <span className="flex flex-wrap gap-x-4">
                                        <PeriodLabel period={shift.period} />
                                        {shift.valueCents !== null && (
                                            <span className="text-muted-foreground tabular-nums">
                                                {formatMoney(shift.valueCents)}
                                            </span>
                                        )}
                                    </span>
                                </span>
                                <StatusBadge status={shift.status} />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

const weekdays = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

/** Phone-only strip of the week's days; a dot marks days with shifts. */
function DayStrip({
    days,
    markers,
    current,
    onOpenDay,
}: {
    days: string[];
    markers: string[];
    current: string | null;
    onOpenDay: (date: string) => void;
}) {
    return (
        <ol
            aria-label="Dias da semana"
            className="mb-6 grid grid-cols-7 gap-1 md:hidden"
        >
            {days.map((day, position) => {
                const busy = markers.includes(day);
                const selected = day === current;

                return (
                    <li key={day}>
                        <button
                            type="button"
                            onClick={() => onOpenDay(day)}
                            aria-current={selected ? 'date' : undefined}
                            aria-label={`${formatDay(day)}${busy ? ', com plantão' : ''}`}
                            className={cn(
                                'flex min-h-14 w-full flex-col items-center justify-center gap-0.5 rounded-md text-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                selected
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-muted',
                            )}
                        >
                            <span aria-hidden="true">
                                {weekdays[position].charAt(0)}
                            </span>
                            <span
                                aria-hidden="true"
                                className="text-sm font-semibold tabular-nums"
                            >
                                {Number(day.slice(8))}
                            </span>
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-1.5 rounded-full',
                                    busy
                                        ? selected
                                            ? 'bg-primary-foreground'
                                            : 'bg-primary'
                                        : 'bg-transparent',
                                )}
                            />
                        </button>
                    </li>
                );
            })}
        </ol>
    );
}

function MonthView({
    from,
    to,
    shifts,
    onOpenDay,
}: {
    from: string;
    to: string;
    shifts: ShiftSummary[];
    onOpenDay: (date: string) => void;
}) {
    const days = daysBetween(from, to);
    // Monday-first grid: blank cells before the 1st.
    const leading = (toDate(from).getUTCDay() + 6) % 7;
    const byDay = (day: string) =>
        shifts.filter((shift) => coversDay(shift, day));
    const busyDays = days.filter((day) => byDay(day).length > 0);

    return (
        <>
            {/* Phones: list of the days that have shifts (equivalent to the grid). */}
            <div className="grid gap-4 md:hidden">
                {busyDays.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nenhum plantão neste mês.
                    </p>
                ) : (
                    busyDays.map((day) => (
                        <DaySection
                            key={day}
                            day={day}
                            shifts={byDay(day)}
                            showAll
                        />
                    ))
                )}
            </div>

            <table className="hidden w-full table-fixed border-collapse overflow-hidden rounded-lg border bg-card text-sm md:table">
                <caption className="sr-only">
                    Plantões do mês por dia. Abra um dia para ver os detalhes.
                </caption>
                <thead className="bg-muted/60 text-xs text-muted-foreground">
                    <tr>
                        {weekdays.map((weekday) => (
                            <th key={weekday} scope="col" className="py-2">
                                {weekday}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {Array.from(
                        { length: Math.ceil((leading + days.length) / 7) },
                        (_, week) => (
                            <tr key={week}>
                                {Array.from({ length: 7 }, (_, column) => {
                                    const day =
                                        days[week * 7 + column - leading];

                                    if (day === undefined) {
                                        return (
                                            <td
                                                key={column}
                                                className="border bg-muted/30"
                                            />
                                        );
                                    }

                                    const dayShifts = byDay(day);

                                    return (
                                        <td
                                            key={column}
                                            className="h-28 border p-1 align-top"
                                        >
                                            <button
                                                type="button"
                                                onClick={() => onOpenDay(day)}
                                                aria-label={`${formatDay(day)}: ${dayShifts.length} ${dayShifts.length === 1 ? 'plantão' : 'plantões'}. Abrir dia`}
                                                className="flex h-full w-full flex-col items-stretch gap-1 rounded p-1 text-left hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                            >
                                                <span className="text-xs font-semibold tabular-nums">
                                                    {Number(day.slice(8))}
                                                </span>
                                                {dayShifts
                                                    .slice(0, 3)
                                                    .map((shift) => (
                                                        <span
                                                            key={shift.id}
                                                            className={cn(
                                                                'truncate rounded px-1 text-xs',
                                                                shift.status ===
                                                                    'published'
                                                                    ? 'bg-primary/10'
                                                                    : 'bg-muted',
                                                            )}
                                                        >
                                                            {shift.date === day
                                                                ? shift.start
                                                                : `até ${shift.end}`}{' '}
                                                            {shift.professional ??
                                                                'Vaga'}
                                                            {shift.status !==
                                                                'published' &&
                                                                ` (${statusLabels[shift.status]})`}
                                                        </span>
                                                    ))}
                                                {dayShifts.length > 3 && (
                                                    <span className="text-xs text-muted-foreground">
                                                        +{dayShifts.length - 3}
                                                    </span>
                                                )}
                                            </button>
                                        </td>
                                    );
                                })}
                            </tr>
                        ),
                    )}
                </tbody>
            </table>
        </>
    );
}

ShiftsIndex.layout = {
    breadcrumbs: [{ title: 'Escala', href: index() }],
};
