import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ShiftController from '@/actions/App/Http/Controllers/ShiftController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, show } from '@/routes/shifts';

type Option = { id: number; name: string };

type ProfessionalOption = Option & { registration: string | null };

type ShiftValues = {
    id: number;
    sector_id: number;
    job_function_id: number;
    professional_id: number | null;
    starts_at: string;
    ends_at: string;
    value: string | null;
};

const selectClassName =
    'h-11 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-invalid:border-destructive';

export default function ShiftForm({
    shift,
    unit,
    sectors,
    functions,
    professionals,
}: {
    shift: ShiftValues | null;
    unit: { id: number; name: string; timezone: string | null };
    sectors: Option[];
    functions: Option[];
    professionals: ProfessionalOption[];
}) {
    const title = shift ? 'Editar rascunho' : 'Novo plantão';

    return (
        <>
            <Head title={title} />
            <Page width="narrow">
                <PageHeader
                    eyebrow={unit.name}
                    title={title}
                    description={`Informe os horários no fuso da unidade (${unit.timezone?.replaceAll('_', ' ') ?? 'não informado'}). O plantão é salvo como rascunho; publique depois de revisar.`}
                />

                <Form
                    {...(shift
                        ? ShiftController.update.form(shift.id)
                        : ShiftController.store.form(unit.id))}
                    className="overflow-hidden rounded-lg border bg-card"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-6 p-5 sm:p-6">
                                <div className="grid gap-6 sm:grid-cols-2">
                                    <Select
                                        id="sector_id"
                                        label="Setor"
                                        options={sectors}
                                        defaultValue={shift?.sector_id}
                                        error={errors.sector_id}
                                        required
                                    />
                                    <Select
                                        id="job_function_id"
                                        label="Função"
                                        options={functions}
                                        defaultValue={shift?.job_function_id}
                                        error={errors.job_function_id}
                                        required
                                    />
                                </div>

                                <div className="grid gap-6 sm:grid-cols-2">
                                    <DateTime
                                        id="starts_at"
                                        label="Início"
                                        defaultValue={shift?.starts_at}
                                        error={errors.starts_at}
                                    />
                                    <DateTime
                                        id="ends_at"
                                        label="Fim"
                                        defaultValue={shift?.ends_at}
                                        error={errors.ends_at}
                                    />
                                </div>

                                <div className="grid gap-2 sm:max-w-56">
                                    <Label htmlFor="value">
                                        Valor (R$, opcional)
                                    </Label>
                                    <Input
                                        id="value"
                                        name="value"
                                        inputMode="decimal"
                                        placeholder="0,00"
                                        maxLength={10}
                                        defaultValue={shift?.value ?? ''}
                                        aria-invalid={!!errors.value}
                                        aria-describedby={
                                            errors.value
                                                ? 'value-error'
                                                : undefined
                                        }
                                        className="h-11 bg-card"
                                    />
                                    <InputError
                                        id="value-error"
                                        message={errors.value}
                                    />
                                </div>

                                <ProfessionalPicker
                                    professionals={professionals}
                                    defaultValue={shift?.professional_id}
                                    error={errors.professional_id}
                                />
                                <InputError message={errors.shift} />
                            </div>

                            <div className="flex flex-wrap justify-end gap-3 border-t bg-muted/40 px-5 py-4 sm:px-6">
                                <Button
                                    variant="ghost"
                                    asChild
                                    className="min-h-11"
                                >
                                    <Link
                                        href={
                                            shift
                                                ? show(shift.id)
                                                : index({
                                                      query: { unit: unit.id },
                                                  })
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
                                    Salvar rascunho
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

function Select({
    id,
    label,
    options,
    defaultValue,
    error,
    required,
}: {
    id: string;
    label: string;
    options: Option[];
    defaultValue?: number | null;
    error?: string;
    required?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                name={id}
                required={required}
                defaultValue={defaultValue ?? ''}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className={selectClassName}
            >
                <option value="" disabled={required}>
                    Selecione
                </option>
                {options.map((option) => (
                    <option key={option.id} value={option.id}>
                        {option.name}
                    </option>
                ))}
            </select>
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

function ProfessionalPicker({
    professionals,
    defaultValue,
    error,
}: {
    professionals: ProfessionalOption[];
    defaultValue?: number | null;
    error?: string;
}) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(String(defaultValue ?? ''));
    const needle = query.trim().toLocaleLowerCase('pt-BR');
    // The selected professional stays listed even when the search hides them.
    const visible = professionals.filter(
        (professional) =>
            String(professional.id) === selected ||
            `${professional.name} ${professional.registration ?? ''}`
                .toLocaleLowerCase('pt-BR')
                .includes(needle),
    );

    return (
        <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">Profissional</legend>
            <Label htmlFor="professional-search" className="sr-only">
                Buscar profissional por nome ou registro
            </Label>
            <Input
                id="professional-search"
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Buscar por nome ou registro"
                autoComplete="off"
                className="h-11 bg-card"
            />
            <Label htmlFor="professional_id" className="sr-only">
                Profissional
            </Label>
            <select
                id="professional_id"
                name="professional_id"
                value={selected}
                onChange={(event) => setSelected(event.target.value)}
                aria-invalid={!!error}
                aria-describedby={[
                    'professional_id-hint',
                    error && 'professional_id-error',
                ]
                    .filter(Boolean)
                    .join(' ')}
                className={selectClassName}
            >
                <option value="">Vaga aberta (sem profissional)</option>
                {visible.map((professional) => (
                    <option key={professional.id} value={professional.id}>
                        {professional.registration
                            ? `${professional.name} — ${professional.registration}`
                            : professional.name}
                    </option>
                ))}
            </select>
            <p
                id="professional_id-hint"
                className="text-xs text-muted-foreground"
                aria-live="polite"
            >
                {needle === ''
                    ? 'Lista quem tem habilitação nesta unidade. Elegibilidade e conflito de horário são verificados ao salvar e ao publicar.'
                    : `${visible.length} ${visible.length === 1 ? 'resultado' : 'resultados'} para a busca.`}
            </p>
            <InputError id="professional_id-error" message={error} />
        </fieldset>
    );
}

function DateTime({
    id,
    label,
    defaultValue,
    error,
}: {
    id: string;
    label: string;
    defaultValue?: string;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={id}
                type="datetime-local"
                required
                defaultValue={defaultValue}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className="h-11 bg-card"
            />
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

ShiftForm.layout = {
    breadcrumbs: [{ title: 'Escala', href: index() }],
};
