import { Form, Head, Link } from '@inertiajs/react';
import { ChevronRight, Plus, Search, UserRound } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import type { PatientSummary } from '@/components/patient-display';
import {
    formatBirthDate,
    PatientName,
    StatusBadge,
} from '@/components/patient-display';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, index, show } from '@/routes/patients';

/** Patient search (step 3.2): the registry is searched, never listed whole. */
export default function PatientsIndex({
    q,
    patients,
    canCreate,
}: {
    q: string;
    patients: PatientSummary[];
    canCreate: boolean;
}) {
    const searched = q.trim().length >= 3;

    return (
        <>
            <Head title="Pacientes" />
            <Page>
                <PageHeader
                    eyebrow="Atendimento"
                    title="Pacientes"
                    description="Busque pelo nome, CPF, Cartão Nacional de Saúde ou data de nascimento antes de cadastrar."
                    actions={
                        canCreate && (
                            <Button asChild className="min-h-11">
                                <Link href={create()}>
                                    <Plus aria-hidden="true" />
                                    Novo paciente
                                </Link>
                            </Button>
                        )
                    }
                />

                <Form
                    action={index().url}
                    method="get"
                    options={{ preserveScroll: true }}
                    className="mb-6"
                    role="search"
                >
                    <label htmlFor="q" className="sr-only">
                        Buscar paciente
                    </label>
                    <div className="relative">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            id="q"
                            name="q"
                            type="search"
                            defaultValue={q}
                            minLength={3}
                            autoComplete="off"
                            autoFocus
                            placeholder="Nome, CPF, cartão SUS ou dd/mm/aaaa"
                            className="h-12 rounded-xl pl-10 text-base"
                        />
                    </div>
                </Form>

                {!searched ? (
                    <div className="rounded-xl border bg-card px-5 py-12 text-center">
                        <UserRound
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Busque antes de cadastrar</p>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                            Digite ao menos 3 caracteres. Assim evitamos dois
                            cadastros para a mesma pessoa.
                        </p>
                    </div>
                ) : patients.length === 0 ? (
                    <div className="rounded-xl border bg-card px-5 py-12 text-center">
                        <p className="font-medium">
                            Nenhum paciente encontrado
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Confira a grafia ou busque pelo documento.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                        {patients.map((patient) => (
                            <li key={patient.id}>
                                <Link
                                    href={show(patient.id)}
                                    className="flex min-h-16 items-center gap-4 px-4 py-3 transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                >
                                    <span className="grid min-w-0 flex-1 gap-1">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <PatientName patient={patient} />
                                            <StatusBadge
                                                status={patient.status}
                                            />
                                        </span>
                                        <span className="text-sm text-muted-foreground tabular-nums">
                                            {[
                                                formatBirthDate(
                                                    patient.birthDate,
                                                ),
                                                patient.cpf &&
                                                    `CPF ${patient.cpf}`,
                                                patient.cns &&
                                                    `CNS ${patient.cns}`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
                                    <ChevronRight
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

PatientsIndex.layout = {
    breadcrumbs: [{ title: 'Pacientes', href: index() }],
};
