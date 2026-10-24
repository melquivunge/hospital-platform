import { Form, Head, Link, usePage } from '@inertiajs/react';
import ExamQueueController from '@/actions/App/Http/Controllers/ExamQueueController';
import type { ClinicalDocument } from '@/components/clinical-documents';
import { ClinicalDocuments } from '@/components/clinical-documents';
import type { RecordNote, RecordPatient } from '@/components/clinical-record';
import { patientDescription, RecordEntry } from '@/components/clinical-record';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import type { PatientAllergy } from '@/components/patient-allergies';
import { PatientAllergies } from '@/components/patient-allergies';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/exam-queue';

/**
 * The execution of an exam order (step 5.3, ADR-0008): the items of the user's types, each collected or
 * performed once, and the patient's record, read-only while the order is open. This opening was logged.
 */
export default function ExamExecution({
    order,
    items,
    types,
    priorities,
    patient,
    allergies,
    record,
    documents,
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
    items: {
        id: number;
        name: string;
        type: string;
        collectedAt: string | null;
        collectedBy: string | null;
    }[];
    types: Record<string, string>;
    priorities: Record<string, string>;
    patient: RecordPatient;
    allergies: PatientAllergy[];
    record: RecordNote[];
    documents: ClinicalDocument[];
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
                <PatientAllergies allergies={allergies} />

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
                            <li
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                            >
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
                            </li>
                        ))}
                    </ul>
                </section>

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
                    <ClinicalDocuments documents={documents} />
                </div>
            </Page>
        </>
    );
}
