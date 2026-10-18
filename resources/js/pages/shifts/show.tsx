import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Send } from 'lucide-react';
import AttendanceOccurrenceController from '@/actions/App/Http/Controllers/AttendanceOccurrenceController';
import ShiftController from '@/actions/App/Http/Controllers/ShiftController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, index } from '@/routes/shifts';
import type { ShiftSummary } from '@/components/shift-display';
import {
    formatDay,
    formatMoney,
    PeriodLabel,
    StatusBadge,
    timeRange,
} from '@/components/shift-display';

type Shift = ShiftSummary & {
    unit: { id: number; name: string; timezone: string | null };
    cancelReason: string | null;
};

type Occurrence = {
    id: number;
    kind: 'check_in' | 'check_out';
    kindLabel: string;
    description: string;
    status: 'open' | 'granted' | 'denied';
    statusLabel: string;
    reportedAt: string;
    informedAt: string | null;
    reason: string | null;
};

type Attendance = {
    id: number;
    kind: string;
    accepted: boolean;
    reason: string | null;
    at: string;
    point: string | null;
    distance: number | null;
    accuracy: number | null;
};

type Application = {
    id: number;
    professional: string;
    registration: string | null;
    status: 'active' | 'withdrawn' | 'selected' | 'not_selected' | 'replaced';
    statusLabel: string;
    appliedAt: string;
    reason: string | null;
    blocks: string[];
};

