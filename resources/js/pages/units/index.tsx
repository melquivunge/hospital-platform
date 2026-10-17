import { Head, Link } from '@inertiajs/react';
import { Building2, ChevronRight, Plus } from 'lucide-react';
import { Page, PageHeader, WarningBadge } from '@/components/page';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/units';

type UnitRow = {
    id: number;
    name: string;
    uf: string | null;
    timezone: string | null;
    complete: boolean;
    sectorsCount: number;
};

export default function UnitsIndex({
    units,
    canCreate,
}: {
    units: UnitRow[];
    canCreate: boolean;
}) {
    return (
        <>
            <Head title="Unidades" />
            <Page>
                <PageHeader
                    eyebrow="Estrutura"
                    title="Unidades"
                    description="Hospitais da instituição, sua localização e seus setores."
                    actions={
                        canCreate && (
                            <Button asChild className="min-h-11">
                                <Link href={create()}>
                                    <Plus aria-hidden="true" />
                                    Nova unidade
                                </Link>
                            </Button>
                        )
                    }
                />

                {units.length === 0 ? (
                    <EmptyState canCreate={canCreate} />
                ) : (
                    <div className="overflow-hidden rounded-lg border bg-card">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Unidades da instituição
                            </caption>
                            <thead className="border-b bg-muted/60 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th scope="col" className="px-5 py-3">
                                        Unidade
                                    </th>
                                    <th
                                        scope="col"
                                        className="hidden px-5 py-3 sm:table-cell"
                                    >
                                        UF
                                    </th>
                                    <th
                                        scope="col"
                                        className="hidden px-5 py-3 lg:table-cell"
                                    >
                                        Fuso horário
                                    </th>
                                    <th
                                        scope="col"
                                        className="w-px px-5 py-3 text-right whitespace-nowrap"
                                    >
                                        Setores
                                    </th>
                                    <th
                                        scope="col"
                                        className="hidden px-5 py-3 md:table-cell"
                                    >
                                        Situação
                                    </th>
                                    <th
                                        scope="col"
                                        className="hidden w-10 px-3 py-3 sm:table-cell"
                                    >
                                        <span className="sr-only">Abrir</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {units.map((unit) => (
                                    <tr
                                        key={unit.id}
                                        className="group relative transition-colors hover:bg-muted/50"
                                    >
                                        <td className="px-5 py-4">
                                            <Link
                                                href={show(unit.id)}
                                                className="font-medium after:absolute after:inset-0 focus-visible:outline-none after:focus-visible:rounded-sm after:focus-visible:ring-2 after:focus-visible:ring-ring after:focus-visible:ring-inset"
                                            >
                                                {unit.name}
                                            </Link>
                                            <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground md:hidden">
                                                <span className="sm:hidden">
                                                    {unit.uf ??
                                                        'UF não informada'}
                                                </span>
                                                {!unit.complete && (
                                                    <WarningBadge>
                                                        Cadastro incompleto
                                                    </WarningBadge>
                                                )}
                                            </p>
                                        </td>
                                        <td className="hidden px-5 py-4 text-muted-foreground sm:table-cell">
                                            {unit.uf ?? '—'}
                                        </td>
                                        <td className="hidden px-5 py-4 text-muted-foreground lg:table-cell">
                                            {unit.timezone?.replaceAll(
                                                '_',
                                                ' ',
                                            ) ?? '—'}
                                        </td>
                                        <td className="px-5 py-4 text-right tabular-nums">
                                            {unit.sectorsCount}
                                        </td>
                                        <td className="hidden px-5 py-4 md:table-cell">
                                            {unit.complete ? (
                                                <span className="text-muted-foreground">
                                                    Completo
                                                </span>
                                            ) : (
                                                <WarningBadge>
                                                    Cadastro incompleto
                                                </WarningBadge>
                                            )}
                                        </td>
                                        <td className="hidden px-3 py-4 text-muted-foreground sm:table-cell">
                                            <ChevronRight
                                                className="size-4 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none"
                                                aria-hidden="true"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Page>
        </>
    );
}

function EmptyState({ canCreate }: { canCreate: boolean }) {
    return (
        <section className="flex flex-col items-center rounded-lg border border-dashed bg-card px-6 py-14 text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-accent text-primary">
                <Building2 className="size-6" aria-hidden="true" />
            </span>
            <h2 className="mt-5 text-lg font-semibold tracking-tight">
                Nenhuma unidade disponível
            </h2>
            <p className="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">
                {canCreate
                    ? 'Cadastre o primeiro hospital da instituição para organizar setores e, depois, escalas.'
                    : 'Nenhuma unidade foi atribuída à sua conta. Fale com o administrador da instituição.'}
            </p>
            {canCreate && (
                <Button asChild className="mt-6 min-h-11">
                    <Link href={create()}>
                        <Plus aria-hidden="true" />
                        Cadastrar unidade
                    </Link>
                </Button>
            )}
        </section>
    );
}

UnitsIndex.layout = {
    breadcrumbs: [{ title: 'Unidades', href: index() }],
};
