import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ExamOrderController from '@/actions/App/Http/Controllers/ExamOrderController';
import SurgeryOrderController from '@/actions/App/Http/Controllers/SurgeryOrderController';
import ClinicalDocumentController from '@/actions/App/Http/Controllers/ClinicalDocumentController';
import VisitController from '@/actions/App/Http/Controllers/VisitController';
import type { ClinicalDocument } from '@/components/clinical-documents';
import { ClinicalDocuments } from '@/components/clinical-documents';
import type { ExamResultItem } from '@/components/exam-results';
import { ExamResults } from '@/components/exam-results';
import type { Draft } from '@/components/clinical-notes';
import { NotesWorkspace } from '@/components/clinical-notes';
import type { RecordNote, RecordPatient } from '@/components/clinical-record';
import { patientDescription } from '@/components/clinical-record';
import type {
    AllergyForm,
    PatientAllergy,
} from '@/components/patient-allergies';
import { PatientAllergies } from '@/components/patient-allergies';
import type { PrescriptionPanelData } from '@/components/prescription-panel';
import { PrescriptionPanel } from '@/components/prescription-panel';
import PrescriptionController from '@/actions/App/Http/Controllers/PrescriptionController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { selectClassName } from '@/components/role-fields';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/visits';

/** The open visit (step 3.6a): the patient's signed record, the author's drafts, signing and addenda. */
export default function VisitShow({
    visit,
    patient,
    allergies,
    allergyForm,
    prescriptions,
    record,
    drafts,
    documents,
    examResults,
    admissionOptions,
    canOrder,
}: {
    visit: { id: number; openedAt: string | null; emergency: boolean };
    patient: RecordPatient;
    allergies: PatientAllergy[];
    allergyForm: AllergyForm;
    prescriptions: PrescriptionPanelData | null;
    record: RecordNote[];
    drafts: Draft[];
    documents: ClinicalDocument[];
    examResults: ExamResultItem[];
    admissionOptions: AdmissionOptions | null;
    canOrder: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Atendimento" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={`Atendimento desde ${visit.openedAt}`}
                    title={patient.name}
                    description={patientDescription(patient)}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canOrder && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link
                                        href={ExamOrderController.forVisit(
                                            visit.id,
                                        )}
                                    >
                                        Exames
                                    </Link>
                                </Button>
                            )}
                            {canOrder && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link
                                        href={SurgeryOrderController.forVisit(
                                            visit.id,
                                        )}
                                    >
                                        Cirurgias
                                    </Link>
                                </Button>
                            )}
                            {!visit.emergency && (
                                <Form {...VisitController.close.form(visit.id)}>
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            className="min-h-11"
                                        >
                                            Encerrar atendimento
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </div>
                    }
                />
                <PatientAllergies allergies={allergies} form={allergyForm} />
                <InputError message={errors.visit} className="mb-4" />
                {admissionOptions && (
                    <CloseEmergency
                        visitId={visit.id}
                        options={admissionOptions}
                    />
                )}

                {prescriptions && (
                    <div className="mb-8">
                        <PrescriptionPanel
                            data={prescriptions}
                            start={PrescriptionController.startForVisit.form(
                                visit.id,
                            )}
                        />
                    </div>
                )}
                <NotesWorkspace
                    title="Evolução deste atendimento"
                    action={VisitController.storeNote.form(visit.id)}
                    drafts={drafts}
                    record={record}
                >
                    <ExamResults results={examResults} />
                    <ClinicalDocuments
                        documents={documents}
                        attach={ClinicalDocumentController.store.form(visit.id)}
                    />
                </NotesWorkspace>
            </Page>
        </>
    );
}

const outcomes = [
    { value: 'discharged', label: 'Alta da urgência' },
    { value: 'admission_requested', label: 'Pedido de internação' },
    { value: 'evaded', label: 'Evasão (saiu durante o atendimento)' },
];

type AdmissionOptions = {
    sectors: { id: number; name: string }[];
    professionals: { id: number; name: string }[];
    me: number;
};

/** Step 4.3: an emergency visit closes with its outcome; an admission request (4.4a) names sector and doctor. */
function CloseEmergency({
    visitId,
    options,
}: {
    visitId: number;
    options: AdmissionOptions;
}) {
    const [outcome, setOutcome] = useState('');

    return (
        <details className="mb-6 rounded-xl border bg-card px-5 py-3">
            <summary className="flex min-h-11 cursor-pointer items-center font-medium">
                Encerrar atendimento de urgência
            </summary>
            <Form
                {...VisitController.close.form(visitId)}
                className="grid gap-4 py-3"
            >
                {({ processing, errors }) => (
                    <>
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                Desfecho
                            </legend>
                            {outcomes.map((outcome) => (
                                <label
                                    key={outcome.value}
                                    className="flex min-h-11 items-center gap-2 text-sm"
                                >
                                    <input
                                        type="radio"
                                        name="outcome"
                                        value={outcome.value}
                                        required
                                        onChange={() =>
                                            setOutcome(outcome.value)
                                        }
                                        className="size-4 accent-primary"
                                    />
                                    {outcome.label}
                                </label>
                            ))}
                            <InputError message={errors.outcome} />
                        </fieldset>
                        {outcome === 'admission_requested' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="requested_sector_id">
                                        Setor de internação
                                    </Label>
                                    <select
                                        id="requested_sector_id"
                                        name="requested_sector_id"
                                        required
                                        defaultValue=""
                                        className={selectClassName}
                                    >
                                        <option value="" disabled>
                                            Selecione
                                        </option>
                                        {options.sectors.map((sector) => (
                                            <option
                                                key={sector.id}
                                                value={sector.id}
                                            >
                                                {sector.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.requested_sector_id}
                                    />
                                </div>
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="responsible_professional_id">
                                        Médico responsável
                                    </Label>
                                    <select
                                        id="responsible_professional_id"
                                        name="responsible_professional_id"
                                        required
                                        defaultValue={options.me}
                                        className={selectClassName}
                                    >
                                        {options.professionals.map(
                                            (professional) => (
                                                <option
                                                    key={professional.id}
                                                    value={professional.id}
                                                >
                                                    {professional.name}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    <InputError
                                        message={
                                            errors.responsible_professional_id
                                        }
                                    />
                                </div>
                                <label className="flex min-h-11 items-center gap-2 text-sm">
                                    <input
                                        type="hidden"
                                        name="isolation"
                                        value="0"
                                    />
                                    <input
                                        type="checkbox"
                                        name="isolation"
                                        value="1"
                                        className="size-4 accent-primary"
                                    />
                                    Precisa de isolamento
                                </label>
                            </div>
                        )}
                        <p className="text-sm text-muted-foreground">
                            Assine os rascunhos antes. Depois de encerrar, o
                            prontuário deixa de abrir para você por este
                            atendimento.
                        </p>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            Encerrar atendimento
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}

VisitShow.layout = {
    breadcrumbs: [{ title: 'Meus atendimentos', href: index() }],
};
