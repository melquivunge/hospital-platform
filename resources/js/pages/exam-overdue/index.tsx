import { Head, router } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/exam-overdue';

/**
 * Orders without collection for 7 days (step 5.3b, ADR-0008), for the unit manager: when, priority, where, who
 * ordered and how many items are missing; and, first, the critical results nobody acknowledged (step 5.5). Never
 * the exams, the values or the record.
 */
export default function ExamOverdue({
    units,
    unitId,
    days,
    priorities,
    orders,
    critical,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    days: number;
    priorities: Record<string, string>;
    orders: {
        id: number;
        createdAt: string;
        days: number;
        priority: string;
        place: string;
        author: string | null;
        pending: number;
    }[];
    critical: {
        id: number;
        releasedAt: string;
        minutes: number;
        escalated: boolean;
        place: string;
        author: string | null;
    }[];
}) {
    const unit = units.find((option) => option.id === unitId);

    return (
        <>
            <Head title="Exames atrasados" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Exames atrasados"
                    description={`Pedidos em aberto com exame sem coleta há mais de ${days} dias. Enquanto o pedido está aberto, a equipe de Laboratório ou de Imagem continua lendo o prontuário: cobre a equipe ou peça a quem pediu que cancele.`}
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
                {critical.length > 0 && (
                    <section className="mb-8 grid gap-3">
                        <h2 className="font-semibold text-destructive">
                            Resultados críticos sem ciência
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Ligue para quem pediu ou para a equipe que atende o
                            paciente: a ciência é registrada na tela do
                            resultado.
                        </p>
                        <ul className="divide-y rounded-xl border border-destructive bg-card">
                            {critical.map((alert) => (
                                <li
                                    key={alert.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {alert.place}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            Liberado em {alert.releasedAt} ·
                                            pedido por{' '}
                                            {alert.author ??
                                                'autor desconhecido'}
                                        </span>
                                    </span>
                                    <span className="font-semibold text-destructive tabular-nums">
                                        {alert.minutes < 60
                                            ? `${alert.minutes} min`
                                            : `${Math.floor(alert.minutes / 60)} h ${alert.minutes % 60} min`}{' '}
                                        sem ciência
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {orders.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Nenhum pedido atrasado nesta unidade.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {orders.map((order) => (
                            <li
                                key={order.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                            >
                                <span className="grid gap-0.5">
                                    <span className="font-medium">
                                        Pedido {order.id} · {order.place}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        Pedido em {order.createdAt} por{' '}
                                        {order.author ?? 'autor desconhecido'}
                                    </span>
                                </span>
                                <span className="flex flex-wrap items-center gap-2 text-sm">
                                    {order.priority === 'urgent' && (
                                        <span className="rounded-full bg-destructive px-2 py-0.5 text-xs font-medium text-white">
                                            {priorities[order.priority]}
                                        </span>
                                    )}
                                    <span className="font-semibold text-destructive">
                                        {order.days} dias
                                    </span>
                                    <span className="text-muted-foreground">
                                        {order.pending} sem coleta
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}
