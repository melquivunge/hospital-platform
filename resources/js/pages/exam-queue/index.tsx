import { Head, Link, router } from '@inertiajs/react';
import ExamQueueController from '@/actions/App/Http/Controllers/ExamQueueController';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/exam-queue';

type QueueOrder = {
    id: number;
    patient: string | null;
    place: string;
    priority: string;
    waitingMinutes: number;
    late: boolean;
    items: {
        id: number;
        name: string;
        type: string;
        collected: boolean;
        released: boolean;
    }[];
};

function waiting(minutes: number): string {
    if (minutes < 60) {
        return `${minutes} min`;
    }
    if (minutes < 48 * 60) {
        return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
    }

    return `${Math.floor(minutes / (24 * 60))} dias`;
}

/**
 * The exam queue (step 5.3, ADR-0008): the open orders of the user's types in the unit, urgent first and then
 * by waiting time, until every item has its result (step 5.4); below, the recently released ones, to rectify.
 * Only identification; opening an order is logged.
 */
export default function ExamQueue({
    units,
    unitId,
    types,
    priorities,
    orders,
    released,
    releasedDays,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    types: Record<string, string>;
    priorities: Record<string, string>;
    orders: QueueOrder[];
    released: {
        id: number;
        patient: string | null;
        place: string;
        items: string[];
    }[];
    releasedDays: number;
}) {
    const unit = units.find((option) => option.id === unitId);

    return (
        <>
            <Head title="Fila de exames" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Fila de exames"
                    description={`${Object.values(types).join(' e ')}: pedidos em aberto da unidade, urgentes primeiro. Abrir um pedido abre o prontuário do paciente e fica registrado na trilha de acesso.`}
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
                {orders.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Nenhum pedido em aberto nesta unidade.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {orders.map((order) => {
                            const pending = order.items.filter(
                                (item) => !item.collected,
                            ).length;

                            return (
                                <li key={order.id}>
                                    <Link
                                        href={ExamQueueController.show.url(
                                            order.id,
                                        )}
                                        className="flex min-h-11 flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <span className="grid min-w-0 gap-0.5">
                                            <span className="font-medium">
                                                {order.patient}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {order.place} ·{' '}
                                                {order.items
                                                    .map((item) => item.name)
                                                    .join(', ')}
                                            </span>
                                        </span>
                                        <span className="flex flex-wrap items-center gap-2 text-sm">
                                            {order.priority === 'urgent' && (
                                                <span className="rounded-full bg-destructive px-2 py-0.5 text-xs font-medium text-white">
                                                    {priorities[order.priority]}
                                                </span>
                                            )}
                                            <span
                                                className={
                                                    order.late
                                                        ? 'font-semibold text-destructive'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {order.late
                                                    ? 'Fora do prazo: '
                                                    : 'Espera: '}
                                                {waiting(order.waitingMinutes)}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {pending > 0
                                                    ? `${pending} a executar`
                                                    : `${order.items.filter((item) => !item.released).length} aguardando resultado`}
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {released.length > 0 && (
                    <section className="mt-8 grid gap-3">
                        <h2 className="font-semibold">
                            Liberados nos últimos {releasedDays} dias
                        </h2>
                        <ul className="divide-y rounded-xl border bg-card">
                            {released.map((order) => (
                                <li key={order.id}>
                                    <Link
                                        href={ExamQueueController.show.url(
                                            order.id,
                                        )}
                                        className="grid min-h-11 gap-0.5 px-5 py-3 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <span className="font-medium">
                                            {order.patient}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {order.place} ·{' '}
                                            {order.items.join(', ')}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </Page>
        </>
    );
}
