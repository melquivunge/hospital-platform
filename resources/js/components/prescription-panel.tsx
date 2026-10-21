import { Form, Link } from '@inertiajs/react';
import { Pill } from 'lucide-react';
import PrescriptionController from '@/actions/App/Http/Controllers/PrescriptionController';
import { Button } from '@/components/ui/button';

export type PrescriptionItemView = {
    id: number;
    medication: string;
    dose: string;
    route: string;
    schedule: string;
    startsAt: string;
    notes: string | null;
    storedAlerts: string[];
    justification: string | null;
};

export type PrescriptionSummary = {
    id: number;
    signedAt: string | null;
    validUntil: string | null;
    author: string | null;
    items: PrescriptionItemView[];
};

export type PrescriptionPanelData = {
    active: PrescriptionSummary | null;
    previous: PrescriptionSummary[];
    draftId: number | null;
    canPrescribe: boolean;
};

/** One item as signed: what, how much, how and when, with the alerts and the prescriber's justification. */
export function PrescriptionItemLine({ item }: { item: PrescriptionItemView }) {
    return (
        <div className="grid gap-1 text-sm">
            <p>
                <span className="font-medium">{item.medication}</span> ·{' '}
                {item.dose} · {item.route} · {item.schedule}
            </p>
            <p className="text-xs text-muted-foreground">
                Início {item.startsAt}
                {item.notes && ` · ${item.notes}`}
            </p>
            {item.storedAlerts.length > 0 && (
                <p className="text-xs">
                    <span className="font-medium text-destructive">
                        Alerta: {item.storedAlerts.join(' ')}
                    </span>{' '}
                    Justificativa: {item.justification}
                </p>
            )}
        </div>
    );
}

/**
 * The prescription on the record pages (step 4b.2a): the active one for everyone with access, the earlier ones,
 * and for the doctor a way into their own draft.
 */
export function PrescriptionPanel({
    data,
    start,
}: {
    data: PrescriptionPanelData;
    start: { action: string; method: 'post' };
}) {
    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 font-semibold">
                    <Pill className="size-4 text-primary" aria-hidden="true" />
                    Prescrição
                </h2>
                {data.canPrescribe &&
                    (data.draftId !== null ? (
                        <Button asChild className="min-h-11">
                            <Link
                                href={PrescriptionController.edit.url(
                                    data.draftId,
                                )}
                            >
                                Continuar rascunho
                            </Link>
                        </Button>
                    ) : (
                        <Form {...start}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    Nova prescrição
                                </Button>
                            )}
                        </Form>
                    ))}
            </div>
            {data.active === null ? (
                <p className="text-sm text-muted-foreground">
                    Nenhuma prescrição válida agora.
                </p>
            ) : (
                <div className="grid gap-3">
                    <p className="text-xs text-muted-foreground">
                        Assinada em {data.active.signedAt} por{' '}
                        {data.active.author} · válida até{' '}
                        {data.active.validUntil}
                    </p>
                    <ol className="grid gap-3">
                        {data.active.items.map((item) => (
                            <li
                                key={item.id}
                                className="rounded-lg border px-4 py-3"
                            >
                                <PrescriptionItemLine item={item} />
                            </li>
                        ))}
                    </ol>
                </div>
            )}
            {data.previous.length > 0 && (
                <details>
                    <summary className="inline-flex min-h-11 cursor-pointer items-center text-sm text-muted-foreground">
                        Prescrições anteriores ({data.previous.length})
                    </summary>
                    <ul className="grid gap-4 pt-2">
                        {data.previous.map((prescription) => (
                            <li key={prescription.id} className="grid gap-2">
                                <p className="text-xs text-muted-foreground">
                                    Assinada em {prescription.signedAt} por{' '}
                                    {prescription.author} · válida até{' '}
                                    {prescription.validUntil}
                                </p>
                                <ol className="grid gap-2 border-l-2 pl-4">
                                    {prescription.items.map((item) => (
                                        <li key={item.id}>
                                            <PrescriptionItemLine item={item} />
                                        </li>
                                    ))}
                                </ol>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}
