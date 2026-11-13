import { Head } from '@inertiajs/react';
import { JourneyCard, useLiveJourney } from '@/components/portal-journey';
import type { Journey } from '@/components/portal-journey';

/**
 * Steps 7b.1 and 7b.2: one arrival's journey, opened by the QR without an account, refreshed every 20 s.
 */
export default function PortalVisit({
    visit,
}: {
    network: { name: string; slug: string };
    visit: Journey | null;
}) {
    const updated = useLiveJourney(
        'visit',
        visit,
        visit !== null && !visit.ended,
    );

    if (visit === null) {
        return (
            <>
                <Head title="Sua visita" />
                <h1 className="text-2xl font-semibold tracking-tight">
                    QR inválido ou vencido
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Peça um novo QR na recepção.
                </p>
            </>
        );
    }

    return (
        <>
            <Head title="Sua visita" />
            <h1 className="text-2xl font-semibold tracking-tight">
                Sua visita
            </h1>
            <JourneyCard journey={visit} />
            <p className="mt-6 text-xs text-muted-foreground">{updated}</p>
        </>
    );
}
