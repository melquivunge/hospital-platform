import { Form, Head } from '@inertiajs/react';
import SurgeryOrderController from '@/actions/App/Http/Controllers/SurgeryOrderController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Surgery = {
    id: number;
    procedure: string;
    laterality: string;
    urgency: string;
    desiredDate: string;
    indication: string;
    status: 'requested' | 'scheduled' | 'in_progress' | 'cancelled';
    scheduledStart: string | null;
    author: string | null;
    createdAt: string;
    cancellationReason: string | null;
    canCancel: boolean;
};

/**
 * The surgery orders of one visit or admission (step 5b.2, delivery 5b): the professional who attends orders a
 * procedure of the catalog with the side, the urgency, the wished date and the clinical indication, signed with the
 * password; the author cancels with a reason.
 */
export default function SurgeryOrders({
    patient,
    encounter,
    surgeries,
    catalog,
    lateralities,
    urgencies,
    today,
    maxDate,
}: {
    patient: { name: string };
    encounter: { type: 'admission' | 'visit'; id: number };
    surgeries: Surgery[];
    catalog: { id: number; name: string; duration_minutes: number }[];
    lateralities: Record<string, string>;
    urgencies: Record<string, string>;
    today: string;
    maxDate: string;
}) {
    const action =
        encounter.type === 'admission'
            ? SurgeryOrderController.storeForAdmission.form(encounter.id)
            : SurgeryOrderController.storeForVisit.form(encounter.id);

    return (
        <>
            <Head title="Cirurgias do paciente" />
            <Page>
                <PageHeader
                    eyebrow={patient.name}
                    title="Cirurgias"
                    description={
                        encounter.type === 'admission'
                            ? 'Pedidos de cirurgia desta internação.'
                            : 'Pedidos de cirurgia deste atendimento.'
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
                                {catalog.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        O catálogo de procedimentos da rede está
                                        vazio.
                                    </p>
                                )}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2 sm:col-span-2">
                                        <Label htmlFor="surgical_procedure_id">
                                            Procedimento
                                        </Label>
                                        <select
                                            id="surgical_procedure_id"
                                            name="surgical_procedure_id"
                                            required
                                            defaultValue=""
                                            className={selectClassName}
                                        >
                                            <option value="" disabled>
                                                Escolha o procedimento
                                            </option>
                                            {catalog.map((procedure) => (
                                                <option
                                                    key={procedure.id}
                                                    value={procedure.id}
                                                >
                                                    {procedure.name} (
                                                    {procedure.duration_minutes}{' '}
                                                    min)
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={
                                                errors.surgical_procedure_id
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="laterality">
                                            Lateralidade
                                        </Label>
                                        <select
                                            id="laterality"
                                            name="laterality"
                                            required
                                            defaultValue=""
                                            className={selectClassName}
                                        >
                                            <option value="" disabled>
                                                Escolha
                                            </option>
                                            {Object.entries(lateralities).map(
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
                                        <InputError
                                            message={errors.laterality}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="urgency">Caráter</Label>
                                        <select
                                            id="urgency"
                                            name="urgency"
                                            defaultValue="elective"
                                            className={selectClassName}
                                        >
                                            {Object.entries(urgencies).map(
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
                                        <InputError message={errors.urgency} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="desired_date">
                                            Data desejada
                                        </Label>
                                        <Input
                                            id="desired_date"
                                            name="desired_date"
                                            type="date"
                                            required
                                            min={today}
                                            max={maxDate}
                                            defaultValue={today}
                                            className="h-11"
                                        />
                                        <InputError
                                            message={errors.desired_date}
                                        />
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
                    {surgeries.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-8 text-center text-muted-foreground">
                            Nenhum pedido de cirurgia.
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {surgeries.map((surgery) => (
                                <li
                                    key={surgery.id}
                                    className="rounded-xl border bg-card px-5 py-4"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <h3
                                            className={`font-medium ${surgery.status === 'cancelled' ? 'text-muted-foreground line-through' : ''}`}
                                        >
                                            {surgery.procedure}
                                            {surgery.laterality !== 'none' &&
                                                ` · ${lateralities[surgery.laterality]}`}
                                        </h3>
                                        <span className="flex gap-2">
                                            {surgery.status === 'cancelled' && (
                                                <span className="rounded-full border px-2 py-0.5 text-xs font-medium">
                                                    Cancelado
                                                </span>
                                            )}
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-xs font-medium ${surgery.urgency === 'urgent' ? 'bg-destructive text-white' : 'bg-muted'}`}
                                            >
                                                {urgencies[surgery.urgency]}
                                            </span>
                                        </span>
                                    </div>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Desejada para {surgery.desiredDate} ·
                                        pedido em {surgery.createdAt} ·{' '}
                                        {surgery.author}
                                        {surgery.status === 'scheduled' &&
                                            ` · agendada para ${surgery.scheduledStart}`}
                                        {surgery.status === 'in_progress' &&
                                            ' · em andamento'}
                                        {surgery.status === 'cancelled' &&
                                            ` · cancelado: ${surgery.cancellationReason}`}
                                    </p>
                                    <p className="mt-2 text-sm">
                                        {surgery.indication}
                                    </p>
                                    {surgery.canCancel && (
                                        <details className="mt-2">
                                            <summary className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary">
                                                Cancelar pedido
                                            </summary>
                                            <Form
                                                {...SurgeryOrderController.cancel.form(
                                                    surgery.id,
                                                )}
                                                errorBag={`cancel${surgery.id}`}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="mt-2 grid gap-2"
                                            >
                                                {({ processing, errors }) => (
                                                    <>
                                                        <Label
                                                            htmlFor={`cancel-${surgery.id}`}
                                                        >
                                                            Motivo do
                                                            cancelamento
                                                        </Label>
                                                        <Input
                                                            id={`cancel-${surgery.id}`}
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
