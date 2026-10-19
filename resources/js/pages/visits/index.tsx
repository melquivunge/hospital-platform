import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ClipboardPlus } from 'lucide-react';
import VisitController from '@/actions/App/Http/Controllers/VisitController';
import type { AppointmentStatus } from '@/components/appointment-actions';
import { AppointmentStatusBadge } from '@/components/appointment-actions';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/visits';

type Item = {
    id: number;
    start: string;
    unit: string | null;
    patient: string | null;
    status: AppointmentStatus;
    walkIn: boolean;
    visitId: number | null;
};

/** Patients who arrived for the professional and visits in progress (step 3.6a). */
export default function Visits({
    appointments,
    emergencies,
}: {
    appointments: Item[];
    emergencies: {
        id: number;
        start: string;
        unit: string | null;
        patient: string | null;
    }[];
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Meus atendimentos" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Atendimento"
                    title="Meus atendimentos"
                    description="Pacientes que já chegaram para você e atendimentos em andamento. O prontuário abre só durante o atendimento, e cada leitura fica registrada."
                />
                <InputError message={errors.visit} className="mb-4" />

                {emergencies.length > 0 && (
                    <section
                        aria-labelledby="emergency-heading"
                        className="mb-6 grid gap-3"
                    >
                        <h2 id="emergency-heading" className="font-semibold">
                            Urgência em andamento
                        </h2>
                        <ul className="divide-y rounded-xl border bg-card">
                            {emergencies.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {item.patient}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            Desde {item.start} · {item.unit}
                                        </span>
                                    </span>
                                    <Button asChild className="min-h-11">
                                        <Link
                                            href={show(item.id)}
                                            aria-label={`Continuar atendimento de urgência: ${item.patient}`}
                                        >
                                            Continuar
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {appointments.length === 0 ? (
                    <div className="rounded-xl border bg-card px-5 py-12 text-center">
                        <ClipboardPlus
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Ninguém aguardando</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Os pacientes aparecem aqui quando a recepção
                            confirma a chegada.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {appointments.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm"
                            >
                                <span className="grid gap-0.5">
                                    <span className="font-medium">
                                        {item.patient}
                                    </span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {item.start} · {item.unit}
                                        {item.walkIn && ' · Encaixe'}
                                    </span>
                                </span>
                                <span className="flex items-center gap-3">
                                    <AppointmentStatusBadge
                                        status={item.status}
                                    />
                                    {item.visitId !== null ? (
                                        <Button asChild className="min-h-11">
                                            <Link href={show(item.visitId)}>
                                                Continuar
                                            </Link>
                                        </Button>
                                    ) : (
                                        <Form
                                            {...VisitController.open.form(
                                                item.id,
                                            )}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    aria-label={`Iniciar atendimento: ${item.patient} às ${item.start}`}
                                                    className="min-h-11"
                                                >
                                                    Iniciar atendimento
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

Visits.layout = {
    breadcrumbs: [{ title: 'Meus atendimentos', href: index() }],
};
