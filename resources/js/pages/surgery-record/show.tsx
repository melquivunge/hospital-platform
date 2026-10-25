import { Head, Link } from '@inertiajs/react';
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
import { Button } from '@/components/ui/button';

type Surgery = {
    id: number;
    procedure: string;
    laterality: string;
    urgency: string;
    indication: string;
    status: 'requested' | 'scheduled';
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
