import { Form, Head, Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import PatientController from '@/actions/App/Http/Controllers/PatientController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import type { PatientSummary } from '@/components/patient-display';
import {
    formatBirthDate,
    PatientName,
    sexLabels,
} from '@/components/patient-display';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, show } from '@/routes/patients';

type Patient = {
    id: number;
    registered_unit_id: number;
    name: string;
    social_name: string | null;
    birth_date: string | null;
    sex: string | null;
    cpf: string | null;
    cns: string | null;
    mother_name: string | null;
    phone: string | null;
    email: string | null;
    guardian_name: string | null;
    guardian_relationship: string | null;
    guardian_phone: string | null;
    race_color?: string | null;
    ethnicity?: string | null;
};

/** Patient registration (step 3.2): without documents it stays provisional, completed later. */
export default function PatientForm({
    patient,
    units,
    duplicates,
    raceColors,
    ethnicities,
}: {
    patient: Patient | null;
    units: { id: number; name: string }[];
    duplicates: PatientSummary[];
    raceColors: Record<string, string>;
    ethnicities: { code: string; name: string }[];
}) {
    const title = patient ? 'Editar cadastro' : 'Novo paciente';
    // Step 6b.6a1: the ethnicity is asked only for the indigenous race/color (05).
    const [raceColor, setRaceColor] = useState(patient?.race_color ?? '');

    return (
        <>
            <Head title={title} />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Pacientes"
                    title={title}
                    description="CPF e Cartão Nacional de Saúde são opcionais. Sem eles, o cadastro fica provisório até ser completado."
                />

                <Form
                    {...(patient
                        ? PatientController.update.form(patient.id)
                        : PatientController.store.form())}
                    className="grid gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {errors.confirm_new && duplicates.length > 0 && (
                                <section
                                    role="alert"
                                    className="rounded-xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-500/40 dark:bg-amber-500/10"
                                >
                                    <h2 className="flex items-center gap-2 font-semibold">
                                        <TriangleAlert
                                            className="size-4 text-amber-700 dark:text-amber-300"
                                            aria-hidden="true"
                                        />
                                        Esta pessoa já pode ter cadastro
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {errors.confirm_new}
                                    </p>
                                    <ul className="mt-4 divide-y rounded-lg border bg-card">
                                        {duplicates.map((duplicate) => (
                                            <li
                                                key={duplicate.id}
                                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm"
                                            >
                                                <span className="grid gap-0.5">
                                                    <PatientName
                                                        patient={duplicate}
                                                    />
                                                    <span className="text-muted-foreground tabular-nums">
                                                        {[
                                                            formatBirthDate(
                                                                duplicate.birthDate,
                                                            ),
                                                            duplicate.cpf &&
                                                                `CPF ${duplicate.cpf}`,
                                                            duplicate.unit,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </span>
                                                </span>
                                                <Link
                                                    href={show(duplicate.id)}
                                                    className="flex min-h-11 items-center font-medium text-primary underline"
                                                >
                                                    Usar este cadastro
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                    <label className="mt-4 flex min-h-11 items-center gap-3 text-sm font-medium">
                                        <input
                                            type="checkbox"
                                            name="confirm_new"
                                            value="1"
                                            className="size-4 accent-primary"
                                        />
                                        Conferi: é outra pessoa, cadastrar mesmo
                                        assim
                                    </label>
                                </section>
                            )}

                            <Section title="Identificação">
                                {(units.length > 1 || patient !== null) && (
                                    <Field
                                        id="registered_unit_id"
                                        label="Unidade do cadastro"
                                        error={errors.registered_unit_id}
                                    >
                                        <select
                                            id="registered_unit_id"
                                            name="registered_unit_id"
                                            required
                                            defaultValue={
                                                patient?.registered_unit_id ??
                                                (units.length === 1
                                                    ? units[0].id
                                                    : '')
                                            }
                                            className={selectClassName}
                                        >
                                            <option value="" disabled>
                                                Selecione
                                            </option>
                                            {units.map((unit) => (
                                                <option
                                                    key={unit.id}
                                                    value={unit.id}
                                                >
                                                    {unit.name}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                )}
                                {units.length === 1 && patient === null && (
                                    <input
                                        type="hidden"
                                        name="registered_unit_id"
                                        value={units[0].id}
                                    />
                                )}
                                <Field
                                    id="name"
                                    label="Nome civil completo"
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        maxLength={255}
                                        autoComplete="off"
                                        defaultValue={patient?.name}
                                        aria-invalid={!!errors.name}
                                        className="h-11"
                                    />
                                </Field>
                                <Field
                                    id="social_name"
                                    label="Nome social (opcional)"
                                    hint="Como a pessoa prefere ser chamada. Aparece em destaque."
                                    error={errors.social_name}
                                >
                                    <Input
                                        id="social_name"
                                        name="social_name"
                                        maxLength={255}
                                        autoComplete="off"
                                        defaultValue={
                                            patient?.social_name ?? ''
                                        }
                                        className="h-11"
                                    />
                                </Field>
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        id="birth_date"
                                        label="Data de nascimento"
                                        error={errors.birth_date}
                                    >
                                        <Input
                                            id="birth_date"
                                            name="birth_date"
                                            type="date"
                                            defaultValue={
                                                patient?.birth_date ?? ''
                                            }
                                            aria-invalid={!!errors.birth_date}
                                            className="h-11"
                                        />
                                    </Field>
                                    <Field
                                        id="sex"
                                        label="Sexo"
                                        error={errors.sex}
                                    >
                                        <select
                                            id="sex"
                                            name="sex"
                                            defaultValue={patient?.sex ?? ''}
                                            className={selectClassName}
                                        >
                                            <option value="">Selecione</option>
                                            {Object.entries(sexLabels).map(
                                                ([value, label]) => (
                                                    <option
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </Field>
                                    <Field
                                        id="race_color"
                                        label="Raça/cor (autodeclarada)"
                                        hint="Como a pessoa se declara. Exigida no faturamento SUS."
                                        error={errors.race_color}
                                    >
                                        <select
                                            id="race_color"
                                            name="race_color"
                                            value={raceColor}
                                            onChange={(event) =>
                                                setRaceColor(event.target.value)
                                            }
                                            className={selectClassName}
                                        >
                                            <option value="">Selecione</option>
                                            {Object.entries(raceColors).map(
                                                ([value, label]) => (
                                                    <option
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </Field>
                                    {raceColor === '05' && (
                                        <Field
                                            id="ethnicity"
                                            label="Etnia"
                                            error={errors.ethnicity}
                                        >
                                            <select
                                                id="ethnicity"
                                                name="ethnicity"
                                                required
                                                defaultValue={
                                                    patient?.ethnicity ?? ''
                                                }
                                                aria-invalid={
                                                    !!errors.ethnicity
                                                }
                                                className={selectClassName}
                                            >
                                                <option value="">
                                                    Selecione
                                                </option>
                                                {ethnicities.map((option) => (
                                                    <option
                                                        key={option.code}
                                                        value={option.code}
                                                    >
                                                        {option.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                    )}
                                </div>
                            </Section>

                            <Section
                                title="Documentos"
                                description="Opcionais, mas únicos na rede. Com um deles, a data de nascimento e o sexo, o cadastro fica completo."
                            >
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        id="cpf"
                                        label="CPF"
                                        error={errors.cpf}
                                    >
                                        <Input
                                            id="cpf"
                                            name="cpf"
                                            inputMode="numeric"
                                            autoComplete="off"
                                            maxLength={14}
                                            placeholder="000.000.000-00"
                                            defaultValue={patient?.cpf ?? ''}
                                            aria-invalid={!!errors.cpf}
                                            className="h-11 tabular-nums"
                                        />
                                    </Field>
                                    <Field
                                        id="cns"
                                        label="Cartão Nacional de Saúde"
                                        error={errors.cns}
                                    >
                                        <Input
                                            id="cns"
                                            name="cns"
                                            inputMode="numeric"
                                            autoComplete="off"
                                            maxLength={18}
                                            placeholder="000 0000 0000 0000"
                                            defaultValue={patient?.cns ?? ''}
                                            aria-invalid={!!errors.cns}
                                            className="h-11 tabular-nums"
                                        />
                                    </Field>
                                </div>
                            </Section>

                            <Section title="Contato e filiação">
                                <Field
                                    id="mother_name"
                                    label="Nome da mãe (opcional)"
                                    error={errors.mother_name}
                                >
                                    <Input
                                        id="mother_name"
                                        name="mother_name"
                                        maxLength={255}
                                        autoComplete="off"
                                        defaultValue={
                                            patient?.mother_name ?? ''
                                        }
                                        className="h-11"
                                    />
                                </Field>
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        id="phone"
                                        label="Telefone"
                                        error={errors.phone}
                                    >
                                        <Input
                                            id="phone"
                                            name="phone"
                                            type="tel"
                                            maxLength={30}
                                            autoComplete="off"
                                            defaultValue={patient?.phone ?? ''}
                                            className="h-11"
                                        />
                                    </Field>
                                    <Field
                                        id="email"
                                        label="E-mail"
                                        error={errors.email}
                                    >
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            maxLength={255}
                                            autoComplete="off"
                                            defaultValue={patient?.email ?? ''}
                                            className="h-11"
                                        />
                                    </Field>
                                </div>
                            </Section>

                            <Section
                                title="Responsável legal"
                                description="Para menores de idade ou pessoas tuteladas."
                            >
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        id="guardian_name"
                                        label="Nome"
                                        error={errors.guardian_name}
                                    >
                                        <Input
                                            id="guardian_name"
                                            name="guardian_name"
                                            maxLength={255}
                                            autoComplete="off"
                                            defaultValue={
                                                patient?.guardian_name ?? ''
                                            }
                                            className="h-11"
                                        />
                                    </Field>
                                    <Field
                                        id="guardian_relationship"
                                        label="Parentesco"
                                        error={errors.guardian_relationship}
                                    >
                                        <Input
                                            id="guardian_relationship"
                                            name="guardian_relationship"
                                            maxLength={60}
                                            autoComplete="off"
                                            placeholder="Mãe, pai, tutor..."
                                            defaultValue={
                                                patient?.guardian_relationship ??
                                                ''
                                            }
                                            className="h-11"
                                        />
                                    </Field>
                                </div>
                                <Field
                                    id="guardian_phone"
                                    label="Telefone do responsável"
                                    error={errors.guardian_phone}
                                >
                                    <Input
                                        id="guardian_phone"
                                        name="guardian_phone"
                                        type="tel"
                                        maxLength={30}
                                        autoComplete="off"
                                        defaultValue={
                                            patient?.guardian_phone ?? ''
                                        }
                                        className="h-11"
                                    />
                                </Field>
                            </Section>

                            <div className="flex flex-wrap justify-end gap-3">
                                <Button
                                    variant="ghost"
                                    asChild
                                    className="min-h-11"
                                >
                                    <Link
                                        href={
                                            patient ? show(patient.id) : index()
                                        }
                                    >
                                        Cancelar
                                    </Link>
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    {processing && <Spinner />}
                                    {patient
                                        ? 'Salvar alterações'
                                        : 'Cadastrar paciente'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="grid gap-6 rounded-xl border bg-card p-5 sm:p-6">
            <div>
                <h2 className="font-semibold">{title}</h2>
                {description && (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function Field({
    id,
    label,
    hint,
    error,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

PatientForm.layout = {
    breadcrumbs: [{ title: 'Pacientes', href: index() }],
};
