import { Head, Link } from '@inertiajs/react';
import PortalChildController from '@/actions/App/Http/Controllers/Portal/PortalChildController';
import PortalTwoFactorController from '@/actions/App/Http/Controllers/Portal/PortalTwoFactorController';

export default function PortalHome({
    network,
    patient,
    children,
}: {
    network: { name: string; slug: string };
    patient: { name: string };
    children: { id: number; name: string }[];
}) {
    return (
        <>
            <Head title="Portal do paciente" />
            <h1 className="text-2xl font-semibold tracking-tight">
                Olá, {patient.name.split(' ')[0]}
            </h1>
            <p className="mt-2 text-sm text-muted-foreground">
                Seu acesso ao portal está ativo. Em breve você verá aqui seus
                resultados de exame, suas próximas consultas e os documentos
                liberados pela equipe.
            </p>
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
                    href={PortalTwoFactorController.show.url(network.slug)}
                    className="font-medium text-primary underline-offset-4 hover:underline"
                >
                    Segurança da conta
                </Link>
            </p>
        </>
    );
}
