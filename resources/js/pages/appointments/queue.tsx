import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AppointmentController from '@/actions/App/Http/Controllers/AppointmentController';
import type { AppointmentStatus } from '@/components/appointment-actions';
import {
    AppointmentStatusBadge,
    CancelAppointment,
    NoShowButton,
} from '@/components/appointment-actions';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { queue } from '@/routes/appointments';

type Modality = 'private' | 'insurance' | 'sus';

type QueueItem = {
    id: number;
    start: string;
    patient: string | null;
    professional: string | null;
    walkIn: boolean;
    status: AppointmentStatus;
    arrivedAt: string | null;
    modality: Modality | null;
    insurer: string | null;
    cancelReason: string | null;
    canNoShow: boolean;
};

const modalityLabels: Record<Modality, string> = {
    private: 'Particular',
    insurance: 'Convênio',
    sus: 'SUS',
};

/** Today's reception queue (step 3.5): waiting, arrived, no-shows and cancellations. */
export default function Queue({
    units,
    unit,
    date,
    professionals,
    professionalId,
    insurers,
    appointments,
}: {
    units: { id: number; name: string }[];
    unit: { id: number; name: string; timezone: string | null };
    date: string;
    professionals: { id: number; name: string }[];
    professionalId: number | null;
    insurers: { id: number; name: string }[];
    appointments: QueueItem[];
}) {
    const navigate = (query: Record<string, string | number | null>) =>
        router.get(
            queue({
                query: {
                    unit: unit.id,
                    professional: professionalId,
                    ...query,
                },
            }).url,
            {},
            { preserveScroll: true },
        );
    const waiting = appointments.filter((item) => item.status === 'scheduled');
    const arrived = appointments
        .filter((item) => item.status === 'arrived')
        .sort((a, b) => (a.arrivedAt ?? '').localeCompare(b.arrivedAt ?? ''));
    const closed = appointments.filter(
        (item) => item.status === 'cancelled' || item.status === 'no_show',
    );
    const [year, month, day] = date.split('-');

    return (
        <>
            <Head title="Fila do dia" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={unit.name}
                    title="Fila do dia"
                    description={`Hoje, ${day}/${month}/${year}, no fuso da unidade (${unit.timezone ?? 'não definido'}).`}
                />

                <div className="mb-6 grid gap-4 sm:grid-cols-2">
                    {units.length > 1 && (
                        <Field id="unit" label="Unidade">
                            <select
                                id="unit"
                                value={unit.id}
                                onChange={(event) =>
                                    navigate({
                                        unit: event.target.value,
                                        professional: null,
                                    })
                                }
                                className={selectClassName}
                            >
                                {units.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    )}
                    <Field id="professional" label="Profissional">
                        <select
                            id="professional"
                            value={professionalId ?? ''}
                            onChange={(event) =>
                                navigate({
                                    professional: event.target.value || null,
                                })
                            }
                            className={selectClassName}
                        >
                            <option value="">Todos</option>
                            {professionals.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>

                <dl className="mb-6 grid grid-cols-3 gap-3 text-center">
                    {[
                        ['Aguardando', waiting.length],
                        ['Chegaram', arrived.length],
                        ['Faltas e cancelamentos', closed.length],
                    ].map(([label, count]) => (
                        <div
                            key={label}
                            className="rounded-xl border bg-card px-3 py-4"
                        >
                            <dt className="text-xs text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="mt-1 text-2xl font-semibold tabular-nums">
                                {count}
                            </dd>
                        </div>
                    ))}
                </dl>

                <div className="grid gap-8">
                    <Section
                        title="Aguardando chegada"
                        empty="Ninguém aguardando."
                    >
                        {waiting.map((item) => (
                            <Row key={item.id} item={item}>
                                <ArriveForm
                                    context={`${item.patient} às ${item.start}`}
                                    appointmentId={item.id}
                                    insurers={insurers}
                                />
                                <div className="flex flex-wrap items-start gap-2">
                                    {item.canNoShow && (
                                        <NoShowButton
                                            appointmentId={item.id}
                                            context={`${item.patient} às ${item.start}`}
                                        />
                                    )}
                                    <CancelAppointment
                                        appointmentId={item.id}
                                        context={`${item.patient} às ${item.start}`}
                                    />
                                </div>
                            </Row>
                        ))}
                    </Section>
                    <Section title="Chegaram" empty="Nenhuma chegada ainda.">
                        {arrived.map((item) => (
                            <Row key={item.id} item={item}>
                                <p className="text-muted-foreground">
                                    Chegou às {item.arrivedAt} ·{' '}
                                    {item.modality &&
                                        modalityLabels[item.modality]}
                                    {item.insurer && ` (${item.insurer})`}
                                </p>
                            </Row>
                        ))}
                    </Section>
                    <Section
                        title="Faltas e cancelamentos"
                        empty="Nenhuma falta ou cancelamento hoje."
                    >
                        {closed.map((item) => (
                            <Row key={item.id} item={item}>
                                {item.cancelReason && (
                                    <p className="text-muted-foreground">
                                        Motivo: {item.cancelReason}
                                    </p>
                                )}
                            </Row>
                        ))}
                    </Section>
                </div>
            </Page>
        </>
    );
}

function ArriveForm({
    appointmentId,
    context,
    insurers,
}: {
    appointmentId: number;
    context: string;
    insurers: { id: number; name: string }[];
}) {
    const [modality, setModality] = useState<Modality | ''>('');
    const id = (field: string) => `${field}-${appointmentId}`;

    return (
        <Form
            {...AppointmentController.arrive.form(appointmentId)}
            options={{ preserveScroll: true }}
            aria-label={`Chegada: ${context}`}
            className="grid gap-3 rounded-lg bg-muted/40 p-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            id={id('modality')}
                            label="Modalidade"
                            error={errors.modality}
                        >
                            <select
                                id={id('modality')}
                                name="modality"
                                required
                                value={modality}
                                onChange={(event) =>
                                    setModality(
                                        event.target.value as Modality | '',
                                    )
                                }
                                className={selectClassName}
                            >
                                <option value="" disabled>
                                    Selecione
                                </option>
                                {Object.entries(modalityLabels).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                        {modality === 'insurance' && (
                            <Field
                                id={id('insurer_id')}
                                label="Convênio"
                                error={errors.insurer_id}
                            >
                                <select
                                    id={id('insurer_id')}
                                    name="insurer_id"
                                    required
                                    defaultValue=""
                                    className={selectClassName}
                                >
                                    <option value="" disabled>
                                        {insurers.length === 0
                                            ? 'Nenhum convênio ativo'
                                            : 'Selecione'}
                                    </option>
                                    {insurers.map((insurer) => (
                                        <option
                                            key={insurer.id}
                                            value={insurer.id}
                                        >
                                            {insurer.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        )}
                    </div>
                    {modality === 'insurance' && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                id={id('card')}
                                label="Número da carteira"
                                error={errors.insurance_card_number}
                            >
                                <Input
                                    id={id('card')}
                                    name="insurance_card_number"
                                    required
                                    maxLength={40}
                                    autoComplete="off"
                                    className="h-11"
                                />
                            </Field>
                            <Field
                                id={id('valid_until')}
                                label="Validade da carteira"
                                error={errors.insurance_card_valid_until}
                            >
                                <Input
                                    id={id('valid_until')}
                                    name="insurance_card_valid_until"
                                    type="date"
                                    required
                                    className="h-11"
                                />
                            </Field>
                        </div>
                    )}
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={processing}
                            aria-label={`Confirmar chegada: ${context}`}
                            className="min-h-11"
                        >
                            Confirmar chegada
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Row({ item, children }: { item: QueueItem; children: ReactNode }) {
    return (
        <li className="grid gap-3 px-4 py-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="grid gap-0.5">
                    <span className="font-medium">{item.patient}</span>
                    <span className="text-muted-foreground tabular-nums">
                        {item.start} · {item.professional}
                        {item.walkIn && ' · Encaixe'}
                    </span>
                </span>
                <AppointmentStatusBadge status={item.status} />
            </div>
            {children}
        </li>
    );
}

function Section({
    title,
    empty,
    children,
}: {
    title: string;
    empty: string;
    children: ReactNode[];
}) {
    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">{title}</h2>
            {children.length === 0 ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="divide-y rounded-lg border">{children}</ul>
            )}
        </section>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

Queue.layout = {
    breadcrumbs: [{ title: 'Fila do dia', href: queue() }],
};
