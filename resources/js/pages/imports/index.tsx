import { Head, Link } from '@inertiajs/react';
import { FileUp, Plus } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/imports';

type Batch = {
    id: number;
    source_system: string;
    filename: string;
    status: 'previewed' | 'applying' | 'applied';
    received: number;
    createdAt: string;
};

export const batchStatusLabels: Record<Batch['status'], string> = {
    previewed: 'Aguardando confirmação',
    applying: 'Aplicando',
    applied: 'Concluída',
};

export default function ImportsIndex({ batches }: { batches: Batch[] }) {
    return (
        <>
            <Head title="Importações" />
            <Page>
                <PageHeader
                    eyebrow="Dados"
                    title="Importações"
                    description="Arquivos CSV de profissionais. Nada é gravado antes da sua confirmação, e cada linha recebe um resultado."
                    actions={
                        <Button asChild className="min-h-11">
                            <Link href={create()}>
                                <Plus aria-hidden="true" />
                                Nova importação
                            </Link>
                        </Button>
                    }
                />

                {batches.length === 0 ? (
                    <div className="rounded-lg border bg-card px-5 py-12 text-center">
                        <FileUp
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Nenhuma importação ainda</p>
                    </div>
                ) : (
                    <ul className="divide-y overflow-hidden rounded-lg border bg-card">
                        {batches.map((batch) => (
                            <li key={batch.id}>
                                <Link
                                    href={show(batch.id)}
                                    className="grid gap-1 px-5 py-3 text-sm hover:bg-muted/40 sm:grid-cols-[1fr_auto] sm:items-center"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {batch.filename}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            · {batch.source_system} ·{' '}
                                            {batch.received} linhas ·{' '}
                                            {new Date(
                                                batch.createdAt,
                                            ).toLocaleString('pt-BR')}
                                        </span>
                                    </span>
                                    <span className="font-medium">
                                        {batchStatusLabels[batch.status]}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

ImportsIndex.layout = {
    breadcrumbs: [{ title: 'Importações', href: index() }],
};
