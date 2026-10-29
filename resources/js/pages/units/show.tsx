import { Form, Head, Link } from '@inertiajs/react';
import { LayoutList, MapPin, Pencil, Plus, TriangleAlert } from 'lucide-react';
import AttendancePointController from '@/actions/App/Http/Controllers/AttendancePointController';
import SectorController from '@/actions/App/Http/Controllers/SectorController';
import InputError from '@/components/input-error';
import { selectClassName } from '@/components/role-fields';
import { Page, PageHeader, WarningBadge } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit, index } from '@/routes/units';

type Unit = {
    id: number;
    name: string;
    uf: string | null;
    timezone: string | null;
    complete: boolean;
};

type Sector = { id: number; name: string; sus_bed_specialty: string | null };

type AttendancePoint = {
    id: number;
    name: string;
    latitude: string;
    longitude: string;
    radius_m: number;
};

export default function UnitShow({
    unit,
    sectors,
    canUpdate,
    canManageSectors,
    attendancePoints,
    bedSpecialties,
}: {
    unit: Unit;
    sectors: Sector[];
    bedSpecialties: { code: string; name: string }[];
    canUpdate: boolean;
    canManageSectors: boolean;
    attendancePoints: AttendancePoint[] | null;
}) {
    return (
        <>
            <Head title={unit.name} />
            <Page>
                <PageHeader
                    eyebrow="Unidade"
                    title={unit.name}
                    actions={
                        canUpdate && (
                            <Button
                                variant="outline"
                                asChild
                                className="min-h-11"
                            >
                                <Link href={edit(unit.id)}>
                                    <Pencil aria-hidden="true" />
                                    Editar unidade
                                </Link>
                            </Button>
                        )
                    }
                />

                {!unit.complete && (
                    <div className="mb-8 flex gap-3 rounded-lg border border-warning-border bg-warning-surface p-4 text-sm text-warning">
                        <TriangleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p>
                            <strong className="font-semibold">
                                Cadastro incompleto.
                            </strong>{' '}
                            Informe UF e fuso horário para que esta unidade
                            possa publicar escalas.
                        </p>
                    </div>
                )}

                <div className="grid gap-8 lg:grid-cols-3">
                    <section
                        aria-labelledby="sectors-heading"
                        className="overflow-hidden rounded-lg border bg-card lg:col-span-2"
                    >
                        <header className="flex items-center justify-between gap-3 border-b px-5 py-4">
                            <h2
                                id="sectors-heading"
                                className="flex items-center gap-2 font-semibold tracking-tight"
                            >
                                <LayoutList
                                    className="size-4 text-primary"
                                    aria-hidden="true"
                                />
                                Setores
                            </h2>
                            <span className="text-sm text-muted-foreground tabular-nums">
                                {sectors.length}{' '}
                                {sectors.length === 1 ? 'setor' : 'setores'}
                            </span>
                        </header>

                        {sectors.length === 0 ? (
                            <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                                Nenhum setor cadastrado
                                {canManageSectors
                                    ? '. Adicione o primeiro abaixo.'
                                    : '.'}
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {sectors.map((sector) => (
                                    <li key={sector.id} className="px-5 py-3">
                                        {canManageSectors ? (
                                            <SectorRenameForm
                                                unitId={unit.id}
                                                sector={sector}
                                                bedSpecialties={bedSpecialties}
                                            />
                                        ) : (
                                            <span className="flex min-h-11 items-center font-medium">
                                                {sector.name}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {canManageSectors && (
                            <Form
                                {...SectorController.store.form(unit.id)}
                                errorBag="newSector"
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-2 border-t bg-muted/40 px-5 py-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Label htmlFor="new-sector">
                                            Novo setor
                                        </Label>
                                        <div className="flex flex-wrap gap-3">
                                            <Input
                                                id="new-sector"
                                                name="name"
                                                required
                                                maxLength={255}
                                                placeholder="Ex.: UTI Adulto"
                                                aria-invalid={!!errors.name}
                                                aria-describedby={
                                                    errors.name
                                                        ? 'new-sector-error'
                                                        : undefined
                                                }
                                                className="h-11 min-w-0 flex-1 bg-card"
                                            />
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
                                        <InputError
                                            id="new-sector-error"
                                            message={errors.name}
                                        />
                                    </>
                                )}
                            </Form>
                        )}
                    </section>

                    <aside aria-labelledby="details-heading">
                        <h2
                            id="details-heading"
                            className="mb-3 text-sm font-semibold tracking-tight"
                        >
                            Informações
                        </h2>
                        <dl className="divide-y rounded-lg border bg-card text-sm">
                            <Detail label="UF" value={unit.uf} />
                            <Detail
                                label="Fuso horário"
                                value={unit.timezone?.replaceAll('_', ' ')}
                            />
                            <div className="flex items-center justify-between gap-3 px-4 py-3">
                                <dt className="text-muted-foreground">
                                    Situação
                                </dt>
                                <dd>
                                    {unit.complete ? (
                                        'Completo'
                                    ) : (
                                        <WarningBadge>Incompleto</WarningBadge>
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </aside>
                </div>

                {attendancePoints && (
                    <AttendancePoints
                        unitId={unit.id}
                        points={attendancePoints}
                    />
                )}
            </Page>
        </>
    );
}

/**
 * Authorized presence points (step 8a). A point is never edited: add a new one and deactivate the old,
 * so past records keep the configuration they used.
 */
function AttendancePoints({
    unitId,
    points,
}: {
    unitId: number;
    points: AttendancePoint[];
}) {
    return (
        <section
            aria-labelledby="points-heading"
            className="mt-8 overflow-hidden rounded-lg border bg-card"
        >
            <header className="border-b px-5 py-4">
                <h2
                    id="points-heading"
                    className="flex items-center gap-2 font-semibold tracking-tight"
                >
                    <MapPin
                        className="size-4 text-primary"
                        aria-hidden="true"
                    />
                    Pontos de presença
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    Entrada e saída só são aceitas dentro do raio de um ponto,
                    somando a precisão do aparelho. Para mudar um ponto,
                    cadastre outro e desative o antigo.
                </p>
            </header>

            {points.length === 0 ? (
                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                    Nenhum ponto ativo: a presença por localização fica
                    desativada nesta unidade.
                </p>
            ) : (
                <ul className="divide-y">
                    {points.map((point) => (
                        <li
                            key={point.id}
                            className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm"
                        >
                            <span>
                                <span className="font-medium">
                                    {point.name}
                                </span>
                                <span className="block text-muted-foreground tabular-nums">
                                    {point.latitude}, {point.longitude} · raio{' '}
                                    {point.radius_m} m
                                </span>
                            </span>
                            <Form
                                {...AttendancePointController.deactivate.form([
                                    unitId,
                                    point.id,
                                ])}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        {processing && <Spinner />}
                                        Desativar
                                        <span className="sr-only">
                                            {' '}
                                            {point.name}
                                        </span>
                                    </Button>
                                )}
                            </Form>
                        </li>
                    ))}
                </ul>
            )}

            <Form
                {...AttendancePointController.store.form(unitId)}
                errorBag="newPoint"
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="grid gap-3 border-t bg-muted/40 px-5 py-4 sm:grid-cols-2"
            >
                {({ processing, errors }) => (
                    <>
                        <PointField
                            id="point-name"
                            name="name"
                            label="Nome"
                            error={errors.name}
                            placeholder="Ex.: Portaria principal"
                        />
                        <PointField
                            id="point-radius"
                            name="radius_m"
                            label="Raio (m)"
                            error={errors.radius_m}
                            inputMode="numeric"
                        />
                        <PointField
                            id="point-latitude"
                            name="latitude"
                            label="Latitude"
                            error={errors.latitude}
                            inputMode="decimal"
                            placeholder="-23.550520"
                        />
                        <PointField
                            id="point-longitude"
                            name="longitude"
                            label="Longitude"
                            error={errors.longitude}
                            inputMode="decimal"
                            placeholder="-46.633308"
                        />
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <Plus aria-hidden="true" />
                            )}
                            Cadastrar ponto
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}

function PointField({
    id,
    name,
    label,
    error,
    placeholder,
    inputMode,
}: {
    id: string;
    name: string;
    label: string;
    error?: string;
    placeholder?: string;
    inputMode?: 'numeric' | 'decimal';
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={name}
                required
                inputMode={inputMode}
                placeholder={placeholder}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className="h-11 bg-card"
            />
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

function Detail({ label, value }: { label: string; value?: string | null }) {
    return (
        <div className="flex items-center justify-between gap-3 px-4 py-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium">
                {value ?? 'Não informado'}
            </dd>
        </div>
    );
}

function SectorRenameForm({
    unitId,
    sector,
    bedSpecialties,
}: {
    unitId: number;
    sector: Sector;
    bedSpecialties: { code: string; name: string }[];
}) {
    const inputId = `sector-${sector.id}`;

    return (
        <Form
            {...SectorController.update.form([unitId, sector.id])}
            errorBag={inputId}
            options={{ preserveScroll: true }}
            className="grid gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <Label htmlFor={inputId} className="sr-only">
                        Nome do setor
                    </Label>
                    <div className="flex flex-wrap gap-3">
                        <Input
                            id={inputId}
                            name="name"
                            required
                            maxLength={255}
                            defaultValue={sector.name}
                            aria-invalid={!!errors.name}
                            aria-describedby={
                                errors.name ? `${inputId}-error` : undefined
                            }
                            className="h-11 min-w-0 flex-1"
                        />
                        <select
                            name="sus_bed_specialty"
                            aria-label={`Especialidade SUS do leito de ${sector.name}`}
                            defaultValue={sector.sus_bed_specialty ?? ''}
                            className={`${selectClassName} min-w-0 flex-1`}
                        >
                            <option value="">Especialidade SUS do leito</option>
                            {bedSpecialties.map((option) => (
                                <option key={option.code} value={option.code}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            aria-label={`Salvar ${sector.name}`}
                            className="min-h-11"
                        >
                            {processing && <Spinner />}
                            Salvar
                        </Button>
                    </div>
                    <InputError id={`${inputId}-error`} message={errors.name} />
                </>
            )}
        </Form>
    );
}

UnitShow.layout = {
    breadcrumbs: [{ title: 'Unidades', href: index() }],
};
