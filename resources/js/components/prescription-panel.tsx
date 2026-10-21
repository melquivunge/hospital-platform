import { Form, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    suspendedAt: string | null;
    suspensionReason: string | null;
    doses?: string[];
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
    canSuspend: boolean;
};

/** One item as signed: what, how much, how and when, with the alerts and the prescriber's justification. */
export function PrescriptionItemLine({ item }: { item: PrescriptionItemView }) {
    return (
        <div className="grid gap-1 text-sm">
            <p
                className={
                    item.suspendedAt ? 'text-muted-foreground' : undefined
                }
            >
                <span
                    className={
                        item.suspendedAt
                            ? 'font-medium line-through'
                            : 'font-medium'
                    }
                >
                    {item.medication}
                </span>{' '}
                · {item.dose} · {item.route} · {item.schedule}
            </p>
            <p className="text-xs text-muted-foreground">
                Início {item.startsAt}
                {item.notes && ` · ${item.notes}`}
            </p>
            {item.suspendedAt && (
                <p className="text-xs font-medium">
                    Suspenso em {item.suspendedAt}: {item.suspensionReason}
                </p>
            )}
            {!item.suspendedAt && item.doses && item.doses.length > 0 && (
                <p className="text-xs text-muted-foreground tabular-nums">
                    Horários: {item.doses.join(' · ')}
                </p>
            )}
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
                                {data.canSuspend && !item.suspendedAt && (
                                    <SuspendForm item={item} />
                                )}
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

function SuspendForm({ item }: { item: PrescriptionItemView }) {
    const id = `suspend-${item.id}`;

    return (
        <details>
            <summary
                aria-label={`Suspender ${item.medication}`}
                className="inline-flex min-h-11 cursor-pointer items-center text-sm text-muted-foreground"
            >
                Suspender
            </summary>
            <Form
                {...PrescriptionController.suspendItem.form(item.id)}
                options={{ preserveScroll: true }}
                errorBag={`suspend${item.id}`}
                className="flex flex-wrap items-end gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid content-start gap-2">
                            <Label htmlFor={id}>Motivo da suspensão</Label>
                            <Input
                                id={id}
                                name="reason"
                                required
                                maxLength={255}
                                className="h-11"
                            />
                            <InputError message={errors.reason} />
                        </div>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Confirmar suspensão
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}
