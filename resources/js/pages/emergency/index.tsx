import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Siren, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import EmergencyController from '@/actions/App/Http/Controllers/EmergencyController';
import InputError from '@/components/input-error';
import { ModalityFields } from '@/components/modality-fields';
import { Page, PageHeader } from '@/components/page';
import { sexLabels } from '@/components/patient-display';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/emergency';
import { index as patients } from '@/routes/patients';
import { show as showVisit } from '@/routes/visits';

export type Color = 'red' | 'orange' | 'yellow' | 'green' | 'blue';

type Level = {
    level: number;
    name: string;
    color: Color;
    target_minutes: number;
};

type Vitals = {
    systolic: number | null;
    diastolic: number | null;
    heart_rate: number | null;
    respiratory_rate: number | null;
    temperature: string | null;
    spo2: number | null;
    pain: number | null;
    glucose: number | null;
};

type Episode = {
    id: number;
    patient: string | null;
    unidentified: boolean;
    identification: string | null;
    arrivedAt: string;
    waitingMinutes: number;
    level: number | null;
    overdue: boolean;
    classifications: number;
    latest: (Vitals & { id: number; level: number; complaint: string }) | null;
    status: 'waiting' | 'in_care' | 'admission_requested';
    attendedBy: string | null;
    myVisitId: number | null;
};

export const levelClasses: Record<Color, string> = {
    red: 'border-red-600/40 bg-red-600/10 text-red-800 dark:text-red-300',
    orange: 'border-orange-600/40 bg-orange-500/10 text-orange-800 dark:text-orange-300',
    yellow: 'border-amber-500/50 bg-amber-400/15 text-amber-900 dark:text-amber-200',
    green: 'border-emerald-600/40 bg-emerald-600/10 text-emerald-800 dark:text-emerald-300',
    blue: 'border-sky-600/40 bg-sky-600/10 text-sky-800 dark:text-sky-300',
};

const vitals: { field: keyof Vitals; label: string; step?: string }[] = [
    { field: 'systolic', label: 'PA sistólica (mmHg)' },
    { field: 'diastolic', label: 'PA diastólica (mmHg)' },
    { field: 'heart_rate', label: 'Frequência cardíaca (bpm)' },
    { field: 'respiratory_rate', label: 'Frequência respiratória (irpm)' },
    { field: 'temperature', label: 'Temperatura (°C)', step: '0.1' },
    { field: 'spo2', label: 'Saturação de O₂ (%)' },
    { field: 'pain', label: 'Dor (0 a 10)' },
    { field: 'glucose', label: 'Glicemia capilar (mg/dL)' },
];

const textareaClassName =
    'min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

export function duration(minutes: number): string {
    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
}

/**
 * Emergency department (step 4.2): the reception registers arrivals, the professionals of the unit
 * classify the risk. The queue shows who waits for classification first, then by level and waiting time.
 */
