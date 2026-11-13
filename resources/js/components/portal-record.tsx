import {
    AlertTriangle,
    CalendarClock,
    FileText,
    Paperclip,
} from 'lucide-react';
import PortalDocumentController from '@/actions/App/Http/Controllers/Portal/PortalDocumentController';
import PortalResultPdfController from '@/actions/App/Http/Controllers/Portal/PortalResultPdfController';

export type PortalResult = {
    id: number;
    exam: string;
    releasedAt: string;
    rectified: boolean;
    critical: boolean;
    report: string | null;
    hasPdf: boolean;
    values: {
        name: string;
        value: string | null;
        unit: string | null;
        reference: string | null;
        flag: 'low' | 'high' | null;
    }[];
};

export type PortalDocument = {
    id: number;
    name: string;
    attachedAt: string;
};

export type PortalAppointment = {
    id: number;
    startsAt: string;
    timezone: string;
    unit: string;
    professional: string;
};

const flagLabels = { low: 'Abaixo da referência', high: 'Acima da referência' };

function formatDate(iso: string, timezone?: string, withTime = false) {
    return new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'long',
        ...(withTime ? { timeStyle: 'short' } : {}),
        timeZone: timezone,
    }).format(new Date(iso));
}

/**
 * Step 7a.3: one patient's signed results and coming appointments, the same for the patient and for the
 * guardian of a linked minor.
 */
export default function PortalRecord({
    slug,
    results,
    appointments,
    documents,
}: {
    slug: string;
    results: PortalResult[];
    appointments: PortalAppointment[];
    documents: PortalDocument[];
}) {
    return (
        <div className="mt-8 grid gap-10">
            <section aria-labelledby="appointments-title">
                <h2
                    id="appointments-title"
                    className="flex items-center gap-2 text-lg font-medium"
                >
                    <CalendarClock
                        className="size-5 text-primary"
                        aria-hidden="true"
                    />
                    Próximas consultas
                </h2>
                {appointments.length === 0 ? (
                    <p className="mt-2 text-sm text-muted-foreground">
                        Nenhuma consulta marcada.
                    </p>
                ) : (
                    <ul className="mt-3 grid gap-2">
                        {appointments.map((appointment) => (
                            <li
                                key={appointment.id}
                                className="rounded-md border bg-card px-4 py-3 text-sm"
                            >
                                <p className="font-medium">
                                    {formatDate(
                                        appointment.startsAt,
                                        appointment.timezone,
                                        true,
                                    )}
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    {appointment.unit} ·{' '}
                                    {appointment.professional}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section aria-labelledby="results-title">
                <h2
                    id="results-title"
                    className="flex items-center gap-2 text-lg font-medium"
                >
                    <FileText
                        className="size-5 text-primary"
                        aria-hidden="true"
                    />
                    Resultados de exame
                </h2>
                {results.length === 0 ? (
                    <p className="mt-2 text-sm text-muted-foreground">
                        Nenhum resultado liberado ainda.
                    </p>
                ) : (
                    <ul className="mt-3 grid gap-3">
                        {results.map((result) => (
                            <li
                                key={result.id}
                                className="rounded-md border bg-card px-4 py-4 text-sm"
                            >
                                <h3 className="font-medium">{result.exam}</h3>
                                <p className="mt-1 text-muted-foreground">
                                    Liberado em {formatDate(result.releasedAt)}
                                    {result.rectified &&
                                        ' · corrigido pelo laboratório'}
                                </p>
                                {result.critical && (
                                    <p
                                        role="note"
                                        className="mt-3 flex gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2"
                                    >
                                        <AlertTriangle
                                            className="mt-0.5 size-4 shrink-0 text-destructive"
                                            aria-hidden="true"
                                        />
                                        <span>
                                            Este resultado tem um valor que pede
                                            atenção. Procure seu médico.
                                        </span>
                                    </p>
                                )}
                                {result.values.length > 0 && (
                                    <dl className="mt-3 grid gap-2">
                                        {result.values.map((value, index) => (
                                            <div
                                                key={index}
                                                className="grid gap-0.5 border-t pt-2 first:border-t-0 first:pt-0"
                                            >
                                                <dt className="text-muted-foreground">
                                                    {value.name}
                                                </dt>
                                                <dd className="font-medium tabular-nums">
                                                    {value.value ?? '—'}{' '}
                                                    {value.unit}
                                                    {value.flag && (
                                                        <span className="ml-2 font-normal text-destructive">
                                                            {
                                                                flagLabels[
                                                                    value.flag
                                                                ]
                                                            }
                                                        </span>
                                                    )}
                                                </dd>
                                                {value.reference && (
                                                    <dd className="text-xs text-muted-foreground">
                                                        Referência:{' '}
                                                        {value.reference}
                                                    </dd>
                                                )}
                                            </div>
                                        ))}
                                    </dl>
                                )}
                                {result.report && (
                                    <p className="mt-3 whitespace-pre-line">
                                        {result.report}
                                    </p>
                                )}
                                {result.hasPdf && (
                                    <a
                                        href={PortalResultPdfController.url([
                                            slug,
                                            result.id,
                                        ])}
                                        className="mt-3 inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                                    >
                                        Baixar o PDF do resultado
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section aria-labelledby="documents-title">
                <h2
                    id="documents-title"
                    className="flex items-center gap-2 text-lg font-medium"
                >
                    <Paperclip
                        className="size-5 text-primary"
                        aria-hidden="true"
                    />
                    Documentos
                </h2>
                {documents.length === 0 ? (
                    <p className="mt-2 text-sm text-muted-foreground">
                        Nenhum documento liberado pela equipe.
                    </p>
                ) : (
                    <ul className="mt-3 grid gap-2">
                        {documents.map((document) => (
                            <li key={document.id}>
                                <a
                                    href={PortalDocumentController.url([
                                        slug,
                                        document.id,
                                    ])}
                                    className="flex min-h-11 flex-wrap items-center justify-between gap-x-3 rounded-md border bg-card px-4 py-2 text-sm hover:bg-accent"
                                >
                                    <span className="font-medium break-all text-primary">
                                        {document.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {formatDate(document.attachedAt)}
                                    </span>
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
