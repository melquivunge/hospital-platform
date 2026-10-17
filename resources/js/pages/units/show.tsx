import { Form, Head, Link } from '@inertiajs/react';
import { LayoutList, Pencil, Plus, TriangleAlert } from 'lucide-react';
import SectorController from '@/actions/App/Http/Controllers/SectorController';
import InputError from '@/components/input-error';
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

type Sector = { id: number; name: string };

export default function UnitShow({
    unit,
    sectors,
    canUpdate,
    canManageSectors,
}: {
    unit: Unit;
    sectors: Sector[];
    canUpdate: boolean;
    canManageSectors: boolean;
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
            </Page>
        </>
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
}: {
    unitId: number;
    sector: Sector;
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
