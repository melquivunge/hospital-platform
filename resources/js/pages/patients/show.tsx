import { Head, Link, usePage } from '@inertiajs/react';
import { CalendarPlus, FileHeart, Pencil } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import type { PatientSummary } from '@/components/patient-display';
import {
    formatBirthDate,
    sexLabels,
    StatusBadge,
} from '@/components/patient-display';
import { Button } from '@/components/ui/button';
import { index as appointments } from '@/routes/appointments';
import { show as record } from '@/routes/clinical-record';
import { edit, index } from '@/routes/patients';

type Patient = PatientSummary & {
    cpf: string | null;
    cns: string | null;
    mother_name: string | null;
    phone: string | null;
    email: string | null;
    guardian_name: string | null;
    guardian_relationship: string | null;
    guardian_phone: string | null;
};

function formatCpf(cpf: string | null): string | null {
    return cpf && cpf.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
}

function formatCns(cns: string | null): string | null {
    return cns && cns.replace(/^(\d{3})(\d{4})(\d{4})(\d{4})$/, '$1 $2 $3 $4');
}

/** Patient registration data (step 3.2). Clinical records arrive in step 3.6. */
export default function PatientShow({
    patient,
    canUpdate,
    unifiedFrom,
}: {
    patient: Patient;
    canUpdate: boolean;
    unifiedFrom: {
        id: number;
        name: string | null;
        birthDate: string | null;
        cpf: string | null;
    }[];
}) {
    const { canBookAppointments, canAttend } = usePage().props;
    const rows: [string, string | null][][] = [
        [
            ['Nome civil', patient.name],
            ['Nome social', patient.socialName],
            ['Nascimento', formatBirthDate(patient.birthDate)],
            ['Sexo', patient.sex ? sexLabels[patient.sex] : null],
            ['Unidade do cadastro', patient.unit],
        ],
        [
            ['CPF', formatCpf(patient.cpf)],
            ['Cartão Nacional de Saúde', formatCns(patient.cns)],
        ],
        [
            ['Nome da mãe', patient.mother_name],
            ['Telefone', patient.phone],
            ['E-mail', patient.email],
        ],
        [
            ['Responsável legal', patient.guardian_name],
            ['Parentesco', patient.guardian_relationship],
            ['Telefone do responsável', patient.guardian_phone],
        ],
    ];

    return (
        <>
            <Head title={patient.socialName ?? patient.name} />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Paciente"
                    title={patient.socialName ?? patient.name}
                    description={
                        patient.status === 'provisional'
                            ? 'Cadastro provisório: falta um documento (CPF ou Cartão Nacional de Saúde), a data de nascimento ou o sexo.'
                            : undefined
                    }
                    actions={
                        <>
                            <StatusBadge status={patient.status} />
                            {canAttend && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link href={record(patient.id)}>
                                        <FileHeart aria-hidden="true" />
                                        Prontuário
                                    </Link>
                                </Button>
                            )}
                            {canBookAppointments && (
                                <Button asChild className="min-h-11">
                                    <Link
                                        href={appointments({
                                            query: { patient: patient.id },
                                        })}
                                    >
                                        <CalendarPlus aria-hidden="true" />
                                        Agendar consulta
                                    </Link>
                                </Button>
                            )}
                            {canUpdate && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link href={edit(patient.id)}>
                                        <Pencil aria-hidden="true" />
                                        Editar
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-4">
                    {rows.map((group, position) => (
                        <dl
                            key={position}
                            className="divide-y rounded-xl border bg-card text-sm"
                        >
                            {group.map(([label, value]) => (
                                <div
                                    key={label}
                                    className="flex flex-wrap justify-between gap-x-6 gap-y-1 px-4 py-3"
                                >
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd
                                        className={
                                            value
                                                ? 'text-right font-medium tabular-nums'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {value ?? 'Não informado'}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    ))}
                </div>

                {unifiedFrom.length > 0 && (
                    <section className="mt-8">
                        <h2 className="mb-3 font-semibold">
                            Cadastros unificados neste
                        </h2>
                        <ul className="divide-y rounded-xl border bg-card text-sm">
                            {unifiedFrom.map((source) => (
                                <li key={source.id} className="px-4 py-3">
                                    <span className="font-medium">
                                        {source.name}
                                    </span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {' · '}
                                        {[
                                            formatBirthDate(source.birthDate),
                                            source.cpf && `CPF ${source.cpf}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </Page>
        </>
    );
}

PatientShow.layout = {
    breadcrumbs: [{ title: 'Pacientes', href: index() }],
};
