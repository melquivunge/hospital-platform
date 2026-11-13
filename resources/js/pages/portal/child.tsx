import { Head, Link } from '@inertiajs/react';
import PortalHomeController from '@/actions/App/Http/Controllers/Portal/PortalHomeController';

/**
 * Step 7a.2: a minor linked to the guardian, with the same content as the patient's own start page (7a.3).
 */
export default function PortalChild({
    network,
    child,
}: {
    network: { name: string; slug: string };
    child: { id: number; name: string };
}) {
    return (
        <>
            <Head title={child.name} />
            <p className="text-sm">
                <Link
                    href={PortalHomeController.url(network.slug)}
                    className="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                >
                    Voltar para o início
                </Link>
            </p>
            <p className="mt-2 text-sm text-muted-foreground">Filhos menores</p>
            <h1 className="text-2xl font-semibold tracking-tight">
                {child.name}
            </h1>
            <p className="mt-2 text-sm text-muted-foreground">
                Em breve você verá aqui os resultados de exame, as próximas
                consultas e os documentos liberados para este paciente.
            </p>
        </>
    );
}
