import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Plus, Stethoscope } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/professionals';

type ProfessionalRow = {
    id: number;
    name: string;
    council: string | null;
    council_number: string | null;
    council_uf: string | null;
    baseUnit: string | null;
};

export function councilLabel(professional: {
    council: string | null;
    council_number: string | null;
    council_uf: string | null;
}) {
    return professional.council
        ? `${professional.council} ${professional.council_number}/${professional.council_uf}`
        : null;
}

export default function ProfessionalsIndex({
    professionals,
    canCreate,
}: {
    professionals: ProfessionalRow[];
    canCreate: boolean;
}) {
    return (
        <>
            <Head title="Profissionais" />
            <Page>
                <PageHeader
                    eyebrow="Equipe"
                    title="Profissionais"
                    description="Cadastro de profissionais da instituição e sua unidade-base. Ter cadastro não dá acesso ao sistema."
                    actions={
                        canCreate && (
                            <Button asChild className="min-h-11">
                                <Link href={create()}>
                                    <Plus aria-hidden="true" />
                                    Novo profissional
                                </Link>
                            </Button>
                        )
                    }
                />

                {professionals.length === 0 ? (
                    <div className="rounded-lg border bg-card px-5 py-12 text-center">
                        <Stethoscope
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">
                            Nenhum profissional cadastrado
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {canCreate
                                ? 'Cadastre o primeiro profissional para montar escalas.'
                                : 'Os profissionais das suas unidades aparecerão aqui.'}
                        </p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-lg border bg-card">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Profissionais da instituição
                            </caption>
                            <thead className="border-b bg-muted/60 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th scope="col" className="px-5 py-3">
                                        Nome
                                    </th>
                                    <th
                                        scope="col"
                                        className="hidden px-5 py-3 sm:table-cell"
                                    >
                                        Registro
                                    </th>
                                    <th
                                        scope="col"
                                        className="hidden px-5 py-3 md:table-cell"
                                    >
                                        Unidade-base
                                    </th>
                                    <th scope="col" className="w-px px-5 py-3">
                                        <span className="sr-only">Abrir</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {professionals.map((professional) => (
                                    <tr
                                        key={professional.id}
                                        className="hover:bg-muted/40"
                                    >
                                        <td className="px-5 py-3 font-medium">
                                            <Link
                                                href={show(professional.id)}
                                                className="flex min-h-11 items-center hover:underline"
                                            >
                                                {professional.name}
                                            </Link>
                                        </td>
                                        <td className="hidden px-5 py-3 text-muted-foreground sm:table-cell">
                                            {councilLabel(professional) ??
                                                'Não informado'}
                                        </td>
                                        <td className="hidden px-5 py-3 md:table-cell">
                                            {professional.baseUnit}
                                        </td>
                                        <td className="px-5 py-3">
                                            <ChevronRight
                                                className="size-4 text-muted-foreground"
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

ProfessionalsIndex.layout = {
    breadcrumbs: [{ title: 'Profissionais', href: index() }],
};
