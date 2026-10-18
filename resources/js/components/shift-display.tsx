import { Moon, Sun } from 'lucide-react';
import { WarningBadge } from '@/components/page';

/**
 * Shift display helpers shared by several pages. They live outside pages/ because every page file is
 * also a bundle entry: importing one page from another can fold it into a shared chunk and drop it
 * from the Vite manifest that @vite looks up.
 */
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
    announced: boolean;
};

/** A shift as shown to professionals (vacancies, requests): no value, no other people. */
export type ShiftBrief = Pick<
    ShiftSummary,
    | 'id'
    | 'date'
    | 'start'
    | 'end'
    | 'endDate'
    | 'sector'
    | 'function'
    | 'period'
> & { unit: string };

export const statusLabels: Record<ShiftSummary['status'], string> = {
    draft: 'Rascunho',
    published: 'Publicado',
    cancelled: 'Cancelado',
};

/** Local calendar dates (YYYY-MM-DD) handled as UTC noon to avoid any timezone shift. */
export function toDate(date: string) {
    return new Date(`${date}T12:00:00Z`);
}

export function formatDay(date: string) {
    return toDate(date).toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: '2-digit',
        month: '2-digit',
        timeZone: 'UTC',
    });
}

export function timeRange(
    shift: Pick<ShiftSummary, 'date' | 'start' | 'end' | 'endDate'>,
) {
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

export function ShiftCard({ shift }: { shift: ShiftBrief }) {
    return (
        <div className="grid gap-1 rounded-md border bg-background p-3">
            <p className="font-medium first-letter:uppercase">
                {formatDay(shift.date)}
            </p>
            <p>
                {shift.unit} · {shift.sector}
            </p>
            <p className="text-muted-foreground">{shift.function}</p>
            <p className="mt-1 flex flex-wrap items-center justify-between gap-2 border-t pt-2">
                <PeriodLabel period={shift.period} />
                <span className="tabular-nums">{timeRange(shift)}</span>
            </p>
        </div>
    );
}
