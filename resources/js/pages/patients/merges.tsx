import { Form, Head } from '@inertiajs/react';
import { GitMerge } from 'lucide-react';
import PatientMergeController from '@/actions/App/Http/Controllers/PatientMergeController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { formatBirthDate } from '@/components/patient-display';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/patient-merges';

type Identity = {
    id: number;
    name: string;
    socialName: string | null;
    birthDate: string | null;
    motherName: string | null;
    cpf: string | null;
    cns: string | null;
    unit: string | null;
    status: string;
};

type Merge = {
    id: number;
    primary: Identity;
    duplicate: Identity;
    reason: string;
    by: string | null;
    at: string;
};

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function IdentityLines({ patient }: { patient: Identity }) {
    return (
        <span className="grid gap-0.5 text-sm">
            <span className="font-medium">
                {patient.socialName ?? patient.name}
            </span>
            <span className="text-muted-foreground tabular-nums">
                {[
                    formatBirthDate(patient.birthDate),
                    patient.cpf && `CPF ${patient.cpf}`,
                    patient.cns && `CNS ${patient.cns}`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </span>
            <span className="text-muted-foreground">
                {[
                    patient.motherName && `Mãe: ${patient.motherName}`,
                    patient.unit,
                    patient.status === 'provisional' && 'Provisório',
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </span>
        </span>
    );
}

/** Merging duplicate registrations (step 3.3): identity data only, never clinical content. */
export default function PatientMerges({
    pairs,
    merges,
}: {
    pairs: [Identity, Identity][];
    merges: Merge[];
}) {
    return (
        <>
            <Head title="Unificar cadastros" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Pacientes"
                    title="Unificar cadastros"
                    description="Cadastros com a mesma data de nascimento e nome parecido. Unificar não apaga nada: o duplicado passa a apontar para o principal e pode ser desfeito."
                />

                <section className="grid gap-4">
                    <h2 className="font-semibold">Possíveis duplicados</h2>
                    {pairs.length === 0 ? (
                        <div className="rounded-xl border bg-card px-5 py-12 text-center">
                            <GitMerge
                                className="mx-auto mb-3 size-6 text-primary"
                                aria-hidden="true"
                            />
                            <p className="font-medium">
                                Nenhuma suspeita no momento
                            </p>
                        </div>
                    ) : (
                        pairs.map(([a, b]) => (
                            <Form
                                key={`${a.id}-${b.id}`}
                                {...PatientMergeController.store.form()}
                                options={{ preserveScroll: true }}
                                className="grid gap-4 rounded-xl border bg-card p-5"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="a_id"
                                            value={a.id}
                                        />
                                        <input
                                            type="hidden"
                                            name="b_id"
                                            value={b.id}
                                        />
                                        <fieldset className="grid gap-3">
                                            <legend className="mb-2 text-sm text-muted-foreground">
                                                Qual é o cadastro principal? O
                                                outro passa a apontar para ele.
                                            </legend>
                                            {[a, b].map((patient) => (
                                                <label
                                                    key={patient.id}
                                                    className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-primary/5"
                                                >
                                                    <input
                                                        type="radio"
                                                        name="primary_id"
                                                        value={patient.id}
                                                        required
                                                        className="mt-1 size-4 accent-primary"
                                                    />
                                                    <IdentityLines
                                                        patient={patient}
                                                    />
                                                </label>
                                            ))}
                                        </fieldset>
                                        <div className="grid gap-2">
                                            <label
                                                htmlFor={`reason-${a.id}-${b.id}`}
                                                className="text-sm font-medium"
                                            >
                                                Motivo
                                            </label>
                                            <Input
                                                id={`reason-${a.id}-${b.id}`}
                                                name="reason"
                                                required
                                                maxLength={1000}
                                                placeholder="Ex.: mesmo CPF confirmado com o paciente"
                                                className="h-11"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Fica no histórico. Não inclua
                                                dados de saúde.
                                            </p>
                                            <InputError
                                                message={
                                                    errors.merge ??
                                                    errors.reason ??
                                                    errors.primary_id
                                                }
                                            />
                                        </div>
                                        <div className="flex justify-end">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="min-h-11"
                                            >
                                                Unificar
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        ))
                    )}
                </section>

                {merges.length > 0 && (
                    <section className="mt-10 grid gap-4">
                        <h2 className="font-semibold">Unificações recentes</h2>
                        {merges.map((merge) => (
                            <Form
                                key={merge.id}
                                {...PatientMergeController.undo.form(merge.id)}
                                options={{ preserveScroll: true }}
                                className="grid gap-4 rounded-xl border bg-card p-5"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div>
                                                <p className="mb-1 text-xs text-muted-foreground">
                                                    Principal
                                                </p>
                                                <IdentityLines
                                                    patient={merge.primary}
                                                />
                                            </div>
                                            <div>
                                                <p className="mb-1 text-xs text-muted-foreground">
                                                    Unificado nele
                                                </p>
                                                <IdentityLines
                                                    patient={merge.duplicate}
                                                />
                                            </div>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {merge.by} ·{' '}
                                            {dateTime.format(
                                                new Date(merge.at),
                                            )}{' '}
                                            · {merge.reason}
                                        </p>
                                        <div className="flex flex-wrap items-end gap-3">
                                            <div className="grid min-w-56 flex-1 gap-2">
                                                <label
                                                    htmlFor={`undo-${merge.id}`}
                                                    className="text-sm font-medium"
                                                >
                                                    Motivo para desfazer
                                                </label>
                                                <Input
                                                    id={`undo-${merge.id}`}
                                                    name="reason"
                                                    required
                                                    maxLength={1000}
                                                    className="h-11"
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                                className="min-h-11"
                                            >
                                                Desfazer
                                            </Button>
                                        </div>
                                        <InputError
                                            message={
                                                errors.merge ?? errors.reason
                                            }
                                        />
                                    </>
                                )}
                            </Form>
                        ))}
                    </section>
                )}
            </Page>
        </>
    );
}

PatientMerges.layout = {
    breadcrumbs: [{ title: 'Unificar cadastros', href: index() }],
};
