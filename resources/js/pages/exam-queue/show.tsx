import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ExamQueueController from '@/actions/App/Http/Controllers/ExamQueueController';
import ExamResultController from '@/actions/App/Http/Controllers/ExamResultController';
import type { ClinicalDocument } from '@/components/clinical-documents';
import { ClinicalDocuments } from '@/components/clinical-documents';
import type { RecordNote, RecordPatient } from '@/components/clinical-record';
import { patientDescription, RecordEntry } from '@/components/clinical-record';
import type {
    ExamResultItem,
    ExamResultVersion,
} from '@/components/exam-results';
import { ExamResults, ResultVersions } from '@/components/exam-results';
import { SurgeryDescriptions } from '@/components/surgery-descriptions';
import type { SurgeryDescriptionData } from '@/components/surgery-descriptions';
import InputError from '@/components/input-error';
import type { PrescriptionPanelData } from '@/components/prescription-panel';
import { PrescriptionPanel } from '@/components/prescription-panel';
import { Page, PageHeader } from '@/components/page';
import type { PatientAllergy } from '@/components/patient-allergies';
import { PatientAllergies } from '@/components/patient-allergies';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/exam-queue';

type ExecutionItem = {
    id: number;
    name: string;
    type: string;
    collectedAt: string | null;
    collectedBy: string | null;
    results: ExamResultVersion[];
    nextVersion: number;
    canSign: boolean;
    analytes: { id: number; name: string; unit: string | null }[];
};

/**
 * The execution of an exam order (step 5.3, ADR-0008): the items of the user's types, each collected or
 * performed once and then released with its result (step 5.4), and the patient's record, read-only while an
 * item waits for its result. After that only the order and the results stay, to rectify. This opening was logged.
 */
