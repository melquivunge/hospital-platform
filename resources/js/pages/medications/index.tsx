import { Form, Head, Link } from '@inertiajs/react';
import { FileUp, Pill, Plus, Search } from 'lucide-react';
import type { ReactNode } from 'react';
import MedicationController from '@/actions/App/Http/Controllers/MedicationController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create as newImport } from '@/routes/imports';
import { index } from '@/routes/medications';

type Medication = {
    id: number;
    active_ingredient: string;
    form: string;
    strength: string;
    dose_unit: string;
    stock_unit: string;
    routes: string[];
    controlled: boolean;
    high_alert: boolean;
    max_single_dose: string | null;
    max_daily_dose: string | null;
    active: boolean;
    updated_at: string;
};

type Interaction = {
    id: number;
    ingredient_a: string;
    ingredient_b: string;
    severity: string;
    note: string;
    active: boolean;
    updated_at: string;
};

type Options = {
    doseUnits: string[];
    stockUnits: string[];
    routes: Record<string, string>;
    severities: Record<string, string>;
};

/** A decimal from the database ("500.000") shown without trailing zeros. */
const dose = (value: string | null) =>
    value === null ? '' : String(Number(value));

/**
 * Network medication catalog and safety rules (step 4b.1a): maximum doses per presentation and
 * interactions between active ingredients. Alerts in prescriptions come only from what is registered here.
 */
