import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import MedicationAdministrationController from '@/actions/App/Http/Controllers/MedicationAdministrationController';
import { selectClassName } from '@/components/role-fields';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Pill } from 'lucide-react';
import PrescriptionController from '@/actions/App/Http/Controllers/PrescriptionController';
import PrescriptionSignatureController from '@/actions/App/Http/Controllers/PrescriptionSignatureController';
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
    prn: boolean;
    doubleCheck: boolean;
    doses?: DoseView[];
    given?: DoseRecord[];
    review: { decision: string; label: string; note: string | null } | null;
    dispensing: {
        id: number;
        quantity: number;
        at: string;
        returnedQuantity: number | null;
        returnedAt: string | null;
        returnReason: string | null;
    } | null;
};

/** Step 4b.4: what the ward team recorded for a dose. */
export type DoseRecord = {
    outcome: 'administered' | 'refused' | 'not_done';
    label: string;
    at: string;
    by: string | null;
    witness: string | null;
    reason: string | null;
    exception: boolean;
};

export type DoseView = {
    id: number;
    at: string;
    late: boolean;
    due: boolean;
    record: DoseRecord | null;
};

export type PrescriptionSummary = {
    id: number;
    signedAt: string | null;
    validUntil: string | null;
    author: string | null;
    certificate: {
        label: string;
        qualified: boolean;
        holder: string;
        at: string;
    } | null;
    canCertify: boolean;
    items: PrescriptionItemView[];
};

