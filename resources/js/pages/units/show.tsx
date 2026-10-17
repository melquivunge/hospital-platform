import { Form, Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import SectorController from '@/actions/App/Http/Controllers/SectorController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
            <div className="max-w-3xl p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        level={1}
                        title={unit.name}
                        description={`${unit.uf ?? 'UF não informada'} · ${unit.timezone ?? 'Fuso horário não informado'}`}
                    />
                    {canUpdate && (
                        <Button variant="outline" asChild className="min-h-11">
                            <Link href={edit(unit.id)}>
                                <Pencil aria-hidden="true" />
                                Editar unidade
                            </Link>
                        </Button>
                    )}
                </div>

                {!unit.complete && (
                    <p className="mb-8 flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm">
                        <Badge variant="outline">Cadastro incompleto</Badge>
                        Informe UF e fuso horário para que esta unidade possa
                        publicar escalas.
                    </p>
                )}

                <section
                    aria-labelledby="sectors-heading"
                    className="space-y-4"
                >
                    <h2 id="sectors-heading" className="text-lg font-semibold">
                        Setores
                    </h2>

                    {sectors.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nenhum setor cadastrado.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border bg-card">
                            {sectors.map((sector) => (
                                <li key={sector.id} className="px-4 py-3">
                                    {canManageSectors ? (
                                        <SectorRenameForm
                                            unitId={unit.id}
                                            sector={sector}
                                        />
                                    ) : (
                                        <span className="font-medium">
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
                            className="grid gap-2 rounded-xl border p-4"
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
                                            aria-invalid={!!errors.name}
                                            aria-describedby={
                                                errors.name
                                                    ? 'new-sector-error'
                                                    : undefined
                                            }
                                            className="h-11 flex-1"
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="min-h-11"
                                        >
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
            </div>
        </>
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
                        Nome do setor {sector.name}
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
                            className="h-11 flex-1"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Salvar
                            <span className="sr-only"> {sector.name}</span>
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
