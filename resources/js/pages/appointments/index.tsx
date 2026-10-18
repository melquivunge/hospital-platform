import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarPlus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import AppointmentController from '@/actions/App/Http/Controllers/AppointmentController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/appointments';
import { index as patients } from '@/routes/patients';

type Slot = {
    startsAt: string;
    time: string;
    state: 'free' | 'booked' | 'blocked' | 'past';
};

type Appointment = {
    id: number;
    start: string;
    end: string;
    patientId: number;
    patient: string | null;
    walkIn: boolean;
    status: 'scheduled' | 'cancelled' | 'no_show';
    cancelReason: string | null;
    rescheduled: boolean;
    canNoShow: boolean;
};

const slotLabels: Record<Slot['state'], string> = {
    free: 'Livre',
    booked: 'Ocupado',
    blocked: 'Bloqueado',
    past: 'Passou',
};

const statusLabels: Record<Appointment['status'], string> = {
    scheduled: 'Agendado',
    cancelled: 'Cancelado',
    no_show: 'Faltou',
};

function dayTitle(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

/** Reception books patients into the schedule grid (step 3.4b). */
export default function Appointments({
    units,
    unit,
    professionals,
    professionalId,
    date,
    patient,
    rescheduling,
    slots,
    appointments,
}: {
    units: { id: number; name: string }[];
    unit: { id: number; name: string; timezone: string | null };
    professionals: { id: number; name: string }[];
    professionalId: number | null;
    date: string;
    patient: { id: number; name: string } | null;
    rescheduling: { id: number; when: string } | null;
    slots: Slot[];
    appointments: Appointment[];
}) {
    const { errors } = usePage().props;
    const [reason, setReason] = useState('');
    const [walkInTime, setWalkInTime] = useState('');
    const [processing, setProcessing] = useState(false);

    const navigate = (query: Record<string, string | number | null>) =>
        router.get(
            index({
                query: {
                    unit: unit.id,
                    professional: professionalId,
                    date,
                    patient: patient?.id ?? null,
                    reschedule: rescheduling?.id ?? null,
                    ...query,
                },
            }).url,
            {},
            { preserveScroll: true },
        );

    const book = (startsAt: string, walkIn: boolean) =>
        router.post(
            AppointmentController.store.url(unit.id),
            {
                professional_id: professionalId,
                patient_id: patient?.id ?? null,
                starts_at: startsAt,
                walk_in: walkIn,
                rescheduled_from_id: rescheduling?.id ?? null,
                reason: rescheduling ? reason : null,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setWalkInTime(''),
            },
        );

    return (
        <>
            <Head title="Agendamentos" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={unit.name}
                    title="Agendamentos"
                    description={`Horários no fuso da unidade (${unit.timezone ?? 'não definido'}).`}
                />

                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    {units.length > 1 && (
                        <Field id="unit" label="Unidade">
                            <select
                                id="unit"
                                value={unit.id}
                                onChange={(event) =>
                                    navigate({
                                        unit: event.target.value,
                                        professional: null,
                                        reschedule: null,
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
                                navigate({ professional: event.target.value })
                            }
                            className={selectClassName}
                            disabled={professionals.length === 0}
                        >
                            {professionals.length === 0 && (
                                <option value="">Nenhum habilitado</option>
                            )}
                            {professionals.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field id="date" label="Dia">
                        <Input
                            id="date"
                            type="date"
                            value={date}
                            onChange={(event) =>
                                event.target.value &&
                                navigate({ date: event.target.value })
                            }
                            className="h-11"
                        />
                    </Field>
                </div>

                <section
                    aria-label="Paciente"
                    className="mb-6 grid gap-3 rounded-xl border bg-card p-5"
                >
                    {patient ? (
                        <>
                            <p className="text-sm">
                                {rescheduling ? 'Remarcando' : 'Agendando'} para{' '}
                                <span className="font-semibold">
                                    {patient.name}
                                </span>
                                {rescheduling &&
                                    ` (consulta de ${rescheduling.when})`}
                            </p>
                            {rescheduling && (
                                <Field
                                    id="reason"
                                    label="Motivo da remarcação"
                                    error={errors.reason}
                                >
                                    <Input
                                        id="reason"
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                        maxLength={255}
                                        required
                                        className="h-11"
                                    />
                                </Field>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link href={patients()}>
                                        Trocar paciente
                                    </Link>
                                </Button>
                                {rescheduling && (
                                    <Button
                                        variant="ghost"
                                        className="min-h-11"
                                        onClick={() =>
                                            navigate({
                                                reschedule: null,
                                                patient: null,
                                            })
                                        }
                                    >
                                        Desistir da remarcação
                                    </Button>
                                )}
                            </div>
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Para agendar, busque o paciente em{' '}
                            <Link
                                href={patients()}
                                className="font-medium text-foreground underline underline-offset-4"
                            >
                                Pacientes
                            </Link>{' '}
                            e escolha “Agendar consulta”.
                        </p>
                    )}
                    <InputError
                        message={errors.starts_at ?? errors.patient_id}
                    />
                </section>

                <h2 className="mb-4 text-lg font-semibold first-letter:uppercase">
                    {dayTitle(date)}
                </h2>

                <div className="grid gap-8">
                    <Card title="Horários">
                        {slots.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                O profissional não atende nesta unidade neste
                                dia.
                            </p>
                        ) : (
                            <ul className="grid grid-cols-3 gap-2 sm:grid-cols-5">
                                {slots.map((slot) => {
                                    const bookable =
                                        slot.state === 'free' &&
                                        patient !== null;

                                    return (
                                        <li key={slot.startsAt}>
                                            <Button
                                                type="button"
                                                variant={
                                                    bookable
                                                        ? 'outline'
                                                        : 'ghost'
                                                }
                                                disabled={
                                                    !bookable || processing
                                                }
                                                onClick={() =>
                                                    book(slot.startsAt, false)
                                                }
                                                aria-label={`${slot.time}, ${slotLabels[slot.state]}${bookable ? ': agendar' : ''}`}
                                                className="h-auto min-h-11 w-full flex-col gap-0 py-1.5 tabular-nums"
                                            >
                                                <span className="font-medium">
                                                    {slot.time}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {slotLabels[slot.state]}
                                                </span>
                                            </Button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        {patient && slots.length > 0 && (
                            <form
                                className="flex flex-wrap items-end gap-3 border-t pt-5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    book(`${date}T${walkInTime}`, true);
                                }}
                            >
                                <Field id="walk_in_time" label="Encaixe às">
                                    <Input
                                        id="walk_in_time"
                                        type="time"
                                        value={walkInTime}
                                        onChange={(event) =>
                                            setWalkInTime(event.target.value)
                                        }
                                        required
                                        className="h-11"
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    <CalendarPlus aria-hidden="true" />
                                    Registrar encaixe
                                </Button>
                            </form>
                        )}
                    </Card>

                    <Card title="Agendamentos do dia">
                        {appointments.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhum agendamento neste dia.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {appointments.map((appointment) => (
                                    <AppointmentRow
                                        key={appointment.id}
                                        appointment={appointment}
                                        onReschedule={() =>
                                            navigate({
                                                reschedule: appointment.id,
                                                patient: null,
                                            })
                                        }
                                    />
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </Page>
        </>
    );
}

function AppointmentRow({
    appointment,
    onReschedule,
}: {
    appointment: Appointment;
    onReschedule: () => void;
}) {
    const open = appointment.status === 'scheduled';

    return (
        <li className="grid gap-2 px-4 py-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="grid gap-0.5">
                    <span className="font-medium">{appointment.patient}</span>
                    <span className="text-muted-foreground tabular-nums">
                        {appointment.start}–{appointment.end}
                        {appointment.walkIn && ' · Encaixe'}
                        {appointment.rescheduled && ' · Remarcado'}
                    </span>
                </span>
                <span
                    className={
                        open
                            ? 'rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary'
                            : 'rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground'
                    }
                >
                    {statusLabels[appointment.status]}
                </span>
            </div>
            {appointment.cancelReason && (
                <p className="text-muted-foreground">
                    Motivo: {appointment.cancelReason}
                </p>
            )}
            {open && (
                <div className="flex flex-wrap items-start gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={onReschedule}
                    >
                        Remarcar
                    </Button>
                    {appointment.canNoShow && (
                        <Form
                            {...AppointmentController.noShow.form(
                                appointment.id,
                            )}
                            options={{ preserveScroll: true }}
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                className="min-h-11"
                            >
                                Faltou
                            </Button>
                        </Form>
                    )}
                    <details className="group">
                        <summary className="inline-flex min-h-11 cursor-pointer items-center rounded-md px-3 text-muted-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                            Cancelar
                        </summary>
                        <Form
                            {...AppointmentController.cancel.form(
                                appointment.id,
                            )}
                            options={{ preserveScroll: true }}
                            className="mt-2 flex flex-wrap items-end gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Field
                                        id={`reason-${appointment.id}`}
                                        label="Motivo do cancelamento"
                                        error={errors.reason}
                                    >
                                        <Input
                                            id={`reason-${appointment.id}`}
                                            name="reason"
                                            required
                                            maxLength={255}
                                            className="h-11"
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        Confirmar cancelamento
                                    </Button>
                                </>
                            )}
                        </Form>
                    </details>
                </div>
            )}
        </li>
    );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="grid gap-5 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">{title}</h2>
            {children}
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

Appointments.layout = {
    breadcrumbs: [{ title: 'Agendamentos', href: index() }],
};
