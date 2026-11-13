import { Head } from '@inertiajs/react';
import { Check } from 'lucide-react';

/**
 * Step 7b.1: one arrival's journey, opened by the QR without an account. Position and waiting time come in 7b.2.
 */
export default function PortalVisit({
    visit,
}: {
    network: { name: string; slug: string };
    visit: { unit: string; steps: string[]; current: number } | null;
}) {
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
            <p className="mt-2 text-sm text-muted-foreground">{visit.unit}</p>
            <ol className="mt-8 grid gap-3" aria-label="Etapas da visita">
                {visit.steps.map((step, index) => {
                    const done = index < visit.current;
                    const now = index === visit.current;

                    return (
                        <li
                            key={step}
                            aria-current={now ? 'step' : undefined}
                            className={`flex min-h-11 items-center gap-3 rounded-md border px-4 text-sm ${now ? 'border-primary bg-primary/10 font-medium' : 'bg-card'} ${done ? 'text-muted-foreground' : ''}`}
                        >
                            <span
                                className={`flex size-6 shrink-0 items-center justify-center rounded-full border ${done || now ? 'border-primary text-primary' : ''}`}
                                aria-hidden="true"
                            >
                                {done ? (
                                    <Check className="size-4" />
                                ) : (
                                    index + 1
                                )}
                            </span>
                            {step}
                            {now && <span className="sr-only"> (agora)</span>}
                        </li>
                    );
                })}
            </ol>
            <p className="mt-6 text-xs text-muted-foreground">
                Atualize a página para ver a etapa mais recente.
            </p>
        </>
    );
}
