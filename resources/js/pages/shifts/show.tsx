import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Send } from 'lucide-react';
import ShiftController from '@/actions/App/Http/Controllers/ShiftController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, index } from '@/routes/shifts';
import type { ShiftSummary } from '@/pages/shifts/index';
import {
    formatDay,
    formatMoney,
    PeriodLabel,
    StatusBadge,
    timeRange,
} from '@/pages/shifts/index';

type Shift = ShiftSummary & {
    unit: { id: number; name: string; timezone: string | null };
    cancelReason: string | null;
};

export default function ShiftShow({
    shift,
    canEdit,
    canPublish,
    canCancel,
}: {
    shift: Shift;
    canEdit: boolean;
    canPublish: boolean;
    canCancel: boolean;
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
                    </Detail>
                    {shift.cancelReason && (
                        <Detail label="Motivo do cancelamento">
                            {shift.cancelReason}
                        </Detail>
                    )}
                </dl>

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