export default function MedicationsIndex({
    q,
    total,
    limit,
    medications,
    interactions,
    totalInteractions,
    ingredients,
    ...options
}: {
    q: string;
    total: number;
    totalInteractions: number;
    limit: number;
    medications: Medication[];
    interactions: Interaction[];
    ingredients: string[];
} & Options) {
    return (
        <>
            <Head title="Medicamentos" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Rede"
                    title="Medicamentos"
                    description="Catálogo da rede e regras de segurança. A prescrição só alerta sobre dose máxima e interação do que estiver cadastrado aqui; um medicamento sem regra não gera alerta."
                />
                <Button asChild variant="outline" className="mb-6 min-h-11">
                    <Link href={newImport()}>
                        <FileUp aria-hidden="true" />
                        Importar catálogo ou interações por CSV
                    </Link>
                </Button>

                <Form
                    {...index.form()}
                    options={{ preserveScroll: true }}
                    className="mb-6 flex flex-wrap items-end gap-3"
                >
                    <div className="grid min-w-0 flex-1 gap-2">
                        <Label htmlFor="q">Buscar por princípio ativo</Label>
                        <Input
                            id="q"
                            name="q"
                            defaultValue={q}
                            maxLength={255}
                            className="h-11"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        className="min-h-11"
                    >
                        <Search aria-hidden="true" />
                        Buscar
                    </Button>
                </Form>

                <section
                    aria-labelledby="medications-heading"
                    className="mb-8 overflow-hidden rounded-lg border bg-card"
                >
                    <header className="flex items-center justify-between gap-3 border-b px-5 py-4">
                        <h2
                            id="medications-heading"
                            className="flex items-center gap-2 font-semibold tracking-tight"
                        >
                            <Pill
                                className="size-4 text-primary"
                                aria-hidden="true"
                            />
                            Apresentações
                        </h2>
                        <span className="text-sm text-muted-foreground tabular-nums">
                            {medications.length < total
                                ? `${medications.length} de ${total}`
                                : total}
                        </span>
                    </header>
                    {medications.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            {q
                                ? 'Nenhum medicamento encontrado.'
                                : 'Nenhum medicamento cadastrado. Adicione o primeiro abaixo.'}
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {medications.map((medication) => (
                                <li
                                    key={`${medication.id}-${medication.updated_at}`}
                                    className="px-5 py-3"
                                >
                                    <MedicationItem
                                        medication={medication}
                                        options={options}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                    {medications.length === limit && (
                        <p className="border-t px-5 py-3 text-sm text-muted-foreground">
                            Mostrando os primeiros {limit}. Use a busca para
                            achar os demais.
                        </p>
                    )}
                    <Form
                        {...MedicationController.store.form()}
                        errorBag="newMedication"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-3 border-t bg-muted/40 px-5 py-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <p className="text-sm font-medium">
                                    Nova apresentação
                                </p>
                                <MedicationFields
                                    id="new"
                                    options={options}
                                    errors={errors}
                                />
                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <Plus aria-hidden="true" />
                                        )}
                                        Adicionar
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <section
                    aria-labelledby="interactions-heading"
                    className="overflow-hidden rounded-lg border bg-card"
                >
                    <header className="flex items-center justify-between gap-3 border-b px-5 py-4">
                        <h2
                            id="interactions-heading"
                            className="font-semibold tracking-tight"
                        >
                            Interações entre princípios ativos
                        </h2>
                        <span className="text-sm text-muted-foreground tabular-nums">
                            {interactions.length < totalInteractions
                                ? `${interactions.length} de ${totalInteractions}`
                                : totalInteractions}
                        </span>
                    </header>
                    {interactions.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            Nenhuma interação cadastrada.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {interactions.map((interaction) => (
                                <li
                                    key={`${interaction.id}-${interaction.updated_at}`}
                                    className="px-5 py-3"
                                >
                                    <InteractionItem
                                        interaction={interaction}
                                        severities={options.severities}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                    <Form
                        {...MedicationController.storeInteraction.form()}
                        errorBag="newInteraction"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-3 border-t bg-muted/40 px-5 py-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <p className="text-sm font-medium">
                                    Nova interação
                                </p>
                                <datalist id="ingredients">
                                    {ingredients.map((ingredient) => (
                                        <option
                                            key={ingredient}
                                            value={ingredient}
                                        />
                                    ))}
                                </datalist>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {(
                                        [
                                            'ingredient_a',
                                            'ingredient_b',
                                        ] as const
                                    ).map((field, position) => (
                                        <div
                                            key={field}
                                            className="grid content-start gap-2"
                                        >
                                            <Label htmlFor={`new-${field}`}>
                                                {position === 0
                                                    ? 'Princípio ativo'
                                                    : 'Com o princípio ativo'}
                                            </Label>
                                            <Input
                                                id={`new-${field}`}
                                                name={field}
                                                list="ingredients"
                                                required
                                                maxLength={255}
                                                className="h-11 bg-card"
                                            />
                                            <InputError
                                                message={errors[field]}
                                            />
                                        </div>
                                    ))}
                                </div>
                                <InteractionFields
                                    id="new-interaction"
                                    severities={options.severities}
                                    errors={errors}
                                />
                                <div className="flex justify-end">
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <Plus aria-hidden="true" />
                                        )}
                                        Adicionar
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>
            </Page>
        </>
    );
}

function MedicationItem({
    medication,
    options,
}: {
    medication: Medication;
    options: Options;
}) {
    const id = `medication-${medication.id}`;
    const name = `${medication.active_ingredient} ${medication.strength} (${medication.form})`;
    const limits = [
        medication.max_single_dose &&
            `máx. ${dose(medication.max_single_dose)} ${medication.dose_unit} por vez`,
        medication.max_daily_dose &&
            `máx. ${dose(medication.max_daily_dose)} ${medication.dose_unit} por dia`,
    ].filter(Boolean);

    return (
        <details>
            <summary className="flex min-h-11 cursor-pointer flex-wrap items-center gap-x-3 gap-y-1">
                <span
                    className={
                        medication.active
                            ? 'font-medium'
                            : 'font-medium text-muted-foreground line-through'
                    }
                >
                    {name}
                </span>
                {medication.controlled && <Tag>Controlado</Tag>}
                {medication.high_alert && <Tag>Alto alerta</Tag>}
                {!medication.active && <Tag>Inativo</Tag>}
                <span className="text-sm text-muted-foreground">
                    {limits.length > 0 ? limits.join(' · ') : 'sem dose máxima'}
                </span>
            </summary>
            <Form
                {...MedicationController.update.form(medication.id)}
                errorBag={id}
                options={{ preserveScroll: true }}
                className="grid gap-3 py-3"
            >
                {({ processing, errors }) => (
                    <>
                        <MedicationFields
                            id={id}
                            options={options}
                            errors={errors}
                            medication={medication}
                        />
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <Check
                                name="active"
                                label="Ativo"
                                checked={medication.active}
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                aria-label={`Salvar ${name}`}
                                className="min-h-11"
                            >
                                Salvar
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </details>
    );
}

function MedicationFields({
    id,
    options,
    errors,
    medication,
}: {
    id: string;
    options: Options;
    errors: Record<string, string | undefined>;
    medication?: Medication;
}) {
    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    id={`${id}-ingredient`}
                    label="Princípio ativo (DCB)"
                    error={errors.active_ingredient}
                >
                    <Input
                        id={`${id}-ingredient`}
                        name="active_ingredient"
                        required
                        maxLength={255}
                        defaultValue={medication?.active_ingredient}
                        readOnly={medication !== undefined}
                        placeholder="Ex.: Dipirona"
                        className="h-11 bg-card"
                    />
                </Field>
                <Field id={`${id}-form`} label="Forma" error={errors.form}>
                    <Input
                        id={`${id}-form`}
                        name="form"
                        required
                        maxLength={100}
                        defaultValue={medication?.form}
                        placeholder="Ex.: Solução injetável"
                        className="h-11 bg-card"
                    />
                </Field>
                <Field
                    id={`${id}-strength`}
                    label="Concentração"
                    error={errors.strength}
                >
                    <Input
                        id={`${id}-strength`}
                        name="strength"
                        required
                        maxLength={100}
                        defaultValue={medication?.strength}
                        placeholder="Ex.: 500 mg/mL"
                        className="h-11 bg-card"
                    />
                </Field>
                <Field
                    id={`${id}-unit`}
                    label="Unidade de dose"
                    error={errors.dose_unit}
                >
                    <select
                        id={`${id}-unit`}
                        name="dose_unit"
                        required
                        defaultValue={medication?.dose_unit ?? ''}
                        className={selectClassName}
                    >
                        <option value="" disabled>
                            Selecione
                        </option>
                        {options.doseUnits.map((unit) => (
                            <option key={unit} value={unit}>
                                {unit}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field
                    id={`${id}-stock`}
                    label="Unidade de estoque"
                    error={errors.stock_unit}
                >
                    <select
                        id={`${id}-stock`}
                        name="stock_unit"
                        defaultValue={medication?.stock_unit ?? 'unidade'}
                        className={selectClassName}
                    >
                        {options.stockUnits.map((unit) => (
                            <option key={unit} value={unit}>
                                {unit}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field
                    id={`${id}-single`}
                    label="Dose máxima por administração (opcional)"
                    error={errors.max_single_dose}
                >
                    <Input
                        id={`${id}-single`}
                        name="max_single_dose"
                        type="number"
                        min="0"
                        step="any"
                        defaultValue={dose(medication?.max_single_dose ?? null)}
                        className="h-11 bg-card"
                    />
                </Field>
                <Field
                    id={`${id}-daily`}
                    label="Dose máxima por dia (opcional)"
                    error={errors.max_daily_dose}
                >
                    <Input
                        id={`${id}-daily`}
                        name="max_daily_dose"
                        type="number"
                        min="0"
                        step="any"
                        defaultValue={dose(medication?.max_daily_dose ?? null)}
                        className="h-11 bg-card"
                    />
                </Field>
            </div>
            <fieldset className="grid gap-1">
                <legend className="mb-1 text-sm font-medium">Vias</legend>
                <div className="flex flex-wrap gap-x-4">
                    {Object.entries(options.routes).map(([value, label]) => (
                        <label
                            key={value}
                            className="flex min-h-11 items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                name="routes[]"
                                value={value}
                                defaultChecked={medication?.routes.includes(
                                    value,
                                )}
                                className="size-4 accent-primary"
                            />
                            {label}
                        </label>
                    ))}
                </div>
                <InputError message={errors.routes ?? errors['routes.0']} />
            </fieldset>
            <div className="flex flex-wrap gap-x-6">
                <Check
                    name="controlled"
                    label="Controlado (Portaria 344/98)"
                    checked={medication?.controlled ?? false}
                />
                <Check
                    name="high_alert"
                    label="Alto alerta (dupla checagem)"
                    checked={medication?.high_alert ?? false}
                />
            </div>
        </>
    );
}

function InteractionItem({
    interaction,
    severities,
}: {
    interaction: Interaction;
    severities: Record<string, string>;
}) {
    const id = `interaction-${interaction.id}`;
    const pair = `${interaction.ingredient_a} + ${interaction.ingredient_b}`;

    return (
        <details>
            <summary className="flex min-h-11 cursor-pointer flex-wrap items-center gap-x-3 gap-y-1">
                <span
                    className={
                        interaction.active
                            ? 'font-medium'
                            : 'font-medium text-muted-foreground line-through'
                    }
                >
                    {pair}
                </span>
                <Tag>{severities[interaction.severity]}</Tag>
                {!interaction.active && <Tag>Inativa</Tag>}
            </summary>
            <Form
                {...MedicationController.updateInteraction.form(interaction.id)}
                errorBag={id}
                options={{ preserveScroll: true }}
                className="grid gap-3 py-3"
            >
                {({ processing, errors }) => (
                    <>
                        <InteractionFields
                            id={id}
                            severities={severities}
                            errors={errors}
                            interaction={interaction}
                        />
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <Check
                                name="active"
                                label="Ativa"
                                checked={interaction.active}
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                aria-label={`Salvar ${pair}`}
                                className="min-h-11"
                            >
                                Salvar
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </details>
    );
}

function InteractionFields({
    id,
    severities,
    errors,
    interaction,
}: {
    id: string;
    severities: Record<string, string>;
    errors: Record<string, string | undefined>;
    interaction?: Interaction;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-[12rem_1fr]">
            <Field
                id={`${id}-severity`}
                label="Gravidade"
                error={errors.severity}
            >
                <select
                    id={`${id}-severity`}
                    name="severity"
                    required
                    defaultValue={interaction?.severity ?? ''}
                    className={selectClassName}
                >
                    <option value="" disabled>
                        Selecione
                    </option>
                    {Object.entries(severities).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </Field>
            <Field
                id={`${id}-note`}
                label="Orientação ao prescritor"
                error={errors.note}
            >
                <Input
                    id={`${id}-note`}
                    name="note"
                    required
                    maxLength={500}
                    defaultValue={interaction?.note}
                    className="h-11 bg-card"
                />
            </Field>
        </div>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function Check({
    name,
    label,
    checked,
}: {
    name: string;
    label: string;
    checked: boolean;
}) {
    return (
        <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="hidden" name={name} value="0" />
            <input
                type="checkbox"
                name={name}
                value="1"
                defaultChecked={checked}
                className="size-4 accent-primary"
            />
            {label}
        </label>
    );
}

function Tag({ children }: { children: ReactNode }) {
    return (
        <span className="rounded-full border px-2 py-0.5 text-xs text-muted-foreground">
            {children}
        </span>
    );
}

MedicationsIndex.layout = {
    breadcrumbs: [{ title: 'Medicamentos', href: index() }],
};