export default function Emergency({
    units,
    unit,
    canArrive,
    insurers,
    canTriage,
    canCall,
    levels,
    patient,
    episodes,
}: {
    units: { id: number; name: string }[];
    unit: { id: number; name: string };
    canArrive: boolean;
    insurers: { id: number; name: string }[];
    canTriage: boolean;
    canCall: boolean;
    levels: Level[];
    patient: { id: number; name: string } | null;
    episodes: Episode[];
}) {
    const byLevel = new Map(levels.map((level) => [level.level, level]));
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Urgência" />
            <Page>
                <PageHeader
                    eyebrow={unit.name}
                    title="Urgência"
                    description="Fila da urgência: primeiro quem aguarda classificação, depois por nível de risco e tempo de espera. O nível aparece em texto e em cor."
                />
                {units.length > 1 && (
                    <div className="mb-6 grid max-w-sm gap-2">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            value={unit.id}
                            onChange={(event) =>
                                router.get(
                                    index({
                                        query: { unit: event.target.value },
                                    }).url,
                                )
                            }
                            className={selectClassName}
                        >
                            {units.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                {canArrive && (
                    <Arrival
                        unitId={unit.id}
                        patient={patient}
                        insurers={insurers}
                    />
                )}
                <InputError message={errors.call} className="mb-4" />

                <section aria-labelledby="queue-heading" className="grid gap-3">
                    <h2
                        id="queue-heading"
                        className="flex items-center justify-between gap-3 font-semibold"
                    >
                        Fila
                        <span className="text-sm font-normal text-muted-foreground tabular-nums">
                            {episodes.length}{' '}
                            {episodes.length === 1 ? 'paciente' : 'pacientes'}
                        </span>
                    </h2>
                    {episodes.length === 0 ? (
                        <div className="rounded-xl border bg-card px-5 py-12 text-center">
                            <Siren
                                className="mx-auto mb-3 size-6 text-primary"
                                aria-hidden="true"
                            />
                            <p className="font-medium">Ninguém aguardando</p>
                        </div>
                    ) : (
                        <ul className="grid gap-3">
                            {episodes.map((episode) => (
                                <EpisodeCard
                                    key={episode.id}
                                    episode={episode}
                                    level={
                                        episode.level === null
                                            ? null
                                            : (byLevel.get(episode.level) ??
                                              null)
                                    }
                                    levels={levels}
                                    canTriage={canTriage}
                                    canCall={canCall}
                                    canArrive={canArrive}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}

function Arrival({
    unitId,
    patient,
    insurers,
}: {
    unitId: number;
    patient: { id: number; name: string } | null;
    insurers: { id: number; name: string }[];
}) {
    return (
        <section
            aria-labelledby="arrival-heading"
            className="mb-8 grid gap-4 rounded-xl border bg-card p-5 sm:p-6"
        >
            <h2 id="arrival-heading" className="font-semibold">
                Registrar chegada
            </h2>
            {patient === null ? (
                <p className="text-sm text-muted-foreground">
                    Paciente com cadastro: busque em{' '}
                    <Link
                        href={patients()}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Pacientes
                    </Link>{' '}
                    e use “Chegada na urgência” na ficha.
                </p>
            ) : (
                <Form
                    {...EmergencyController.arrive.form(unitId)}
                    className="grid gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="patient_id"
                                value={patient.id}
                            />
                            <p className="text-sm">
                                Paciente:{' '}
                                <span className="font-medium">
                                    {patient.name}
                                </span>
                            </p>
                            <ModalityFields
                                idPrefix="arrival"
                                insurers={insurers}
                                errors={errors}
                            />
                            <InputError message={errors.patient_id} />
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11 justify-self-start"
                            >
                                Registrar chegada
                            </Button>
                        </>
                    )}
                </Form>
            )}

            <details className="rounded-lg border px-4 py-2">
                <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                    Paciente não identificado
                </summary>
                <Form
                    {...EmergencyController.arrive.form(unitId)}
                    errorBag="unidentified"
                    resetOnSuccess
                    className="grid gap-4 py-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="unidentified"
                                value="1"
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field id="sex" label="Sexo" error={errors.sex}>
                                    <select
                                        id="sex"
                                        name="sex"
                                        required
                                        defaultValue=""
                                        className={selectClassName}
                                    >
                                        <option value="" disabled>
                                            Selecione
                                        </option>
                                        {Object.entries(sexLabels).map(
                                            ([value, text]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {text}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </Field>
                                <Field
                                    id="apparent_age"
                                    label="Idade aparente (anos)"
                                    error={errors.apparent_age}
                                >
                                    <Input
                                        id="apparent_age"
                                        name="apparent_age"
                                        type="number"
                                        min={0}
                                        max={120}
                                        inputMode="numeric"
                                        className="h-11"
                                    />
                                </Field>
                            </div>
                            <Field
                                id="description"
                                label="Descrição (roupas, sinais, onde foi encontrado)"
                                error={errors.description}
                            >
                                <textarea
                                    id="description"
                                    name="description"
                                    required
                                    maxLength={500}
                                    className={textareaClassName}
                                />
                            </Field>
                            <ModalityFields
                                idPrefix="unidentified"
                                insurers={insurers}
                                errors={errors}
                            />
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11 justify-self-start"
                            >
                                Registrar chegada sem identificação
                            </Button>
                        </>
                    )}
                </Form>
            </details>
        </section>
    );
}

function LevelBadge({ level }: { level: Level | null }) {
    return level === null ? (
        <span className="rounded-full border border-dashed px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
            Aguardando classificação
        </span>
    ) : (
        <span
            className={`rounded-full border px-2.5 py-0.5 text-xs font-semibold ${levelClasses[level.color]}`}
        >
            {level.level} · {level.name}
        </span>
    );
}

function EpisodeCard({
    episode,
    level,
    levels,
    canTriage,
    canCall,
    canArrive,
}: {
    episode: Episode;
    level: Level | null;
    levels: Level[];
    canTriage: boolean;
    canCall: boolean;
    canArrive: boolean;
}) {
    const name = episode.unidentified
        ? 'Não identificado'
        : (episode.patient ?? 'Paciente');
    const latest = episode.latest;

    return (
        <li
            className={`grid gap-3 rounded-xl border bg-card p-4 text-sm ${episode.overdue ? 'border-destructive ring-1 ring-destructive' : ''}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <span className="grid gap-0.5">
                    <span className="font-semibold">{name}</span>
                    {episode.identification && (
                        <span className="text-muted-foreground">
                            {episode.identification}
                        </span>
                    )}
                    <span className="text-muted-foreground tabular-nums">
                        Chegou às {episode.arrivedAt} ·{' '}
                        {episode.status === 'waiting'
                            ? 'espera de'
                            : 'na urgência há'}{' '}
                        {duration(episode.waitingMinutes)}
                    </span>
                </span>
                <LevelBadge level={level} />
            </div>
            {episode.overdue && level && (
                <p className="flex items-center gap-2 font-medium text-destructive">
                    <TriangleAlert className="size-4" aria-hidden="true" />
                    Tempo-alvo de {duration(level.target_minutes)} estourado
                </p>
            )}
            {latest && (
                <div className="grid gap-1 rounded-lg bg-muted/50 px-3 py-2">
                    <p>
                        <span className="text-muted-foreground">
                            Queixa principal:
                        </span>{' '}
                        {latest.complaint}
                    </p>
                    <p className="text-muted-foreground">
                        {vitals
                            .filter(({ field }) => latest[field] !== null)
                            .map(
                                ({ field, label }) =>
                                    `${label}: ${latest[field]}`,
                            )
                            .join(' · ') || 'Sinais vitais não informados'}
                    </p>
                    {episode.classifications > 1 && (
                        <p className="text-muted-foreground">
                            Reclassificado ({episode.classifications}{' '}
                            classificações)
                        </p>
                    )}
                </div>
            )}
            {episode.status !== 'waiting' && (
                <p className="font-medium">
                    {episode.status === 'in_care'
                        ? `Em atendimento${episode.attendedBy ? ` com ${episode.attendedBy}` : ''}`
                        : 'Aguardando internação'}
                </p>
            )}
            <div className="flex flex-wrap items-start gap-2">
                {episode.myVisitId !== null && (
                    <Button asChild className="min-h-11">
                        <Link href={showVisit(episode.myVisitId)}>
                            Continuar atendimento
                        </Link>
                    </Button>
                )}
                {episode.status === 'waiting' &&
                    canCall &&
                    episode.level !== null && (
                        <Form {...EmergencyController.call.form(episode.id)}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    aria-label={`Chamar e atender: ${name}, chegada às ${episode.arrivedAt}`}
                                    className="min-h-11"
                                >
                                    Chamar e atender
                                </Button>
                            )}
                        </Form>
                    )}
                {episode.status === 'waiting' && canTriage && (
                    // Remounted after each classification: closed again, with the new values as defaults.
                    <details
                        key={episode.latest?.id ?? 0}
                        className="basis-full"
                    >
                        <summary
                            aria-label={`${latest ? 'Reclassificar' : 'Classificar'}: ${name}, chegada às ${episode.arrivedAt}`}
                            className="inline-flex min-h-11 cursor-pointer items-center rounded-md border px-3 font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {latest ? 'Reclassificar' : 'Classificar'}
                        </summary>
                        <TriageForm episode={episode} levels={levels} />
                    </details>
                )}
                {episode.status === 'waiting' && (canTriage || canArrive) && (
                    <details className="basis-full">
                        <summary
                            aria-label={`Registrar evasão: ${name}, chegada às ${episode.arrivedAt}`}
                            className="inline-flex min-h-11 cursor-pointer items-center rounded-md px-3 text-muted-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Registrar evasão
                        </summary>
                        <Form
                            {...EmergencyController.evade.form(episode.id)}
                            options={{ preserveScroll: true }}
                            className="mt-2 flex flex-wrap items-center gap-3"
                        >
                            {({ processing }) => (
                                <>
                                    <p className="text-muted-foreground">
                                        O paciente saiu sem ser atendido. O
                                        horário e quem registrou ficam
                                        guardados.
                                    </p>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        Confirmar evasão
                                    </Button>
                                </>
                            )}
                        </Form>
                    </details>
                )}
            </div>
        </li>
    );
}

function TriageForm({
    episode,
    levels,
}: {
    episode: Episode;
    levels: Level[];
}) {
    const id = (field: string) => `${field}-${episode.id}`;

    return (
        <Form
            {...EmergencyController.triage.form(episode.id)}
            errorBag={`triage-${episode.id}`}
            options={{ preserveScroll: true }}
            className="mt-3 grid gap-4"
        >
            {({ processing, errors }) => (
                <>
                    {episode.latest && (
                        <input
                            type="hidden"
                            name="previous_id"
                            value={episode.latest.id}
                        />
                    )}
                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">
                            Nível de risco
                        </legend>
                        <div className="flex flex-wrap gap-2">
                            {levels.map((level) => (
                                <label
                                    key={level.level}
                                    className={`flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border px-3 has-[:checked]:ring-2 has-[:checked]:ring-ring ${levelClasses[level.color]}`}
                                >
                                    <input
                                        type="radio"
                                        name="level"
                                        value={level.level}
                                        required
                                        defaultChecked={
                                            episode.latest?.level ===
                                            level.level
                                        }
                                        className="size-4"
                                    />
                                    {level.level} · {level.name}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.level} />
                    </fieldset>
                    <Field
                        id={id('complaint')}
                        label="Queixa principal"
                        error={errors.complaint}
                    >
                        <textarea
                            id={id('complaint')}
                            name="complaint"
                            required
                            maxLength={500}
                            defaultValue={episode.latest?.complaint ?? ''}
                            className={textareaClassName}
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        {vitals.map(({ field, label, step }) => (
                            <Field
                                key={field}
                                id={id(field)}
                                label={label}
                                error={errors[field]}
                            >
                                <Input
                                    id={id(field)}
                                    name={field}
                                    type="number"
                                    step={step ?? '1'}
                                    inputMode="decimal"
                                    className="h-11"
                                />
                            </Field>
                        ))}
                    </div>
                    <Button
                        type="submit"
                        disabled={processing}
                        className="min-h-11 justify-self-start"
                    >
                        Registrar classificação
                    </Button>
                </>
            )}
        </Form>
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

Emergency.layout = {
    breadcrumbs: [{ title: 'Urgência', href: index() }],
};
