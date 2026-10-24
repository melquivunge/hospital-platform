import { Form, Head } from '@inertiajs/react';
import ExamOrderController from '@/actions/App/Http/Controllers/ExamOrderController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Order = {
    id: number;
    priority: string;
    indication: string;
    status: 'requested' | 'cancelled';
    author: string | null;
    createdAt: string;
    cancellationReason: string | null;
    items: { id: number; name: string; type: string }[];
    canCancel: boolean;
};

type CatalogExam = {
    id: number;
    name: string;
    type: string;
    preparation: string | null;
};

/**
 * The exam orders of one visit or admission (step 5.2, delivery 5): the professional who attends orders exams of
 * the catalog, with priority and clinical indication, signed with the password; the author cancels with a reason.
 */
export default function ExamOrders({
    patient,
    encounter,
    orders,
    catalog,
    types,
    priorities,
}: {
    patient: { name: string };
    encounter: { type: 'admission' | 'visit'; id: number };
    orders: Order[];
    catalog: CatalogExam[];
    types: Record<string, string>;
    priorities: Record<string, string>;
}) {
    const action =
        encounter.type === 'admission'
            ? ExamOrderController.storeForAdmission.form(encounter.id)
            : ExamOrderController.storeForVisit.form(encounter.id);

    return (
        <>
            <Head title="Exames do paciente" />
            <Page>
                <PageHeader
                    eyebrow={patient.name}
                    title="Exames"
                    description={
                        encounter.type === 'admission'
                            ? 'Pedidos de exames desta internação.'
                            : 'Pedidos de exames deste atendimento.'
                    }
                />

                <section className="mb-8 grid gap-3">
                    <h2 className="text-lg font-semibold">Novo pedido</h2>
                    <Form
                        {...action}
                        errorBag="order"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        resetOnError={['password']}
                        className="grid gap-4 rounded-xl border bg-card p-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                {Object.entries(types).map(([type, label]) => {
                                    const exams = catalog.filter(
                                        (exam) => exam.type === type,
                                    );

                                    return (
                                        exams.length > 0 && (
                                            <fieldset
                                                key={type}
                                                className="grid gap-1"
                                            >
                                                <legend className="mb-1 text-sm font-medium">
                                                    {label}
                                                </legend>
                                                {exams.map((exam) => (
                                                    <label
                                                        key={exam.id}
                                                        className="flex min-h-11 items-start gap-3 py-1 text-sm"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            name="exam_ids[]"
                                                            value={exam.id}
                                                            className="mt-0.5 size-5 shrink-0"
                                                        />
                                                        <span>
                                                            {exam.name}
                                                            {exam.preparation && (
                                                                <span className="block text-muted-foreground">
                                                                    Preparo:{' '}
                                                                    {
                                                                        exam.preparation
                                                                    }
                                                                </span>
                                                            )}
                                                        </span>
                                                    </label>
                                                ))}
                                            </fieldset>
                                        )
                                    );
                                })}
                                {catalog.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        O catálogo de exames da rede está vazio.
                                    </p>
                                )}
                                <InputError
                                    message={
                                        errors.exam_ids ?? errors['exam_ids.0']
                                    }
                                />
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="priority">
                                            Prioridade
                                        </Label>
                                        <select
                                            id="priority"
                                            name="priority"
                                            defaultValue="routine"
                                            className={selectClassName}
                                        >
                                            {Object.entries(priorities).map(
                                                ([value, label]) => (
                                                    <option
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <InputError message={errors.priority} />
                                    </div>
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="indication">
                                            Indicação clínica
                                        </Label>
                                        <textarea
                                            id="indication"
                                            name="indication"
                                            required
                                            maxLength={1000}
                                            rows={3}
                                            className="rounded-md border bg-background px-3 py-2 text-sm"
                                        />
                                        <InputError
                                            message={errors.indication}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            Sua senha (assinatura)
                                        </Label>
                                        <Input
                                            id="password"
                                            name="password"
                                            type="password"
                                            required
                                            autoComplete="current-password"
                                            className="h-11"
                                        />
                                        <InputError message={errors.password} />
                                    </div>
                                </div>
                                <div>
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || catalog.length === 0
                                        }
                                        className="min-h-11"
                                    >
                                        Assinar e pedir
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">Pedidos</h2>
                    {orders.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-8 text-center text-muted-foreground">
                            Nenhum pedido de exame.
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {orders.map((order) => (
                                <li
                                    key={order.id}
                                    className="rounded-xl border bg-card px-5 py-4"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <h3 className="font-medium">
                                            {order.items
                                                .map((item) => item.name)
                                                .join(', ')}
                                        </h3>
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${order.priority === 'urgent' ? 'bg-destructive text-white' : 'bg-muted'}`}
                                        >
                                            {priorities[order.priority]}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {order.createdAt} · {order.author}
                                        {order.status === 'cancelled' &&
                                            ` · cancelado: ${order.cancellationReason}`}
                                    </p>
                                    <p className="mt-2 text-sm">
                                        {order.indication}
                                    </p>
                                    {order.canCancel && (
                                        <details className="mt-2">
                                            <summary className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary">
                                                Cancelar pedido
                                            </summary>
                                            <Form
                                                {...ExamOrderController.cancel.form(
                                                    order.id,
                                                )}
                                                errorBag={`cancel${order.id}`}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="mt-2 grid gap-2"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <Label
                                                            htmlFor={`cancel-${order.id}`}
                                                        >
                                                            Motivo do
                                                            cancelamento
                                                        </Label>
                                                        <Input
                                                            id={`cancel-${order.id}`}
                                                            name="reason"
                                                            required
                                                            maxLength={500}
                                                            className="h-11"
                                                        />
                                                        <InputError
                                                            message={
                                                                errors.reason
                                                            }
                                                        />
                                                        <div>
                                                            <Button
                                                                type="submit"
                                                                variant="outline"
                                                                disabled={
                                                                    processing
                                                                }
                                                                className="min-h-11"
                                                            >
                                                                Cancelar pedido
                                                            </Button>
                                                        </div>
                                                    </>
                                                )}
                                            </Form>
                                        </details>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}