export default function ShiftShow({
    shift,
    canEdit,
    canPublish,
    canCancel,
    canSelect,
    applications,
    attendance,
    occurrences,
    canDecideOccurrences,
}: {
    shift: Shift;
    canEdit: boolean;
    canPublish: boolean;
    canCancel: boolean;
    canSelect: boolean;
    applications: Application[];
    attendance: Attendance[];
    occurrences: Occurrence[];
    canDecideOccurrences: boolean;
}) {
    return (
        <>
            <Head title="Plantão" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={shift.unit.name}
                    title={`Plantão de ${formatDay(shift.date)}`}
                    actions={
                        canEdit && (
                            <Button
                                variant="outline"
                                asChild
                                className="min-h-11"
                            >
                                <Link href={edit(shift.id)}>
                                    <Pencil aria-hidden="true" />
                                    Editar rascunho
                                </Link>
                            </Button>
                        )
                    }
                />

                <dl className="mb-8 divide-y rounded-lg border bg-card text-sm">
                    <Detail label="Situação">
                        <StatusBadge status={shift.status} />
                    </Detail>
                    <Detail label="Horário">{timeRange(shift)}</Detail>
                    <Detail label="Turno">
                        <PeriodLabel period={shift.period} />
                    </Detail>
                    <Detail label="Valor">
                        {shift.valueCents === null
                            ? 'Não informado'
                            : formatMoney(shift.valueCents)}
                    </Detail>
                    <Detail label="Fuso">
                        {shift.unit.timezone?.replaceAll('_', ' ')}
                    </Detail>
                    <Detail label="Setor">{shift.sector}</Detail>
                    <Detail label="Função">{shift.function}</Detail>
                    <Detail label="Profissional">
                        {shift.professional ?? 'Vaga aberta'}
                        {shift.announced && (
                            <span className="block font-normal text-primary">
                                Anunciado para substituição
                            </span>
                        )}
                    </Detail>
                    {shift.cancelReason && (
                        <Detail label="Motivo do cancelamento">
                            {shift.cancelReason}
                        </Detail>
                    )}
                </dl>

                {attendance.length > 0 && (
                    <section aria-labelledby="attendance" className="mb-6">
                        <h2
                            id="attendance"
                            className="mb-3 text-base font-semibold"
                        >
                            Presença
                        </h2>
                        <ul className="divide-y rounded-lg border bg-card text-sm">
                            {attendance.map((event) => (
                                <li
                                    key={event.id}
                                    className="grid gap-1 px-4 py-3"
                                >
                                    <p className="flex flex-wrap justify-between gap-2">
                                        <span className="font-medium">
                                            {event.kind}{' '}
                                            {event.accepted
                                                ? 'registrada'
                                                : 'recusada'}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {event.at}
                                        </span>
                                    </p>
                                    {event.accepted ? (
                                        <p className="text-muted-foreground">
                                            {event.point} · {event.distance} m
                                            do ponto · precisão {event.accuracy}{' '}
                                            m
                                        </p>
                                    ) : (
                                        <p className="text-destructive">
                                            {event.reason}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {occurrences.length > 0 && (
                    <section aria-labelledby="occurrences" className="mb-6">
                        <h2
                            id="occurrences"
                            className="mb-3 text-base font-semibold"
                        >
                            Ocorrências de presença
                        </h2>
                        <ul className="grid gap-3">
                            {occurrences.map((occurrence) => (
                                <li
                                    key={occurrence.id}
                                    className="grid gap-2 rounded-lg border bg-card p-4 text-sm"
                                >
                                    <p className="flex flex-wrap justify-between gap-2">
                                        <span className="font-medium">
                                            {occurrence.kindLabel} ·{' '}
                                            {occurrence.statusLabel}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {occurrence.reportedAt}
                                        </span>
                                    </p>
                                    <p>{occurrence.description}</p>
                                    {occurrence.informedAt && (
                                        <p>
                                            Saída ajustada para{' '}
                                            {occurrence.informedAt} (sem
                                            registro por localização)
                                        </p>
                                    )}
                                    {occurrence.reason && (
                                        <p className="text-muted-foreground">
                                            Justificativa: {occurrence.reason}
                                        </p>
                                    )}
                                    {canDecideOccurrences &&
                                        occurrence.status === 'open' && (
                                            <OccurrenceDecision
                                                occurrence={occurrence}
                                            />
                                        )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {(canSelect || applications.length > 0) && (
                    <section aria-labelledby="candidates" className="mb-6">
                        <h2
                            id="candidates"
                            className="mb-1 text-base font-semibold"
                        >
                            Candidatos
                        </h2>
                        <p className="mb-3 text-sm text-muted-foreground">
                            Escolha um candidato e registre a justificativa. A
                            elegibilidade e os conflitos são verificados de novo
                            na escolha; os demais candidatos são encerrados.
                        </p>
                        {applications.length === 0 ? (
                            <p className="rounded-lg border bg-card px-4 py-6 text-center text-sm">
                                Nenhuma candidatura ainda.
                            </p>
                        ) : (
                            <ul className="grid gap-3">
                                {applications.map((application) => (
                                    <li
                                        key={application.id}
                                        className="grid gap-2 rounded-lg border bg-card p-4 text-sm"
                                    >
                                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                                            <p className="font-medium">
                                                {application.professional}
                                                {application.registration && (
                                                    <span className="ml-2 font-normal text-muted-foreground">
                                                        {
                                                            application.registration
                                                        }
                                                    </span>
                                                )}
                                            </p>
                                            <span className="text-muted-foreground">
                                                {application.statusLabel} ·{' '}
                                                {application.appliedAt}
                                            </span>
                                        </div>
                                        {application.reason && (
                                            <p>
                                                Justificativa:{' '}
                                                {application.reason}
                                            </p>
                                        )}
                                        {application.blocks.length > 0 && (
                                            <ul className="list-inside list-disc text-destructive">
                                                {application.blocks.map(
                                                    (block) => (
                                                        <li key={block}>
                                                            {block}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        )}
                                        {canSelect &&
                                            application.status === 'active' && (
                                                <SelectForm
                                                    application={application}
                                                />
                                            )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {canPublish && (
                    <Form
                        {...ShiftController.publish.form(shift.id)}
                        options={{ preserveScroll: true }}
                        className="mb-6 grid gap-3 rounded-lg border bg-card p-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Ao publicar, elegibilidade e conflitos são
                                    verificados de novo. Depois de publicado, o
                                    plantão só pode ser cancelado.
                                </p>
                                <InputError
                                    message={
                                        errors.professional_id ?? errors.shift
                                    }
                                />
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11 justify-self-start"
                                >
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <Send aria-hidden="true" />
                                    )}
                                    Publicar plantão
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                {canCancel && (
                    <Form
                        {...ShiftController.cancel.form(shift.id)}
                        options={{ preserveScroll: true }}
                        className="grid gap-3 rounded-lg border bg-card p-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Label htmlFor="reason">
                                    Motivo do cancelamento
                                </Label>
                                <Input
                                    id="reason"
                                    name="reason"
                                    required
                                    maxLength={1000}
                                    aria-invalid={!!errors.reason}
                                    aria-describedby={
                                        errors.reason
                                            ? 'reason-error'
                                            : undefined
                                    }
                                    className="h-11"
                                />
                                <InputError
                                    id="reason-error"
                                    message={errors.reason}
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    className="min-h-11 justify-self-start"
                                >
                                    {processing && <Spinner />}
                                    Cancelar plantão
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </Page>
        </>
    );
}

/** Grant or deny with a reason; a granted exit needs the informed time (unit's timezone). */
function OccurrenceDecision({ occurrence }: { occurrence: Occurrence }) {
    const field = `decision-${occurrence.id}`;

    return (
        <Form
            {...AttendanceOccurrenceController.decide.form(occurrence.id)}
            options={{ preserveScroll: true }}
            className="grid gap-2 border-t pt-3"
        >
            {({ processing, errors }) => (
                <>
                    {occurrence.kind === 'check_out' && (
                        <>
                            <Label htmlFor={`${field}-time`}>
                                Horário de saída informado (para deferir)
                            </Label>
                            <Input
                                id={`${field}-time`}
                                name="informed_at"
                                type="datetime-local"
                                className="h-11"
                            />
                            <InputError message={errors.informed_at} />
                        </>
                    )}
                    <Label htmlFor={`${field}-reason`}>Justificativa</Label>
                    <Input
                        id={`${field}-reason`}
                        name="reason"
                        required
                        maxLength={1000}
                        className="h-11"
                    />
                    <InputError message={errors.reason ?? errors.occurrence} />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            name="grant"
                            value="1"
                            disabled={processing}
                            className="min-h-11"
                        >
                            {processing && <Spinner />}
                            Deferir
                        </Button>
                        <Button
                            type="submit"
                            name="grant"
                            value="0"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Indeferir
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function SelectForm({ application }: { application: Application }) {
    const field = `reason-${application.id}`;

    return (
        <Form
            {...ShiftController.select.form(application.id)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-2 border-t pt-3"
        >
            {({ processing, errors }) => {
                const error =
                    errors.reason ??
                    errors.application ??
                    errors.professional_id ??
                    errors.shift ??
                    errors.starts_at;

                return (
                    <>
                        <Label htmlFor={field}>Justificativa da escolha</Label>
                        <Input
                            id={field}
                            name="reason"
                            required
                            maxLength={1000}
                            aria-invalid={!!error}
                            aria-describedby={
                                error ? `${field}-error` : undefined
                            }
                            className="h-11"
                        />
                        <InputError id={`${field}-error`} message={error} />
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            {processing && <Spinner />}
                            Escolher {application.professional}
                        </Button>
                    </>
                );
            }}
        </Form>
    );
}

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-start justify-between gap-3 px-4 py-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium">{children}</dd>
        </div>
    );
}

ShiftShow.layout = {
    breadcrumbs: [{ title: 'Escala', href: index() }],
};
