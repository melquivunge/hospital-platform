import { Head, Link, router } from '@inertiajs/react';
import SurgeryScheduleController from '@/actions/App/Http/Controllers/SurgeryScheduleController';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/surgery-schedule';

type Summary = {
    id: number;
    patient: string | null;
    place: string | null;
    procedure: string;
    laterality: string;
    urgency: string;
};

/**
 * The surgical agenda (step 5b.3a, ADR-0009): the surgeries requested in the unit, urgent first, and the day of
 * each room. Only identification, procedure, time and team; no read is logged here.
 */
export default function SurgerySchedule({
    units,
    unitId,
    date,
    urgencies,
    lateralities,
    requested,
    rooms,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    date: string;
    urgencies: Record<string, string>;
    lateralities: Record<string, string>;
    requested: (Summary & { desiredDate: string })[];
    rooms: {
        id: number;
        name: string;
        active: boolean;
        surgeries: (Summary & {
            start: string;
            end: string;
            surgeons: string[];
            status: string;
        })[];
    }[];
}) {
    const unit = units.find((option) => option.id === unitId);
    const go = (query: { unit?: number | string; date?: string }) =>
        router.get(index({ query: { unit: unitId, date, ...query } }).url);
    const title = (surgery: Summary) =>
        surgery.laterality === 'none'
            ? surgery.procedure
            : `${surgery.procedure} · ${lateralities[surgery.laterality]}`;

    return (
        <>
            <Head title="Agenda cirúrgica" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Agenda cirúrgica"
                    description="Pedidos de cirurgia da unidade e o dia de cada sala."
                />
                <div className="mb-6 grid gap-4 sm:grid-cols-2 md:max-w-2xl">
                    {units.length > 1 && (
                        <div className="grid gap-2">
                            <Label htmlFor="unit">Unidade</Label>
                            <select
                                id="unit"
                                value={unitId}
                                onChange={(event) =>
                                    go({ unit: event.target.value })
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
                        <Label htmlFor="date">Dia</Label>
                        <Input
                            id="date"
                            type="date"
                            value={date}
                            onChange={(event) =>
                                event.target.value &&
                                go({ date: event.target.value })
                            }
                            className="h-11"
                        />
                    </div>
                </div>

                <section className="mb-8 grid gap-3">
                    <h2 className="text-lg font-semibold">Salas</h2>
                    {rooms.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-8 text-center text-muted-foreground">
                            Nenhuma sala ativa nesta unidade. O administrador da
                            rede cadastra as salas em "Procedimentos e salas".
                        </p>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {rooms.map((room) => (
                                <section
                                    key={room.id}
                                    aria-labelledby={`room-${room.id}`}
                                    className="rounded-xl border bg-card"
                                >
                                    <h3
                                        id={`room-${room.id}`}
                                        className="border-b px-5 py-3 font-medium"
                                    >
                                        {room.name}
                                        {!room.active && ' (inativa)'}
                                    </h3>
                                    {room.surgeries.length === 0 ? (
                                        <p className="px-5 py-6 text-sm text-muted-foreground">
                                            Livre neste dia.
                                        </p>
                                    ) : (
                                        <ul className="divide-y">
                                            {room.surgeries.map((surgery) => (
                                                <li key={surgery.id}>
                                                    <Link
                                                        href={SurgeryScheduleController.show.url(
                                                            surgery.id,
                                                        )}
                                                        className="grid min-h-11 gap-0.5 px-5 py-3 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    >
                                                        <span className="text-sm font-medium">
                                                            {surgery.start} –{' '}
                                                            {surgery.end}
                                                            {surgery.urgency ===
                                                                'urgent' &&
                                                                ' · Urgência'}
                                                            {surgery.status ===
                                                                'in_progress' &&
                                                                ' · Em andamento'}
                                                            {surgery.status ===
                                                                'completed' &&
                                                                ' · Concluída'}
                                                        </span>
                                                        <span>
                                                            {surgery.patient}
                                                        </span>
                                                        <span className="text-sm text-muted-foreground">
                                                            {title(surgery)}
                                                            {surgery.surgeons
                                                                .length > 0 &&
                                                                ` · ${surgery.surgeons.join(', ')}`}
                                                        </span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>
                            ))}
                        </div>
                    )}
                </section>
                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Aguardando agendamento
                    </h2>
                    {requested.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-8 text-center text-muted-foreground">
                            Nenhum pedido aguardando.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border bg-card">
                            {requested.map((surgery) => (
                                <li key={surgery.id}>
                                    <Link
                                        href={SurgeryScheduleController.show.url(
                                            surgery.id,
                                        )}
                                        className="flex min-h-11 flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <span className="grid min-w-0 gap-0.5">
                                            <span className="font-medium">
                                                {surgery.patient}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {title(surgery)} ·{' '}
                                                {surgery.place} · desejada para{' '}
                                                {surgery.desiredDate}
                                            </span>
                                        </span>
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${surgery.urgency === 'urgent' ? 'bg-destructive text-white' : 'bg-muted'}`}
                                        >
                                            {urgencies[surgery.urgency]}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}
