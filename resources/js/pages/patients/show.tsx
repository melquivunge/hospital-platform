import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CalendarPlus, FileHeart, Pencil } from 'lucide-react';
import PatientPortalInviteController from '@/actions/App/Http/Controllers/Portal/PatientPortalInviteController';
import PatientPortalGuardianController from '@/actions/App/Http/Controllers/Portal/PatientPortalGuardianController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import type { PatientSummary } from '@/components/patient-display';
import {
    formatBirthDate,
    sexLabels,
    StatusBadge,
} from '@/components/patient-display';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { index as appointments } from '@/routes/appointments';
import { show as record } from '@/routes/clinical-record';
import { index as emergency } from '@/routes/emergency';
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
    raceColor: string | null;
    ethnicity: string | null;
    nationality: string | null;
    address: string | null;
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
    portal,
    portalGuardians,
}: {
    patient: Patient;
    canUpdate: boolean;
    portal: 'none' | 'invited' | 'active';
    portalGuardians: { id: number; email: string }[] | null;
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
            ['Raça/cor', patient.raceColor],
            ...(patient.ethnicity
                ? [['Etnia', patient.ethnicity] as [string, string]]
                : []),
            ['Nacionalidade', patient.nationality],
            ['Unidade do cadastro', patient.unit],
        ],
        [
            ['CPF', formatCpf(patient.cpf)],
            ['Cartão Nacional de Saúde', formatCns(patient.cns)],
        ],
        [
            ['Nome da mãe', patient.mother_name],
            ['Endereço', patient.address],
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
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link
                                        href={emergency({
                                            query: { patient: patient.id },
                                        })}
                                    >
                                        Chegada na urgência
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

                {canUpdate && (
                    <PortalAccess patientId={patient.id} portal={portal} />
                )}

                {canUpdate && portalGuardians && (
                    <PortalGuardians
                        patientId={patient.id}
                        guardians={portalGuardians}
                    />
                )}

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

/** Step 7a.1a: the reception checks the document and sends the portal invitation. */
function PortalAccess({
    patientId,
    portal,
}: {
    patientId: number;
    portal: 'none' | 'invited' | 'active';
}) {
    return (
        <section aria-labelledby="portal-heading" className="mt-8">
            <h2 id="portal-heading" className="mb-3 font-semibold">
                Portal do paciente
            </h2>
            <div className="grid gap-3 rounded-xl border bg-card px-4 py-4 text-sm">
                <p>
                    {portal === 'active'
                        ? 'Acesso ativo. Se esquecer a senha, o paciente usa “Esqueci minha senha” no portal.'
                        : portal === 'invited'
                          ? 'Convite enviado, ainda sem senha criada. Você pode reenviar.'
                          : 'Sem acesso ao portal.'}
                </p>
                {portal !== 'active' && (
                    <Form
                        {...PatientPortalInviteController.store.form(patientId)}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-center gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <label className="flex min-h-11 items-center gap-3">
                                    <input
                                        type="checkbox"
                                        name="document_checked"
                                        value="1"
                                        required
                                        className="size-4 accent-primary"
                                    />
                                    Conferi o documento do paciente
                                </label>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    {processing && <Spinner />}
                                    {portal === 'invited'
                                        ? 'Reenviar convite'
                                        : 'Enviar convite ao portal'}
                                </Button>
                                <InputError
                                    message={
                                        errors.portal ?? errors.document_checked
                                    }
                                    className="w-full"
                                />
                            </>
                        )}
                    </Form>
                )}
            </div>
        </section>
    );
}

/** Step 7a.2: the reception links a minor to the guardian's own portal account, or ends the link. */
function PortalGuardians({
    patientId,
    guardians,
}: {
    patientId: number;
    guardians: { id: number; email: string }[];
}) {
    return (
        <section aria-labelledby="guardians-heading" className="mt-8">
            <h2 id="guardians-heading" className="mb-3 font-semibold">
                Responsáveis no portal
            </h2>
            <div className="grid gap-4 rounded-xl border bg-card px-4 py-4 text-sm">
                {guardians.length === 0 ? (
                    <p>
                        Nenhum responsável vinculado. O paciente é menor de
                        idade: quem tem a guarda acompanha pelo portal, na aba
                        “Filhos menores”.
                    </p>
                ) : (
                    <ul className="grid gap-2">
                        {guardians.map((guardian) => (
                            <li
                                key={guardian.id}
                                className="flex flex-wrap items-center justify-between gap-2"
                            >
                                <span className="break-all">
                                    {guardian.email}
                                </span>
                                <Form
                                    {...PatientPortalGuardianController.destroy.form(
                                        [patientId, guardian.id],
                                    )}
                                    options={{ preserveScroll: true }}
                                    onBefore={() =>
                                        confirm(
                                            `Encerrar o vínculo de ${guardian.email}? Ele deixa de ver este paciente no portal.`,
                                        )
                                    }
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            className="min-h-11"
                                        >
                                            {processing && <Spinner />}
                                            Encerrar vínculo
                                        </Button>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                )}
                <Form
                    {...PatientPortalGuardianController.store.form(patientId)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="guardian-email">
                                    E-mail de acesso do responsável ao portal
                                </Label>
                                <Input
                                    id="guardian-email"
                                    name="email"
                                    type="email"
                                    required
                                    autoComplete="off"
                                    aria-invalid={!!errors.email}
                                    aria-describedby="guardian-email-help guardian-email-error"
                                    className="h-11 max-w-md"
                                />
                                <p
                                    id="guardian-email-help"
                                    className="text-muted-foreground"
                                >
                                    O responsável precisa ter o próprio acesso
                                    ativo ao portal.
                                </p>
                                <InputError
                                    id="guardian-email-error"
                                    message={errors.email}
                                />
                            </div>
                            <label className="flex min-h-11 items-center gap-3">
                                <input
                                    type="checkbox"
                                    name="document_checked"
                                    value="1"
                                    required
                                    className="size-4 accent-primary"
                                />
                                Conferi o documento que prova a guarda
                            </label>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11 justify-self-start"
                            >
                                {processing && <Spinner />}
                                Vincular responsável
                            </Button>
                            <InputError
                                message={
                                    errors.guardian ?? errors.document_checked
                                }
                            />
                        </>
                    )}
                </Form>
            </div>
        </section>
    );
}
