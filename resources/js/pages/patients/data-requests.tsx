import { Form, Head, Link } from '@inertiajs/react';
import PatientDataRequestController from '@/actions/App/Http/Controllers/PatientDataRequestController';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index as patients, show } from '@/routes/patients';

/**
 * Step 7a.3b: correction requests the patients sent from the portal, oldest first.
 */
export default function PatientDataRequests({
    requests,
}: {
    requests: {
        id: number;
        patientId: number;
        patient: string;
        message: string;
        createdAt: string;
    }[];
}) {
    return (
        <>
            <Head title="Pedidos de correção" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Atendimento"
                    title="Pedidos de correção"
                    description="Enviados pelos pacientes no portal. Confira com um documento, corrija o cadastro e marque como resolvido."
                />

                {requests.length === 0 ? (
                    <p className="rounded-xl border bg-card px-4 py-6 text-sm text-muted-foreground">
                        Nenhum pedido em aberto.
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {requests.map((request) => (
                            <li
                                key={request.id}
                                className="grid gap-3 rounded-xl border bg-card px-4 py-4 text-sm"
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <Link
                                        href={show(request.patientId)}
                                        className="font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        {request.patient}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {new Intl.DateTimeFormat('pt-BR', {
                                            dateStyle: 'short',
                                            timeStyle: 'short',
                                        }).format(new Date(request.createdAt))}
                                    </span>
                                </div>
                                <p className="whitespace-pre-line">
                                    {request.message}
                                </p>
                                <Form
                                    {...PatientDataRequestController.resolve.form(
                                        request.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            className="min-h-11"
                                        >
                                            {processing && <Spinner />}
                                            Marcar como resolvido
                                        </Button>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

PatientDataRequests.layout = {
    breadcrumbs: [
        { title: 'Pacientes', href: patients() },
        { title: 'Pedidos de correção' },
    ],
};
