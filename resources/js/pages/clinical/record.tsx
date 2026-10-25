import { Head } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import type { RecordNote, RecordPatient } from '@/components/clinical-record';
import { patientDescription, RecordEntry } from '@/components/clinical-record';
import type { ClinicalDocument } from '@/components/clinical-documents';
import { ClinicalDocuments } from '@/components/clinical-documents';
import type { ExamResultItem } from '@/components/exam-results';
import { ExamResults } from '@/components/exam-results';
import { SurgeryDescriptions } from '@/components/surgery-descriptions';
import type { SurgeryDescriptionData } from '@/components/surgery-descriptions';
import { Page, PageHeader } from '@/components/page';
import type { PatientAllergy } from '@/components/patient-allergies';
import { PatientAllergies } from '@/components/patient-allergies';

/** Read-only record under an emergency grant (step 3.6b). */
export default function ClinicalRecord({
    patient,
    allergies,
    expiresAt,
    record,
    documents,
    examResults,
    surgeryDescriptions,
}: {
    patient: RecordPatient;
    allergies: PatientAllergy[];
    expiresAt: string;
    record: RecordNote[];
    documents: ClinicalDocument[];
    examResults: ExamResultItem[];
    surgeryDescriptions: SurgeryDescriptionData[];
}) {
    return (
        <>
            <Head title="Prontuário" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Prontuário"
                    title={patient.name}
                    description={patientDescription(patient)}
                />
                <PatientAllergies allergies={allergies} />
                <p
                    role="status"
                    className="mb-6 flex items-start gap-2 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
                >
                    <ShieldAlert
                        className="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-400"
                        aria-hidden="true"
                    />
                    Acesso de emergência, só leitura, válido até {expiresAt}.
                    Esta leitura foi registrada e a gestão da unidade foi
                    avisada.
                </p>
                <div className="grid gap-8">
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
                    <ExamResults results={examResults} />
                    <SurgeryDescriptions descriptions={surgeryDescriptions} />
                    <ClinicalDocuments documents={documents} />
                </div>
            </Page>
        </>
    );
}
