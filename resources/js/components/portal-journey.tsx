import { usePoll } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';

export type Journey = {
    kind: 'appointment' | 'emergency';
    unit: string;
    steps: string[];
    current: number;
    ended: boolean;
    position: number | null;
    waitMinutes: number | null;
    level: string | null;
    who?: string | null;
};

/**
 * Step 7b.2: refresh the given prop every 20 s and say how fresh it is.
 */
export function useLiveJourney(prop: string, data: unknown): string {
    usePoll(20000, { only: [prop] });
    const [updatedAt, setUpdatedAt] = useState(() => Date.now());
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => setUpdatedAt(Date.now()), [data]);
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 30000);

        return () => clearInterval(timer);
    }, []);
    const minutes = Math.floor(Math.max(0, now - updatedAt) / 60000);

    return minutes === 0 ? 'Atualizado agora' : `Atualizado há ${minutes} min`;
}

function Wait({ journey }: { journey: Journey }) {
    if (journey.position === null) {
        return null;
    }

    if (journey.kind === 'appointment') {
        return (
            <p aria-live="polite" className="mt-4 text-base font-medium">
                {journey.position === 1
                    ? 'Você é o próximo.'
                    : `${journey.position}º na fila · cerca de ${journey.waitMinutes} min`}
            </p>
        );
    }

    return (
        <div className="mt-4">
            <p aria-live="polite" className="text-base font-medium">
                {journey.level && `${journey.level} · `}
                {journey.position}º na fila
                {journey.waitMinutes !== null &&
                    (journey.waitMinutes === 0
                        ? ' · atendimento imediato'
                        : ` · atendimento em até ${journey.waitMinutes} min`)}
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
                A ordem segue a gravidade e pode mudar se chegar alguém em
                estado mais grave.
            </p>
        </div>
    );
}

export function JourneyCard({ journey }: { journey: Journey }) {
    return (
        <section className="mt-6">
            <p className="text-sm text-muted-foreground">
                {journey.who && (
                    <span className="font-medium text-foreground">
                        {journey.who} ·{' '}
                    </span>
                )}
                {journey.kind === 'emergency' ? 'Pronto-socorro' : 'Consulta'} ·{' '}
                {journey.unit}
            </p>
            <Wait journey={journey} />
            <ol className="mt-4 grid gap-3" aria-label="Etapas da visita">
                {journey.steps.map((step, index) => {
                    const done = index < journey.current;
                    const now = index === journey.current;

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
        </section>
    );
}
