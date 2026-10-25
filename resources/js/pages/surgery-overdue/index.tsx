import { Head, router } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/surgery-overdue';

type Row = {
    id: number;
    place: string;
    author: string | null;
    urgency: string;
};

/**
 * Stalled surgeries (step 5b.3c, ADR-0009), for the unit manager: scheduled ones past their time and requests never
 * scheduled. When, where, room and who ordered; never the patient, the procedure or the record.
 */
export default function SurgeryOverdue({
    units,
    unitId,
    hours,
    days,
    urgencies,
    scheduled,
    requested,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    hours: number;
    days: number;
    urgencies: Record<string, string>;
    scheduled: (Row & { start: string; room: string | null })[];
    requested: (Row & { desiredDate: string })[];
}) {
    const unit = units.find((option) => option.id === unitId);
    const list = (
        rows: (Row & { when: string })[],
        empty: string,
        labelledBy: string,
    ) =>
        rows.length === 0 ? (
            <p className="rounded-xl border bg-card px-5 py-8 text-center text-muted-foreground">
                {empty}
            </p>
        ) : (
            <ul
                aria-labelledby={labelledBy}
                className="divide-y rounded-xl border bg-card"
            >
                {rows.map((row) => (
                    <li
                        key={row.id}
                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                    >
                        <span className="grid gap-0.5">
                            <span className="font-medium">{row.when}</span>
                            <span className="text-sm text-muted-foreground">
                                {row.place}
                                {row.author && ` · pedida por ${row.author}`}
                            </span>
                        </span>
                        <span
                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${row.urgency === 'urgent' ? 'bg-destructive text-white' : 'bg-muted'}`}
                        >
                            {urgencies[row.urgency]}
                        </span>
                    </li>
                ))}
            </ul>
        );

    return (
        <>
            <Head title="Cirurgias paradas" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Cirurgias paradas"
                    description="Enquanto a cirurgia está agendada, o centro cirúrgico e a equipe continuam lendo o prontuário: cobre o centro cirúrgico para concluir, reagendar ou cancelar, ou peça a quem pediu que cancele."
                />
                {units.length > 1 && (
                    <div className="mb-6 grid max-w-sm gap-2">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            value={unitId}
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
                <section className="mb-8 grid gap-3">
                    <h2 id="overdue-scheduled" className="font-semibold">
                        Agendadas com fim previsto há mais de {hours} horas
                    </h2>
                    {list(
                        scheduled.map((row) => ({
                            ...row,
                            when: `${row.room}, ${row.start}`,
                        })),
                        'Nenhuma cirurgia agendada vencida.',
                        'overdue-scheduled',
                    )}
                </section>
                <section className="grid gap-3">
                    <h2 id="overdue-requested" className="font-semibold">
                        Pedidos sem agendamento {days} dias depois da data
                        desejada
                    </h2>
                    {list(
                        requested.map((row) => ({
                            ...row,
                            when: `Desejada para ${row.desiredDate}`,
                        })),
                        'Nenhum pedido parado.',
                        'overdue-requested',
                    )}
                </section>
            </Page>
        </>
    );
}
