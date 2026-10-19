import { Head } from '@inertiajs/react';
import WardController from '@/actions/App/Http/Controllers/WardController';
import type { ClinicalDocument } from '@/components/clinical-documents';
import { ClinicalDocuments } from '@/components/clinical-documents';
import type { Draft } from '@/components/clinical-notes';
import { NotesWorkspace } from '@/components/clinical-notes';
import type { RecordNote, RecordPatient } from '@/components/clinical-record';
import { patientDescription } from '@/components/clinical-record';
import { Page, PageHeader } from '@/components/page';
import { index } from '@/routes/ward';

/**
 * The record of an inpatient (step 4.4b, ADR-0004): the responsible doctor and the bed's sector team read
 * and write while the patient is there. Each author keeps one draft; signing is that of delivery 3.
 */
export default function WardShow({
    admission,
    patient,
    record,
    drafts,
    documents,
}: {
    admission: {
        id: number;
        status: 'requested' | 'admitted';
        bed: string | null;
        since: string;
        responsible: string | null;
    };
    patient: RecordPatient;
    record: RecordNote[];
    drafts: Draft[];
    documents: ClinicalDocument[];
}) {
    return (
        <>
            <Head title="Internação" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={
                        admission.status === 'admitted'
                            ? `Internado desde ${admission.since}`
                            : `Aguardando leito desde ${admission.since}`
                    }
                    title={patient.name}
                    description={[
                        patientDescription(patient),
                        admission.bed,
                        `Responsável: ${admission.responsible}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />
                <NotesWorkspace
                    title="Evolução da internação"
                    action={WardController.storeNote.form(admission.id)}
                    drafts={drafts}
                    record={record}
                >
                    <ClinicalDocuments documents={documents} />
                </NotesWorkspace>
            </Page>
        </>
    );
}

WardShow.layout = {
    breadcrumbs: [{ title: 'Internados', href: index() }],
};
