import { Form } from '@inertiajs/react';
import { BadgeCheck, Plus } from 'lucide-react';
import { useState } from 'react';
import QualificationController from '@/actions/App/Http/Controllers/QualificationController';
import InputError from '@/components/input-error';
import { WarningBadge } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export type Qualification = {
    id: number;
    unit: string | null;
    function: string | null;
    sectors: string[];
    status: 'pending' | 'approved' | 'suspended' | 'revoked';
    validFrom: string;
    validUntil: string | null;
    canDecide: boolean;
};

export type QualificationOptions = {
    units: {
        id: number;
        name: string;
        sectors: { id: number; name: string }[];
    }[];
    functions: { id: number; name: string }[];
};

const statusLabels: Record<Qualification['status'], string> = {
    pending: 'Pendente',
    approved: 'Aprovada',
    suspended: 'Suspensa',
    revoked: 'Revogada',
};

const selectClassName =
    'h-11 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-invalid:border-destructive';

/** Dates arrive as YYYY-MM-DD (unit-local); format without a timezone shift. */
function formatDate(date: string) {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

export function QualificationsSection({
    professionalId,
    qualifications,
    options,
}: {
    professionalId: number;
    qualifications: Qualification[];
    options: QualificationOptions | null;
}) {
    return (
        <section
            aria-labelledby="qualifications-heading"
            className="overflow-hidden rounded-lg border bg-card"
        >
            <header className="border-b px-5 py-4">
                <h2
                    id="qualifications-heading"
                    className="flex items-center gap-2 font-semibold tracking-tight"
                >
                    <BadgeCheck
                        className="size-4 text-primary"
                        aria-hidden="true"
                    />
                    Habilitações
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Cada unidade aprova as próprias habilitações. Aprovação em
                    uma unidade não vale em outra.
                </p>
            </header>

            {qualifications.length === 0 ? (
                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                    Nenhuma habilitação. Sem habilitação aprovada, o
                    profissional não pode assumir plantões.
                </p>
            ) : (
                <ul className="divide-y">
                    {qualifications.map((qualification) => (
                        <li
                            key={qualification.id}
                            className="grid gap-3 px-5 py-4 text-sm md:grid-cols-[1fr_auto]"
                        >
                            <div className="space-y-1">
                                <p className="font-medium">
                                    {qualification.function} ·{' '}
                                    {qualification.unit}
                                </p>
                                <p className="text-muted-foreground">
                                    Setores: {qualification.sectors.join(', ')}
                                </p>
                                <p className="text-muted-foreground">
                                    Validade:{' '}
                                    {formatDate(qualification.validFrom)} a{' '}
                                    {qualification.validUntil
                                        ? formatDate(qualification.validUntil)
                                        : 'sem data final'}
                                </p>
                                <p>
                                    {qualification.status === 'approved' ? (
                                        <span className="font-medium">
                                            Aprovada
                                        </span>
                                    ) : (
                                        <WarningBadge>
                                            {statusLabels[qualification.status]}
                                        </WarningBadge>
                                    )}
                                </p>
                            </div>
                            {qualification.canDecide &&
                                qualification.status !== 'revoked' && (
                                    // Remount on status change so the decision select resets to the first option.
                                    <DecisionForms
                                        key={qualification.status}
                                        qualification={qualification}
                                    />
                                )}
                        </li>
                    ))}
                </ul>
            )}

            {options && (
                <RequestForm
                    professionalId={professionalId}
                    options={options}
                />
            )}
        </section>
    );
}

function DecisionForms({ qualification }: { qualification: Qualification }) {
    const bag = `qualification-${qualification.id}`;
    const id = `q-${qualification.id}`;

    return (
        <div className="grid gap-3 md:w-80">
            {qualification.status !== 'approved' && (
                <Form
                    {...QualificationController.transition.form(
                        qualification.id,
                    )}
                    errorBag={bag}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="status"
                                value="approved"
                            />
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                                aria-label={`Aprovar habilitação ${qualification.function} em ${qualification.unit}`}
                            >
                                {processing && <Spinner />}
                                {qualification.status === 'suspended'
                                    ? 'Reativar'
                                    : 'Aprovar'}
                            </Button>
                        </>
                    )}
                </Form>
            )}
            <Form
                {...QualificationController.transition.form(qualification.id)}
                errorBag={bag}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="grid gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <Label htmlFor={`${id}-status`}>Outra decisão</Label>
                        <select
                            id={`${id}-status`}
                            name="status"
                            className={selectClassName}
                        >
                            {qualification.status === 'approved' && (
                                <option value="suspended">Suspender</option>
                            )}
                            <option value="revoked">
                                Revogar (definitivo)
                            </option>
                        </select>
                        <Label htmlFor={`${id}-reason`}>Motivo</Label>
                        <Input
                            id={`${id}-reason`}
                            name="reason"
                            required
                            maxLength={1000}
                            aria-invalid={!!errors.reason}
                            aria-describedby={
                                errors.reason ? `${id}-reason-error` : undefined
                            }
                            className="h-11"
                        />
                        <InputError
                            id={`${id}-reason-error`}
                            message={errors.reason ?? errors.status}
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            {processing && <Spinner />}
                            Registrar decisão
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

function RequestForm({
    professionalId,
    options,
}: {
    professionalId: number;
    options: QualificationOptions;
}) {
    const [unitId, setUnitId] = useState('');
    const sectors =
        options.units.find((unit) => String(unit.id) === unitId)?.sectors ?? [];

    return (
        <Form
            {...QualificationController.store.form(professionalId)}
            errorBag="qualification"
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={() => setUnitId('')}
            className="grid gap-4 border-t bg-muted/40 px-5 py-5"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-semibold tracking-tight">
                        Solicitar habilitação
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="q-unit">Unidade</Label>
                            <select
                                id="q-unit"
                                name="unit_id"
                                required
                                value={unitId}
                                onChange={(event) =>
                                    setUnitId(event.target.value)
                                }
                                aria-invalid={!!errors.unit_id}
                                aria-describedby={
                                    errors.unit_id ? 'q-unit-error' : undefined
                                }
                                className={selectClassName}
                            >
                                <option value="" disabled>
                                    Selecione
                                </option>
                                {options.units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.name}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                id="q-unit-error"
                                message={errors.unit_id}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="q-function">Função</Label>
                            <select
                                id="q-function"
                                name="job_function_id"
                                required
                                defaultValue=""
                                aria-invalid={!!errors.job_function_id}
                                aria-describedby={
                                    errors.job_function_id
                                        ? 'q-function-error'
                                        : undefined
                                }
                                className={selectClassName}
                            >
                                <option value="" disabled>
                                    {options.functions.length === 0
                                        ? 'Nenhuma função cadastrada'
                                        : 'Selecione'}
                                </option>
                                {options.functions.map((jobFunction) => (
                                    <option
                                        key={jobFunction.id}
                                        value={jobFunction.id}
                                    >
                                        {jobFunction.name}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                id="q-function-error"
                                message={errors.job_function_id}
                            />
                        </div>
                    </div>

                    <fieldset
                        aria-describedby={
                            errors.sector_ids ? 'q-sectors-error' : undefined
                        }
                    >
                        <legend className="mb-2 text-sm font-medium">
                            Setores autorizados
                        </legend>
                        {unitId === '' ? (
                            <p className="text-sm text-muted-foreground">
                                Escolha a unidade para ver os setores.
                            </p>
                        ) : sectors.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Esta unidade não tem setores cadastrados.
                            </p>
                        ) : (
                            <div className="flex flex-wrap gap-x-6 gap-y-2">
                                {sectors.map((sector) => (
                                    <label
                                        key={sector.id}
                                        className="flex min-h-11 items-center gap-2 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="sector_ids[]"
                                            value={sector.id}
                                            className="size-4 accent-primary"
                                        />
                                        {sector.name}
                                    </label>
                                ))}
                            </div>
                        )}
                        <InputError
                            id="q-sectors-error"
                            message={errors.sector_ids}
                        />
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="q-from">Início da validade</Label>
                            <Input
                                id="q-from"
                                name="valid_from"
                                type="date"
                                required
                                aria-invalid={!!errors.valid_from}
                                aria-describedby={
                                    errors.valid_from
                                        ? 'q-from-error'
                                        : undefined
                                }
                                className="h-11 bg-card"
                            />
                            <InputError
                                id="q-from-error"
                                message={errors.valid_from}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="q-until">
                                Fim da validade (opcional)
                            </Label>
                            <Input
                                id="q-until"
                                name="valid_until"
                                type="date"
                                aria-invalid={!!errors.valid_until}
                                aria-describedby={
                                    errors.valid_until
                                        ? 'q-until-error'
                                        : undefined
                                }
                                className="h-11 bg-card"
                            />
                            <InputError
                                id="q-until-error"
                                message={errors.valid_until}
                            />
                        </div>
                    </div>

                    <Button
                        type="submit"
                        disabled={processing}
                        className="min-h-11 justify-self-start"
                    >
                        {processing ? <Spinner /> : <Plus aria-hidden="true" />}
                        Solicitar
                    </Button>
                </>
            )}
        </Form>
    );
}
