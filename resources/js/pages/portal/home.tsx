import { Head, Link } from '@inertiajs/react';
import PortalTwoFactorController from '@/actions/App/Http/Controllers/Portal/PortalTwoFactorController';

export default function PortalHome({
    network,
    patient,
}: {
    network: { name: string; slug: string };
    patient: { name: string };
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
