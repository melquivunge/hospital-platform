import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ProfessionalController from '@/actions/App/Http/Controllers/ProfessionalController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, show } from '@/routes/professionals';

type Professional = {
    id: number;
    name: string;
    base_unit_id: number;
    council: string | null;
    council_number: string | null;
    council_uf: string | null;
    external_id: string | null;
    email: string | null;
    phone: string | null;
    cpf: string | null;
};

const selectClassName =
    'h-11 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-invalid:border-destructive';

export default function ProfessionalForm({
    professional,
    units,
    ufs,
}: {
    professional: Professional | null;
    units: { id: number; name: string }[];
    ufs: string[];
}) {
    const title = professional ? 'Editar profissional' : 'Novo profissional';
    const [baseUnitId, setBaseUnitId] = useState(
        String(professional?.base_unit_id ?? ''),
    );
    const baseChanges =
        professional !== null &&
        baseUnitId !== String(professional.base_unit_id);
    // A manager may edit someone based in their unit; the current base is always listed.
    const currentBaseListed =
        professional === null ||
        units.some((unit) => unit.id === professional.base_unit_id);

    return (
        <>
            <Head title={title} />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Profissionais"
                    title={title}
                    description="A unidade-base define a UF usada em vagas e transferências. Só muda por edição autorizada, com motivo."
                />

                <Form
                    {...(professional
                        ? ProfessionalController.update.form(professional.id)
                        : ProfessionalController.store.form())}
                    className="overflow-hidden rounded-lg border bg-card"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-6 p-5 sm:p-6">
                                <Field
                                    id="name"
                                    label="Nome completo"
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        maxLength={255}
                                        defaultValue={professional?.name}
                                        aria-invalid={!!errors.name}
                                        aria-describedby={
                                            errors.name
                                                ? 'name-error'
                                                : undefined
                                        }
                                        className="h-11"
                                    />
                                </Field>

                                <Field
                                    id="base_unit_id"
                                    label="Unidade-base"
                                    error={errors.base_unit_id}
                                >
                                    <select
                                        id="base_unit_id"
                                        name="base_unit_id"
                                        required
                                        value={baseUnitId}
                                        onChange={(event) =>
                                            setBaseUnitId(event.target.value)
                                        }
                                        aria-invalid={!!errors.base_unit_id}
                                        aria-describedby={
                                            errors.base_unit_id
                                                ? 'base_unit_id-error'
                                                : undefined
                                        }
                                        className={selectClassName}
                                    >
                                        <option value="" disabled>
                                            Selecione
                                        </option>
                                        {!currentBaseListed && professional && (
                                            <option
                                                value={
                                                    professional.base_unit_id
                                                }
                                            >
                                                Unidade atual
                                            </option>
                                        )}
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

                                {baseChanges && (
                                    <Field
                                        id="base_unit_reason"
                                        label="Motivo da troca de unidade-base"
                                        hint="Fica no histórico permanente. Não inclua dados pessoais ou de saúde."
                                        error={errors.base_unit_reason}
                                    >
                                        <Input
                                            id="base_unit_reason"
                                            name="base_unit_reason"
                                            required
                                            maxLength={1000}
                                            aria-invalid={
                                                !!errors.base_unit_reason
                                            }
                                            aria-describedby="base_unit_reason-hint base_unit_reason-error"
                                            className="h-11"
                                        />
                                    </Field>
                                )}

                                <fieldset className="grid gap-4">
                                    <legend className="mb-2 text-sm font-medium">
                                        Registro no conselho (opcional)
                                    </legend>
                                    <div className="grid gap-4 sm:grid-cols-[8rem_1fr_6rem]">
                                        <Field
                                            id="council"
                                            label="Conselho"
                                            error={errors.council}
                                        >
                                            <Input
                                                id="council"
                                                name="council"
                                                maxLength={10}
                                                placeholder="CRM"
                                                defaultValue={
                                                    professional?.council ?? ''
                                                }
                                                aria-invalid={!!errors.council}
                                                aria-describedby={
                                                    errors.council
                                                        ? 'council-error'
                                                        : undefined
                                                }
                                                className="h-11 uppercase"
                                            />
                                        </Field>
                                        <Field
                                            id="council_number"
                                            label="Número"
                                            error={errors.council_number}
                                        >
                                            <Input
                                                id="council_number"
                                                name="council_number"
                                                maxLength={20}
                                                defaultValue={
                                                    professional?.council_number ??
                                                    ''
                                                }
                                                aria-invalid={
                                                    !!errors.council_number
                                                }
                                                aria-describedby={
                                                    errors.council_number
                                                        ? 'council_number-error'
                                                        : undefined
                                                }
                                                className="h-11"
                                            />
                                        </Field>
                                        <Field
                                            id="council_uf"
                                            label="UF"
                                            error={errors.council_uf}
                                        >
                                            <select
                                                id="council_uf"
                                                name="council_uf"
                                                defaultValue={
                                                    professional?.council_uf ??
                                                    ''
                                                }
                                                aria-invalid={
                                                    !!errors.council_uf
                                                }
                                                aria-describedby={
                                                    errors.council_uf
                                                        ? 'council_uf-error'
                                                        : undefined
                                                }
                                                className={selectClassName}
                                            >
                                                <option value="">—</option>
                                                {ufs.map((uf) => (
                                                    <option key={uf} value={uf}>
                                                        {uf}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                    </div>
                                </fieldset>

                                <Field
                                    id="external_id"
                                    label="Identificador externo (opcional)"
                                    hint="Matrícula ou código no sistema de origem, usado na importação."
                                    error={errors.external_id}
                                >
                                    <Input
                                        id="external_id"
                                        name="external_id"
                                        maxLength={100}
                                        defaultValue={
                                            professional?.external_id ?? ''
                                        }
                                        aria-invalid={!!errors.external_id}
                                        aria-describedby="external_id-hint external_id-error"
                                        className="h-11"
                                    />
                                </Field>

                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Field
                                        id="email"
                                        label="E-mail (opcional)"
                                        error={errors.email}
                                    >
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            autoComplete="off"
                                            maxLength={255}
                                            defaultValue={
                                                professional?.email ?? ''
                                            }
                                            aria-invalid={!!errors.email}
                                            aria-describedby={
                                                errors.email
                                                    ? 'email-error'
                                                    : undefined
                                            }
                                            className="h-11"
                                        />
                                    </Field>
                                    <Field
                                        id="phone"
                                        label="Telefone (opcional)"
                                        error={errors.phone}
                                    >
                                        <Input
                                            id="phone"
                                            name="phone"
                                            type="tel"
                                            autoComplete="off"
                                            maxLength={30}
                                            defaultValue={
                                                professional?.phone ?? ''
                                            }
                                            aria-invalid={!!errors.phone}
                                            aria-describedby={
                                                errors.phone
                                                    ? 'phone-error'
                                                    : undefined
                                            }
                                            className="h-11"
                                        />
                                    </Field>
                                    <Field
                                        id="cpf"
                                        label="CPF (opcional)"
                                        hint="Para assinar prescrições com certificado digital: o titular precisa ter este CPF."
                                        error={errors.cpf}
                                    >
                                        <Input
                                            id="cpf"
                                            name="cpf"
                                            inputMode="numeric"
                                            autoComplete="off"
                                            maxLength={14}
                                            defaultValue={
                                                professional?.cpf ?? ''
                                            }
                                            aria-invalid={!!errors.cpf}
                                            aria-describedby={
                                                errors.cpf
                                                    ? 'cpf-error'
                                                    : undefined
                                            }
                                            className="h-11"
                                        />
                                    </Field>
                                </div>
                            </div>

                            <div className="flex flex-wrap justify-end gap-3 border-t bg-muted/40 px-5 py-4 sm:px-6">
                                <Button
                                    variant="ghost"
                                    asChild
                                    className="min-h-11"
                                >
                                    <Link
                                        href={
                                            professional
                                                ? show(professional.id)
                                                : index()
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
                                    {professional
                                        ? 'Salvar alterações'
                                        : 'Cadastrar profissional'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
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
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

ProfessionalForm.layout = {
    breadcrumbs: [{ title: 'Profissionais', href: index() }],
};
