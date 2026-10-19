import { Form, Head } from '@inertiajs/react';
import ClinicalDocumentController from '@/actions/App/Http/Controllers/ClinicalDocumentController';
import WardController from '@/actions/App/Http/Controllers/WardController';
import type { ClinicalDocument } from '@/components/clinical-documents';
import { ClinicalDocuments } from '@/components/clinical-documents';
import type { Draft } from '@/components/clinical-notes';
import { NotesWorkspace } from '@/components/clinical-notes';
import type { RecordNote, RecordPatient } from '@/components/clinical-record';
import { patientDescription } from '@/components/clinical-record';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/ward';

/**
 * The record of an inpatient (step 4.4b, ADR-0004): the responsible doctor and the bed's sector team read
 * and write while the patient is there. Each author keeps one draft; signing is that of delivery 3.
 */
export default function WardShow({
    admission,
    patient,
    history,
    handOver,
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
    history: { id: number; at: string; text: string; reason: string | null }[];
    handOver: { id: number; name: string }[] | null;
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
                {handOver && (
                    <details className="mb-6 rounded-xl border bg-card px-5 py-3">
                        <summary className="flex min-h-11 cursor-pointer items-center font-medium">
                            Passar a responsabilidade
                        </summary>
                        <Form
                            {...WardController.handOver.form(admission.id)}
                            className="grid gap-4 py-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="professional_id">
                                            Novo médico responsável
                                        </Label>
                                        <select
                                            id="professional_id"
                                            name="professional_id"
                                            required
                                            defaultValue=""
                                            className={selectClassName}
                                        >
                                            <option value="" disabled>
                                                Selecione
                                            </option>
                                            {handOver.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.professional_id}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="reason">
                                            Motivo (opcional)
                                        </Label>
                                        <Input
                                            id="reason"
                                            name="reason"
                                            maxLength={500}
                                            className="h-11"
                                        />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Depois de passar, você deixa de ler o
                                        prontuário por esta internação, salvo se
                                        for da equipe do setor do leito.
                                    </p>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="min-h-11 justify-self-start"
                                    >
                                        Passar a responsabilidade
                                    </Button>
                                </>
                            )}
                        </Form>
                    </details>
                )}
                <NotesWorkspace
                    title="Evolução da internação"
                    action={WardController.storeNote.form(admission.id)}
                    drafts={drafts}
                    record={record}
                >
                    <section className="grid gap-3 rounded-xl border bg-card p-5 sm:p-6">
                        <h2 className="font-semibold">
                            Histórico de leitos e responsáveis
                        </h2>
                        {history.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Aguardando leito.
                            </p>
                        ) : (
                            <ol className="grid gap-1 text-sm text-muted-foreground">
                                {history.map((entry) => (
                                    <li key={entry.id}>
                                        {entry.at} · {entry.text}
                                        {entry.reason && ` (${entry.reason})`}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>
                    <ClinicalDocuments
                        documents={documents}
                        attach={ClinicalDocumentController.storeForAdmission.form(
                            admission.id,
                        )}
                    />
                </NotesWorkspace>
            </Page>
        </>
    );
}

WardShow.layout = {
    breadcrumbs: [{ title: 'Internados', href: index() }],
};