export default function ExamExecution({
    order,
    items,
    types,
    priorities,
    patient,
    open,
    allergies,
    record,
    prescriptions,
    documents,
    examResults,
    surgeryDescriptions,
}: {
    order: {
        id: number;
        priority: string;
        indication: string;
        author: string | null;
        createdAt: string;
        place: string;
        unitId: number;
    };
    items: ExecutionItem[];
    types: Record<string, string>;
    priorities: Record<string, string>;
    patient: RecordPatient;
    allergies: PatientAllergy[];
    record: RecordNote[];
    open: boolean;
    prescriptions: PrescriptionPanelData | null;
    documents: ClinicalDocument[];
    examResults: ExamResultItem[];
    surgeryDescriptions: SurgeryDescriptionData[];
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Execução de exame" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={`${order.place} · pedido em ${order.createdAt}`}
                    title={patient.name}
                    description={patientDescription(patient)}
                    actions={
                        <Button asChild variant="outline" className="min-h-11">
                            <Link
                                href={index({ query: { unit: order.unitId } })}
                            >
                                Voltar à fila
                            </Link>
                        </Button>
                    }
                />
                {open && <PatientAllergies allergies={allergies} />}

                <section className="mb-8 grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="font-semibold">Pedido</h2>
                        <span
                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${order.priority === 'urgent' ? 'bg-destructive text-white' : 'bg-muted'}`}
                        >
                            {priorities[order.priority]}
                        </span>
                    </div>
                    <p className="text-sm">
                        <span className="text-muted-foreground">
                            Indicação clínica ({order.author}):
                        </span>{' '}
                        {order.indication}
                    </p>
                    <InputError message={errors.collect} />
                    <ul className="divide-y rounded-lg border">
                        {items.map((item) => (
                            <li key={item.id} className="grid gap-3 px-4 py-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {item.name}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {types[item.type]}
                                            {item.collectedAt &&
                                                ` · ${item.type === 'imaging' ? 'realizado' : 'coletado'} em ${item.collectedAt} por ${item.collectedBy}`}
                                        </span>
                                    </span>
                                    {item.collectedAt === null && (
                                        <Form
                                            {...ExamQueueController.collect.form(
                                                item.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="min-h-11"
                                                >
                                                    {item.type === 'imaging'
                                                        ? 'Registrar realização'
                                                        : 'Registrar coleta'}
                                                </Button>
                                            )}
                                        </Form>
                                    )}
                                </div>
                                {item.results.length > 0 && (
                                    <ResultVersions versions={item.results} />
                                )}
                                {item.collectedAt !== null &&
                                    (item.canSign ? (
                                        <ResultForm
                                            key={item.nextVersion}
                                            item={item}
                                        />
                                    ) : (
                                        item.results.length === 0 &&
                                        item.type === 'imaging' && (
                                            <p className="text-sm text-muted-foreground">
                                                O laudo de imagem é liberado por
                                                um médico com CRM.
                                            </p>
                                        )
                                    ))}
                            </li>
                        ))}
                    </ul>
                </section>

                {open ? (
                    <div className="grid gap-8">
                        {prescriptions && (
                            <PrescriptionPanel data={prescriptions} />
                        )}
                        <ExamResults results={examResults} />
                        <SurgeryDescriptions
                            descriptions={surgeryDescriptions}
                        />
                        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
                            <h2 className="font-semibold">
                                Evoluções assinadas
                            </h2>
                            {record.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nenhuma evolução assinada.
                                </p>
                            ) : (
                                <ol className="grid gap-4">
                                    {record.map((note) => (
                                        <RecordEntry
                                            key={note.id}
                                            note={note}
                                        />
                                    ))}
                                </ol>
                            )}
                        </section>
                        <ClinicalDocuments documents={documents} />
                    </div>
                ) : (
                    <p className="rounded-xl border bg-card px-5 py-6 text-sm text-muted-foreground">
                        Resultados liberados: o prontuário do paciente não fica
                        mais aberto para a equipe de exames. Esta tela mostra só
                        o pedido e os resultados, para retificação.
                    </p>
                )}
            </Page>
        </>
    );
}

/**
 * Release (version 1) or rectify (the next version, with a reason) one item's result, signed with the password.
 * A rectification starts closed so the current result is read first; keyed by the version, it starts over after
 * each release.
 */
function ResultForm({ item }: { item: ExecutionItem }) {
    const rectifying = item.nextVersion > 1;
    // A rectification is a whole new version: it starts from the current one.
    const current = item.results[0];
    const [shown, setShown] = useState(!rectifying);
    const field = (name: string) => `${name}-${item.id}`;

    if (!shown) {
        return (
            <div>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={() => setShown(true)}
                >
                    Retificar resultado
                </Button>
            </div>
        );
    }

    return (
        <Form
            {...ExamResultController.release.form(item.id)}
            errorBag={`result${item.id}`}
            options={{ preserveScroll: true }}
            resetOnSuccess
            resetOnError={['password']}
            className="grid gap-4 rounded-lg border bg-muted/30 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="version"
                        value={item.nextVersion}
                    />
                    <h3 className="text-sm font-semibold">
                        {rectifying
                            ? `Retificação (versão ${item.nextVersion})`
                            : item.type === 'imaging'
                              ? 'Laudo'
                              : 'Resultado'}
                    </h3>
                    {rectifying && (
                        <div className="grid gap-2">
                            <Label htmlFor={field('reason')}>
                                Motivo da retificação
                            </Label>
                            <Input
                                id={field('reason')}
                                name="reason"
                                required
                                maxLength={500}
                                className="h-11"
                            />
                            <InputError message={errors.reason} />
                        </div>
                    )}
                    {item.analytes.length > 0 && (
                        <fieldset className="grid gap-3 sm:grid-cols-2">
                            <legend className="mb-2 text-sm font-medium">
                                Valores
                            </legend>
                            {item.analytes.map((analyte) => (
                                <div key={analyte.id} className="grid gap-1">
                                    <Label htmlFor={field(`a${analyte.id}`)}>
                                        {analyte.name}
                                        {analyte.unit && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                ({analyte.unit})
                                            </span>
                                        )}
                                    </Label>
                                    <Input
                                        id={field(`a${analyte.id}`)}
                                        name={`values[${analyte.id}]`}
                                        defaultValue={
                                            current?.values.find(
                                                (value) =>
                                                    value.analyteId ===
                                                    analyte.id,
                                            )?.value ?? ''
                                        }
                                        maxLength={100}
                                        inputMode="text"
                                        className="h-11"
                                    />
                                </div>
                            ))}
                        </fieldset>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor={field('report')}>
                            {item.type === 'imaging' ? 'Laudo' : 'Observações'}
                        </Label>
                        <textarea
                            id={field('report')}
                            name="report"
                            defaultValue={current?.report ?? ''}
                            maxLength={10000}
                            rows={item.type === 'imaging' ? 6 : 3}
                            className="rounded-md border bg-background px-3 py-2 text-sm"
                        />
                        <InputError message={errors.report} />
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor={field('file')}>
                                {current?.attachment
                                    ? 'Novo PDF (substitui o atual)'
                                    : 'PDF (opcional, até 10 MB)'}
                            </Label>
                            <Input
                                id={field('file')}
                                name="file"
                                type="file"
                                accept="application/pdf,.pdf"
                                className="h-11"
                            />
                            <InputError message={errors.file} />
                            {current?.attachment && (
                                <label className="flex min-h-11 items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="remove_attachment"
                                        value="1"
                                        className="size-4"
                                    />
                                    Remover o PDF atual (
                                    {current.attachment.name})
                                </label>
                            )}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={field('password')}>
                                Sua senha (assinatura)
                            </Label>
                            <Input
                                id={field('password')}
                                name="password"
                                type="password"
                                required
                                autoComplete="current-password"
                                className="h-11"
                            />
                            <InputError message={errors.password} />
                        </div>
                    </div>
                    <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            name="critical"
                            value="1"
                            defaultChecked={current?.critical ?? false}
                            className="size-4"
                        />
                        Resultado crítico: avisa quem pediu com destaque e
                        espera a ciência
                    </label>
                    <InputError
                        message={
                            errors.version ??
                            Object.entries(errors).find(([key]) =>
                                key.startsWith('values.'),
                            )?.[1]
                        }
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11"
                        >
                            {rectifying
                                ? 'Assinar e retificar'
                                : 'Assinar e liberar'}
                        </Button>
                        {rectifying && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="min-h-11"
                                onClick={() => setShown(false)}
                            >
                                Cancelar
                            </Button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}