export type PrescriptionPanelData = {
    active: PrescriptionSummary | null;
    previous: PrescriptionSummary[];
    draftId: number | null;
    canPrescribe: boolean;
    canSuspend: boolean;
    administration: {
        witnesses: { id: number; name: string }[];
        now: string;
    } | null;
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
            {item.review && (
                <p
                    className={`text-xs font-medium ${item.review.decision === 'refused' ? 'text-destructive' : 'text-primary'}`}
                >
                    Farmácia: {item.review.label}
                    {item.review.note && ` · ${item.review.note}`}
                </p>
            )}
            {item.dispensing && (
                <p className="text-xs text-muted-foreground tabular-nums">
                    Dispensado: {item.dispensing.quantity} em{' '}
                    {item.dispensing.at}
                    {item.dispensing.returnedQuantity !== null &&
                        ` · Devolvido: ${item.dispensing.returnedQuantity} em ${item.dispensing.returnedAt} (${item.dispensing.returnReason})`}
                </p>
            )}
            {item.suspendedAt && (
                <p className="text-xs font-medium">
                    Suspenso em {item.suspendedAt}: {item.suspensionReason}
                </p>
            )}
            <DoseList item={item} />
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
    /** Absent where nobody prescribes, as on the exam execution (step 5.3). */
    start?: { action: string; method: 'post' };
}) {
    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 font-semibold">
                    <Pill className="size-4 text-primary" aria-hidden="true" />
                    Prescrição
                </h2>
                {data.canPrescribe &&
                    start &&
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
                    <SignatureLine prescription={data.active} />
                    <ol className="grid gap-3">
                        {data.active.items.map((item) => (
                            <li
                                key={item.id}
                                className="rounded-lg border px-4 py-3"
                            >
                                <PrescriptionItemLine item={item} />
                                {data.administration &&
                                    !item.suspendedAt &&
                                    item.review?.decision !== 'refused' && (
                                        <AdministrationForms
                                            item={item}
                                            administration={data.administration}
                                        />
                                    )}
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
                                <SignatureLine prescription={prescription} />
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

/** Step 4b.5: who signed and how; the digital signature's files, or the author's button to sign with the certificate. */
function SignatureLine({
    prescription,
}: {
    prescription: PrescriptionSummary;
}) {
    const { certificate } = prescription;
    const file = (part: 'pdf' | 'p7s') =>
        PrescriptionSignatureController.download.url({
            prescription: prescription.id,
            part,
        });

    return (
        <div className="grid gap-1 text-xs text-muted-foreground">
            <p>
                Assinada em {prescription.signedAt} por {prescription.author} ·
                válida até {prescription.validUntil}
            </p>
            {certificate ? (
                <p className="flex flex-wrap items-center gap-x-3">
                    <span>
                        Assinatura digital com {certificate.label}, titular{' '}
                        {certificate.holder}, em {certificate.at}
                    </span>
                    <a
                        href={file('pdf')}
                        className="inline-flex min-h-11 items-center underline"
                    >
                        Documento assinado (PDF)
                    </a>
                    <a
                        href={file('p7s')}
                        className="inline-flex min-h-11 items-center underline"
                    >
                        Assinatura (.p7s)
                    </a>
                </p>
            ) : (
                <p>Assinatura eletrônica simples (senha)</p>
            )}
            {prescription.canCertify && (
                <Form
                    {...PrescriptionSignatureController.store.form(
                        prescription.id,
                    )}
                    options={{ preserveScroll: true }}
                    className="grid justify-items-start gap-1"
                >
                    {({ processing, errors }) => (
                        <>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Assinar com certificado digital
                            </Button>
                            <InputError message={errors.certificate} />
                        </>
                    )}
                </Form>
            )}
        </div>
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

function recordText(record: DoseRecord): string {
    return [
        `${record.label} ${record.at}`,
        record.by && `por ${record.by}`,
        record.witness && `com ${record.witness}`,
        record.exception && 'sem dispensação',
        record.reason && `(${record.reason})`,
    ]
        .filter(Boolean)
        .join(' ');
}

/** Step 4b.4: the times of an item with what was recorded, the late ones marked; a suspended item keeps only its records. */
function DoseList({ item }: { item: PrescriptionItemView }) {
    const doses = (item.doses ?? []).filter(
        (dose) => !item.suspendedAt || dose.record,
    );
    const given = item.given ?? [];
    if (doses.length === 0 && given.length === 0) {
        return null;
    }

    return (
        <ul className="grid gap-0.5 text-xs tabular-nums">
            {doses.map((dose) => (
                <li
                    key={dose.id}
                    className={
                        dose.late
                            ? 'font-medium text-destructive'
                            : 'text-muted-foreground'
                    }
                >
                    {dose.at}:{' '}
                    {dose.record
                        ? recordText(dose.record)
                        : dose.late
                          ? 'atrasado'
                          : 'previsto'}
                </li>
            ))}
            {given.map((record, index) => (
                <li key={index} className="text-muted-foreground">
                    Se necessário: {recordText(record)}
                </li>
            ))}
        </ul>
    );
}

function AdministrationForms({
    item,
    administration,
}: {
    item: PrescriptionItemView;
    administration: NonNullable<PrescriptionPanelData['administration']>;
}) {
    const due = (item.doses ?? []).filter((dose) => dose.due);

    return (
        <div className="mt-2 grid gap-1">
            {due.map((dose) => (
                <AdministerForm
                    key={dose.id}
                    item={item}
                    administration={administration}
                    dose={dose}
                />
            ))}
            {item.prn && (
                <AdministerForm item={item} administration={administration} />
            )}
        </div>
    );
}

function AdministerForm({
    item,
    administration,
    dose,
}: {
    item: PrescriptionItemView;
    administration: NonNullable<PrescriptionPanelData['administration']>;
    dose?: DoseView;
}) {
    const [outcome, setOutcome] = useState('administered');
    const key = dose ? `dose-${dose.id}` : `prn-${item.id}`;
    const administered = outcome === 'administered';
    const needsReason = !administered || !item.dispensing;

    return (
        <details className="rounded-md border px-3">
            <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                {dose
                    ? `Registrar o horário ${dose.at}`
                    : 'Administrar uma dose (se necessário)'}
            </summary>
            <Form
                {...(dose
                    ? MedicationAdministrationController.dose.form(dose.id)
                    : MedicationAdministrationController.prn.form(item.id))}
                options={{ preserveScroll: true }}
                errorBag={dose ? `dose${dose.id}` : `prn${item.id}`}
                className="grid gap-3 pb-3"
            >
                {({ processing, errors }) => (
                    <>
                        {dose && (
                            <fieldset className="grid gap-1">
                                <legend className="mb-1 text-sm">
                                    Registro
                                </legend>
                                <div className="flex flex-wrap gap-x-4">
                                    {Object.entries(OUTCOMES).map(
                                        ([value, label]) => (
                                            <label
                                                key={value}
                                                className="flex min-h-11 items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="radio"
                                                    name="outcome"
                                                    value={value}
                                                    checked={outcome === value}
                                                    onChange={() =>
                                                        setOutcome(value)
                                                    }
                                                />
                                                {label}
                                            </label>
                                        ),
                                    )}
                                </div>
                                <InputError message={errors.outcome} />
                            </fieldset>
                        )}
                        {administered && (
                            <div className="grid max-w-xs gap-2">
                                <Label htmlFor={`${key}-at`}>
                                    Horário da administração
                                </Label>
                                <Input
                                    id={`${key}-at`}
                                    name="occurred_at"
                                    type="datetime-local"
                                    defaultValue={administration.now}
                                    max={administration.now}
                                    required
                                    className="h-11"
                                />
                                <InputError message={errors.occurred_at} />
                            </div>
                        )}
                        {needsReason && (
                            <div className="grid gap-2">
                                <Label htmlFor={`${key}-reason`}>
                                    {administered
                                        ? 'Motivo da exceção (item não dispensado pela farmácia)'
                                        : 'Motivo'}
                                </Label>
                                <Input
                                    id={`${key}-reason`}
                                    name="reason"
                                    required
                                    maxLength={255}
                                    className="h-11"
                                />
                                <InputError message={errors.reason} />
                            </div>
                        )}
                        {administered && item.doubleCheck && (
                            <div className="grid gap-3 rounded-md bg-muted p-3">
                                <p className="text-sm font-medium">
                                    Dupla checagem: controlado ou alto alerta
                                </p>
                                <div className="grid gap-2">
                                    <Label htmlFor={`${key}-witness`}>
                                        Segunda pessoa
                                    </Label>
                                    <select
                                        id={`${key}-witness`}
                                        name="witness_user_id"
                                        required
                                        defaultValue=""
                                        className={selectClassName}
                                    >
                                        <option value="" disabled>
                                            Escolha
                                        </option>
                                        {administration.witnesses.map(
                                            (witness) => (
                                                <option
                                                    key={witness.id}
                                                    value={witness.id}
                                                >
                                                    {witness.name}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    <InputError
                                        message={errors.witness_user_id}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor={`${key}-password`}>
                                        Senha da segunda pessoa
                                    </Label>
                                    <Input
                                        id={`${key}-password`}
                                        name="witness_password"
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                        className="h-11"
                                    />
                                    <InputError
                                        message={errors.witness_password}
                                    />
                                </div>
                            </div>
                        )}
                        <div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Registrar
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </details>
    );
}

const OUTCOMES = {
    administered: 'Administrado',
    refused: 'Recusado pelo paciente',
    not_done: 'Não realizado',
};
