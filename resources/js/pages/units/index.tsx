import { Head, Link } from '@inertiajs/react';
import { Building2, ChevronRight, Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
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
            <div className="p-4 md:p-8">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        level={1}
                        title="Unidades"
                        description="Hospitais da instituição e seus setores."
                    />
                    {canCreate && (
                        <Button asChild className="min-h-11">
                            <Link href={create()}>
                                <Plus aria-hidden="true" />
                                Nova unidade
                            </Link>
                        </Button>
                    )}
                </div>

                {units.length === 0 ? (
                    <section className="max-w-xl rounded-xl border bg-card p-8">
                        <Building2
                            className="size-8 text-teal-700"
                            aria-hidden="true"
                        />
                        <h2 className="mt-5 text-lg font-semibold">
                            Nenhuma unidade disponível
                        </h2>
                        <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                            {canCreate
                                ? 'Cadastre o primeiro hospital da instituição para organizar setores e escalas.'
                                : 'Nenhuma unidade foi atribuída à sua conta. Fale com o administrador da instituição.'}
                        </p>
                    </section>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {units.map((unit) => (
                            <li key={unit.id}>
                                <Link
                                    href={show(unit.id)}
                                    className="flex min-h-11 flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-muted/50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-teal-700"
                                >
                                    <span className="font-medium">
                                        {unit.name}
                                    </span>
                                    <span className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                                        <span>
                                            {unit.uf ?? 'UF não informada'}
                                        </span>
                                        <span>
                                            {unit.sectorsCount}{' '}
                                            {unit.sectorsCount === 1
                                                ? 'setor'
                                                : 'setores'}
                                        </span>
                                        {!unit.complete && (
                                            <Badge variant="outline">
                                                Cadastro incompleto
                                            </Badge>
                                        )}
                                        <ChevronRight
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

UnitsIndex.layout = {
    breadcrumbs: [{ title: 'Unidades', href: index() }],
};
