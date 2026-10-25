import { Form, Head, Link } from '@inertiajs/react';
import SurgeryChecklistController from '@/actions/App/Http/Controllers/SurgeryChecklistController';
import SurgeryDescriptionController from '@/actions/App/Http/Controllers/SurgeryDescriptionController';
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
import type { SurgeryDescriptionData } from '@/components/surgery-descriptions';
import {
    SurgeryDescriptionEntry,
    SurgeryDescriptions,
} from '@/components/surgery-descriptions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Steps = Record<
    string,
    { label: string; items: Record<string, [string, string[]]> }
>;

/** The consent term (step 5b.4): attached file, or the urgent waiver, before the first step. */
function Consent({ surgery, open }: { surgery: Surgery; open: boolean }) {
    const consent = surgery.consent;
    const editable =
        surgery.canConfirm &&
        (surgery.status === 'requested' || surgery.status === 'scheduled');

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">Termo de consentimento</h2>
            {consent === null ? (
                <p className="text-sm text-muted-foreground">
                    Nenhum termo anexado.
                </p>
            ) : consent.kind === 'document' && !open ? (
                <p className="text-sm">
                    {consent.name}{' '}
                    <span className="text-muted-foreground">
                        · anexado por {consent.by} em {consent.at}
                    </span>
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

/** The password field that signs the description and the addendum. */
function SignPassword({ id, error }: { id: string; error?: string }) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Sua senha (assinatura)</Label>
            <Input
                id={id}
                name="password"
                type="password"
                required
                autoComplete="current-password"
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className="h-11"
            />
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

const descriptionFields = [
    ['findings', 'Achados', true, 5000],
    ['technique', 'Técnica', true, 5000],
    ['complications', 'Intercorrências', true, 5000],
    ['specimens', 'Peças enviadas (opcional)', false, 1000],
] as const;

/**
 * The surgical description (step 5b.5): signed with the password by a surgeon of the team after the three checklist
 * steps; it completes the surgery. Addenda come after, the original stays.
 */
function Description({ surgery }: { surgery: Surgery }) {
    const description = surgery.description;

    if (description === null && !surgery.canDescribe) {
        return (
            <section className="grid gap-2 rounded-xl border bg-card p-5 sm:p-6">
                <h2 className="font-semibold">Descrição cirúrgica</h2>
                <p className="text-sm text-muted-foreground">
                    {surgery.status === 'in_progress' &&
                    surgery.checklist.sign_out
                        ? 'Aguardando a descrição assinada por um cirurgião da equipe.'
                        : 'A descrição é assinada por um cirurgião da equipe depois das três etapas do checklist.'}
                </p>
            </section>
        );
    }

    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">Descrição cirúrgica</h2>
            {description !== null ? (
                <ul className="grid">
                    <SurgeryDescriptionEntry description={description}>
                        {surgery.canAddendum && (
                            <Form
                                {...SurgeryDescriptionController.addendum.form(
                                    surgery.id,
                                )}
                                errorBag="addendum"
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                resetOnError={['password']}
                                className="grid gap-3 border-t pt-3"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="addendum-body">
                                                Adendo
                                            </Label>
                                            <textarea
                                                id="addendum-body"
                                                name="body"
                                                required
                                                maxLength={5000}
                                                rows={3}
                                                aria-invalid={!!errors.body}
                                                aria-describedby={
                                                    errors.body
                                                        ? 'addendum-body-error'
                                                        : undefined
                                                }
                                                className="rounded-md border bg-background px-3 py-2 text-sm"
                                            />
                                            <InputError
                                                id="addendum-body-error"
                                                message={errors.body}
                                            />
                                        </div>
                                        <SignPassword
                                            id="addendum-password"
                                            error={errors.password}
                                        />
                                        <div>
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                                className="min-h-11"
                                            >
                                                Assinar adendo
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </SurgeryDescriptionEntry>
                </ul>
            ) : (
                <Form
                    {...SurgeryDescriptionController.store.form(surgery.id)}
                    errorBag="description"
                    options={{ preserveScroll: true }}
                    resetOnError={['password']}
                    className="grid gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            {descriptionFields.map(
                                ([name, label, required, max]) => (
                                    <div key={name} className="grid gap-2">
                                        <Label htmlFor={`description-${name}`}>
                                            {label}
                                        </Label>
                                        <textarea
                                            id={`description-${name}`}
                                            name={name}
                                            required={required}
                                            maxLength={max}
                                            rows={required ? 4 : 2}
                                            aria-invalid={!!errors[name]}
                                            aria-describedby={
                                                errors[name]
                                                    ? `description-${name}-error`
                                                    : undefined
                                            }
                                            className="rounded-md border bg-background px-3 py-2 text-sm"
                                        />
                                        <InputError
                                            id={`description-${name}-error`}
                                            message={errors[name]}
                                        />
                                    </div>
                                ),
                            )}
                            <SignPassword
                                id="description-password"
                                error={errors.password}
                            />
                            <InputError message={errors.description} />
                            <div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    Assinar e concluir a cirurgia
                                </Button>
                            </div>
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
    indication: string | null;
    status: 'requested' | 'scheduled' | 'in_progress' | 'completed';
    startedAt: string | null;
    completedAt: string | null;
    description: SurgeryDescriptionData | null;
    canDescribe: boolean;
    canAddendum: boolean;
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
    addendumDays,
    patient,
    open,
    allergies,
    record,
    prescriptions,
    documents,
    examResults,
    surgeryDescriptions,
}: {
    surgery: Surgery;
    roles: Record<string, string>;
    steps: Steps;
    answers: Record<string, string>;
    lateralities: Record<string, string>;
    urgencies: Record<string, string>;
    addendumDays: number;
    patient: RecordPatient;
    allergies: PatientAllergy[];
    open: boolean;
    record: RecordNote[];
    prescriptions: PrescriptionPanelData | null;
    documents: ClinicalDocument[];
    examResults: ExamResultItem[];
    surgeryDescriptions: SurgeryDescriptionData[];
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
                {open ? (
                    <PatientAllergies allergies={allergies} />
                ) : (
                    <p
                        role="status"
                        className="mb-6 rounded-xl border bg-card px-5 py-4 text-sm"
                    >
                        Cirurgia concluída em {surgery.completedAt}. O
                        prontuário não está mais aberto; os cirurgiões da equipe
                        assinam adendos à descrição por {addendumDays} dias.
                    </p>
                )}

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
                        {surgery.completedAt
                            ? `concluída em ${surgery.completedAt} · `
                            : surgery.startedAt &&
                              `em andamento desde ${surgery.startedAt} · `}
                        {surgery.start
                            ? `${surgery.room}, ${surgery.start} – ${surgery.end}`
                            : `aguardando agendamento, desejada para ${surgery.desiredDate}`}{' '}
                        · pedida por {surgery.author}
                    </p>
                    {surgery.indication && (
                        <p className="text-sm">
                            <span className="font-medium">Indicação:</span>{' '}
                            {surgery.indication}
                        </p>
                    )}
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
                    <Consent surgery={surgery} open={open} />
                    <Checklist
                        surgery={surgery}
                        steps={steps}
                        answers={answers}
                    />
                    <Description surgery={surgery} />
                    {open && (
                        <>
                            {prescriptions && (
                                <PrescriptionPanel data={prescriptions} />
                            )}
                            <ExamResults results={examResults} />
                            <SurgeryDescriptions
                                descriptions={surgeryDescriptions}
                            />
                            <RecordNotes record={record} />
                            <ClinicalDocuments documents={documents} />
                        </>
                    )}
                </div>
            </Page>
        </>
    );
}

function RecordNotes({ record }: { record: RecordNote[] }) {
    return (
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
    );
}
