import { Head } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import type { RecordNote, RecordPatient } from '@/components/clinical-record';
import { patientDescription, RecordEntry } from '@/components/clinical-record';
import { Page, PageHeader } from '@/components/page';

/** Read-only record under an emergency grant (step 3.6b). */
export default function ClinicalRecord({
    patient,
    expiresAt,
    record,
}: {
    patient: RecordPatient;
    expiresAt: string;
    record: RecordNote[];
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
            </Page>
        </>
    );
}
