import { Head, Link } from '@inertiajs/react';
import PortalRecord from '@/components/portal-record';
import type {
    PortalAppointment,
    PortalDocument,
    PortalResult,
} from '@/components/portal-record';
import PortalChildController from '@/actions/App/Http/Controllers/Portal/PortalChildController';
import PortalMyDataController from '@/actions/App/Http/Controllers/Portal/PortalMyDataController';
import PortalVisitsController from '@/actions/App/Http/Controllers/Portal/PortalVisitsController';
import PortalTwoFactorController from '@/actions/App/Http/Controllers/Portal/PortalTwoFactorController';

export default function PortalHome({
    network,
    patient,
    children,
    results,
    appointments,
    documents,
    visits,
}: {
    network: { name: string; slug: string };
    patient: { name: string };
    children: { id: number; name: string }[];
    results: PortalResult[];
    appointments: PortalAppointment[];
    documents: PortalDocument[];
    visits: number;
}) {
    return (
        <>
            <Head title="Portal do paciente" />
            <h1 className="text-2xl font-semibold tracking-tight">
                Olá, {patient.name.split(' ')[0]}
            </h1>
            {visits > 0 && (
                <Link
                    href={PortalVisitsController.url(network.slug)}
                    className="mt-6 flex min-h-11 items-center justify-between rounded-md border border-primary bg-primary/10 px-4 font-medium"
                >
                    {visits === 1
                        ? 'Você tem uma visita em andamento'
                        : `${visits} visitas em andamento`}
                    <span className="text-primary">Acompanhar</span>
                </Link>
            )}
            <PortalRecord
                slug={network.slug}
                results={results}
                appointments={appointments}
                documents={documents}
            />
            {children.length > 0 && (
                <section aria-labelledby="children-title" className="mt-8">
                    <h2 id="children-title" className="text-lg font-medium">
                        Filhos menores
                    </h2>
                    <ul className="mt-3 grid gap-2">
                        {children.map((child) => (
                            <li key={child.id}>
                                <Link
                                    href={PortalChildController.url([
                                        network.slug,
                                        child.id,
                                    ])}
                                    className="flex min-h-11 items-center rounded-md border bg-card px-4 font-medium hover:bg-accent"
                                >
                                    {child.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            <p className="mt-6 text-sm">
                <Link
                    href={PortalMyDataController.show.url(network.slug)}
                    className="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                >
                    Meus dados
                </Link>
            </p>
            <p className="text-sm">
                <Link
                    href={PortalTwoFactorController.show.url(network.slug)}
                    className="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                >
                    Segurança da conta
                </Link>
            </p>
        </>
    );
}
