import { Head, Link } from '@inertiajs/react';
import PortalHomeController from '@/actions/App/Http/Controllers/Portal/PortalHomeController';
import { JourneyCard, useLiveJourney } from '@/components/portal-journey';
import type { Journey } from '@/components/portal-journey';

/**
 * Step 7b.2: the signed-in patient's live journey — the same screen as the QR, for each arrival on now.
 */
export default function PortalVisits({
    network,
    visits,
}: {
    network: { name: string; slug: string };
    visits: Journey[];
}) {
    const updated = useLiveJourney('visits', visits);

    return (
        <>
            <Head title="Visitas em andamento" />
            <h1 className="text-2xl font-semibold tracking-tight">
                Visitas em andamento
            </h1>
            {visits.length === 0 ? (
                <p className="mt-2 text-sm text-muted-foreground">
                    Nenhuma visita em andamento. Quando você chegar ao hospital,
                    ela aparece aqui.
                </p>
            ) : (
                visits.map((visit, index) => (
                    <JourneyCard key={index} journey={visit} />
                ))
            )}
            <p className="mt-6 text-xs text-muted-foreground">{updated}</p>
            <p className="text-sm">
                <Link
                    href={PortalHomeController.url(network.slug)}
                    className="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                >
                    Voltar ao início
                </Link>
            </p>
        </>
    );
}
