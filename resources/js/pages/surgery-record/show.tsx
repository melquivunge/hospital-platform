import { Form, Head, Link } from '@inertiajs/react';
import SurgeryChecklistController from '@/actions/App/Http/Controllers/SurgeryChecklistController';
import SurgeryScheduleController from '@/actions/App/Http/Controllers/SurgeryScheduleController';
import type { ClinicalDocument } from '@/components/clinical-documents';
import { ClinicalDocuments } from '@/components/clinical-documents';
import type { RecordNote, RecordPatient } from '@/components/clinical-record';
import { patientDescription, RecordEntry } from '@/components/clinical-record';
import type { ExamResultItem } from '@/components/exam-results';
import { ExamResults } from '@/components/exam-results';
import { Page, PageHeader } from '@/components/page';
import type { PatientAllergy } from '@/components/patient-allergies';
import { PatientAllergies } from '@/components/patient-allergies';
import type { PrescriptionPanelData } from '@/components/prescription-panel';
import { PrescriptionPanel } from '@/components/prescription-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Steps = Record<
    string,
    { label: string; items: Record<string, [string, string[]]> }
>;

/** The consent term (step 5b.4): attached file, or the urgent waiver, before the first step. */
function Consent({ surgery }: { surgery: Surgery }) {
    const consent = surgery.consent;
    const editable = surgery.canConfirm && surgery.status !== 'in_progress';

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">Termo de consentimento</h2>
            {consent === null ? (
                <p className="text-sm text-muted-foreground">
                    Nenhum termo anexado.
                </p>
            ) : consent.kind === 'document' ? (
                <p className="text-sm">
                    <a
                        href={SurgeryChecklistController.downloadConsent.url(
                            consent.id,
                        )}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        {consent.name}
                    </a>{' '}
                    <span className="text-muted-foreground">
                        · anexado por {consent.by} em {consent.at}
                    </span>
                </p>
            ) : (
                <p className="text-sm">
                    Urgência sem termo: {consent.waiverReason}{' '}
                    <span className="text-muted-foreground">
                        · {consent.by} em {consent.at}
                    </span>
                </p>
            )}
            {editable && (
                <Form
                    {...SurgeryChecklistController.storeConsent.form(
                        surgery.id,
                    )}
                    errorBag="consent"
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="consent-file">
                                    {consent === null
                                        ? 'Termo assinado (PDF, JPG ou PNG, até 10 MB)'
                                        : 'Substituir por outro termo'}
                                </Label>
                                <Input
                                    id="consent-file"
                                    name="file"
                                    type="file"
                                    required
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    className="h-11"
                                />
                                <InputError message={errors.file} />
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Anexar
                            </Button>
                        </>
                    )}
                </Form>
            )}
            {editable && surgery.urgency === 'urgent' && consent === null && (
                <Form
                    {...SurgeryChecklistController.waiveConsent.form(
                        surgery.id,
                    )}
                    errorBag="waiver"
                    options={{ preserveScroll: true }}
                    className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="waiver-reason">
                                    Urgência sem termo: motivo
                                </Label>
                                <Input
                                    id="waiver-reason"
                                    name="reason"
                                    required
                                    maxLength={500}
                                    className="h-11"
                                />
                                <InputError message={errors.reason} />
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Registrar
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}

/** The WHO safe surgery checklist (step 5b.4): three steps in order, each confirmed once. */
function Checklist({
    surgery,
    steps,
    answers,
}: {
    surgery: Surgery;
    steps: Steps;
    answers: Record<string, string>;
}) {
    const keys = Object.keys(steps);
    const next = keys.find((key) => !surgery.checklist[key]);
    const startable =
        surgery.status === 'scheduled' || surgery.status === 'in_progress';

    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">Checklist de cirurgia segura</h2>
            {keys.map((key, index) => {
                const done = surgery.checklist[key];
                const open =
                    !done && key === next && surgery.canConfirm && startable;

                return (
                    <section
                        key={key}
                        aria-labelledby={`step-${key}`}
                        className="grid gap-2 border-t pt-4 first:border-t-0 first:pt-0"
                    >
                        <h3 id={`step-${key}`} className="font-medium">
                            {index + 1}. {steps[key].label}
                            {done && (
                                <span className="ml-2 text-sm font-normal text-muted-foreground">
                                    confirmada por {done.by} em {done.at}
                                </span>
                            )}
                        </h3>
                        {done ? (
                            <ul className="grid gap-1 text-sm">
                                {Object.entries(steps[key].items).map(
                                    ([item, [text]]) => (
                                        <li key={item}>
                                            {text} —{' '}
                                            <span className="font-medium">
                                                {answers[done.answers[item]]}
                                            </span>
                                        </li>
                                    ),
                                )}
                            </ul>
                        ) : open ? (
                            <Form
                                {...SurgeryChecklistController.confirm.form({
                                    surgery: surgery.id,
                                    step: key,
                                })}
                                errorBag={`step-${key}`}
                                options={{ preserveScroll: true }}
                                className="grid gap-3"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        {Object.entries(steps[key].items).map(
                                            ([item, [text, options]]) => (
                                                <fieldset
                                                    key={item}
                                                    className="grid gap-1"
                                                >
                                                    <legend className="text-sm">
                                                        {text}
                                                    </legend>
                                                    <div className="flex flex-wrap gap-4">
                                                        {options.map(
                                                            (option) => (
                                                                <label
                                                                    key={option}
                                                                    className="flex min-h-11 items-center gap-2 text-sm"
                                                                >
                                                                    <input
                                                                        type="radio"
                                                                        name={`answers[${item}]`}
                                                                        value={
                                                                            option
                                                                        }
                                                                        required
                                                                        className="size-4 shrink-0 accent-primary"
                                                                    />
                                                                    {options.length ===
                                                                        1 &&
                                                                    option ===
                                                                        'yes'
                                                                        ? 'Confirmado'
                                                                        : answers[
                                                                              option
                                                                          ]}
                                                                </label>
                                                            ),
                                                        )}
                                                    </div>
                                                    <InputError
                                                        message={
                                                            errors[
                                                                `answers.${item}`
                                                            ]
                                                        }
                                                    />
                                                </fieldset>
                                            ),
                                        )}
                                        <InputError message={errors.step} />
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="min-h-11"
                                            >
                                                Confirmar etapa
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {surgery.status === 'requested'
                                    ? 'A cirurgia ainda não foi agendada.'
                                    : 'Aguardando a etapa anterior.'}
                            </p>
                        )}
                    </section>
                );
            })}
        </section>
    );
}

