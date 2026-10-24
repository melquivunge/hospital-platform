import { Head, router } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/exam-overdue';

/**
 * Orders without collection for 7 days (step 5.3b, ADR-0008), for the unit manager: when, priority, where, who
 * ordered and how many items are missing. Never the exams or the record.
 */
export default function ExamOverdue({
    units,
    unitId,
    days,
    priorities,
    orders,
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