type Surgery = {
    id: number;
    procedure: string;
    laterality: string;
    urgency: string;
    indication: string;
    status: 'requested' | 'scheduled' | 'in_progress';
    startedAt: string | null;
    consent: {
        id: number;
        kind: 'document' | 'waiver';
        name: string | null;
        waiverReason: string | null;
        by: string | null;
        at: string;
    } | null;
    checklist: Record<
        string,
        { answers: Record<string, string>; by: string | null; at: string }
    >;
    canConfirm: boolean;
    desiredDate: string;
    author: string | null;
    room: string | null;
    start: string | null;
    end: string | null;
    urgentReason: string | null;
    team: { id: number; name: string; role: string }[];
    place: string;
    canManage: boolean;
};

/**
 * The record for the surgery (step 5b.3b, ADR-0009): the surgical center of the unit and the scheduled team read the
 * patient's record while the surgery is open, with the screens of who attends; the opening is in the access trail.
 */
export default function SurgeryRecord({
    surgery,
    roles,
    steps,
    answers,
    lateralities,
    urgencies,
    patient,
    allergies,
    record,
    prescriptions,
    documents,
    examResults,
}: {
    surgery: Surgery;
    roles: Record<string, string>;
    steps: Steps;
    answers: Record<string, string>;
    lateralities: Record<string, string>;
    urgencies: Record<string, string>;
    patient: RecordPatient;
    allergies: PatientAllergy[];
    record: RecordNote[];
    prescriptions: PrescriptionPanelData;
    documents: ClinicalDocument[];
    examResults: ExamResultItem[];
}) {
    return (
        <>
            <Head title="Prontuário para a cirurgia" />
            <Page>
                <PageHeader
                    eyebrow="Prontuário para a cirurgia"
                    title={patient.name}
                    description={patientDescription(patient)}
                    actions={
                        surgery.canManage && (
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <Link
                                    href={SurgeryScheduleController.show.url(
                                        surgery.id,
                                    )}
                                >
                                    Agenda desta cirurgia
                                </Link>
                            </Button>
                        )
                    }
                />
                <PatientAllergies allergies={allergies} />

                <section className="mb-8 grid gap-2 rounded-xl border bg-card p-5 sm:p-6">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="font-semibold">
                            {surgery.procedure}
                            {surgery.laterality !== 'none' &&
                                ` · ${lateralities[surgery.laterality]}`}
                        </h2>
                        <span
                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${surgery.urgency === 'urgent' ? 'bg-destructive text-white' : 'bg-muted'}`}
                        >
                            {urgencies[surgery.urgency]}
                        </span>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {surgery.place} ·{' '}
                        {surgery.startedAt &&
                            `em andamento desde ${surgery.startedAt} · `}
                        {surgery.start
                            ? `${surgery.room}, ${surgery.start} – ${surgery.end}`
                            : `aguardando agendamento, desejada para ${surgery.desiredDate}`}{' '}
                        · pedida por {surgery.author}
                    </p>
                    <p className="text-sm">
                        <span className="font-medium">Indicação:</span>{' '}
                        {surgery.indication}
                    </p>
                    {surgery.urgentReason && (
                        <p className="text-sm">
                            <span className="font-medium">
                                Motivo da urgência:
                            </span>{' '}
                            {surgery.urgentReason}
                        </p>
                    )}
                    {surgery.team.length > 0 && (
                        <p className="text-sm">
                            <span className="font-medium">Equipe:</span>{' '}
                            {surgery.team
                                .map(
                                    (member) =>
                                        `${member.name} (${roles[member.role]})`,
                                )
                                .join(', ')}
                        </p>
                    )}
                </section>

                <div className="grid gap-8">
                    <Consent surgery={surgery} />
                    <Checklist
                        surgery={surgery}
                        steps={steps}
                        answers={answers}
                    />
                    <PrescriptionPanel data={prescriptions} />
                    <ExamResults results={examResults} />
                    <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
                        <h2 className="font-semibold">Evoluções assinadas</h2>
                        {record.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhuma evolução assinada.
                            </p>
                        ) : (
                            <ol className="grid gap-4">
                                {record.map((note) => (
                                    <RecordEntry key={note.id} note={note} />
                                ))}
                            </ol>
                        )}
                    </section>
                    <ClinicalDocuments documents={documents} />
                </div>
            </Page>
        </>
    );
}
