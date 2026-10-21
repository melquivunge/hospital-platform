import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import ImportController from '@/actions/App/Http/Controllers/ImportController';
import InputError from '@/components/input-error';
import { Page, PageHeader, WarningBadge } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { batchStatusLabels } from '@/pages/imports/index';
import { index, show } from '@/routes/imports';

type Status = 'ready' | 'created' | 'identical' | 'conflict' | 'rejected';

type Item = {
    id: number;
    line: number;
    sourceId: string | null;
    name: string | null;
    status: Status;
    messages: string[];
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
};

const statusLabels: Record<Status, string> = {
    ready: 'Será criado',
    created: 'Criado',
    identical: 'Já existente idêntico',
    conflict: 'Conflitante',
    rejected: 'Rejeitado',
};

/** What confirming creates, per import type. */
const createdLabels = {
    professionals: 'profissionais',
    shifts: 'plantões em rascunho',
    medications: 'apresentações no catálogo',
    medication_interactions: 'interações',
};

export default function ImportShow({
    batch,
    counts,
    items,
    filter,
}: {
    batch: {
        id: number;
        type: keyof typeof createdLabels;
        source_system: string;
        filename: string;
        status: 'previewed' | 'applying' | 'applied';
        received: number;
        createdAt: string;
    };
    counts: Record<Status, number>;
    items: Paginated<Item>;
    filter: Status | null;
}) {
    // While the worker applies lines, refresh the counts.
    const { start, stop } = usePoll(
        3000,
        { only: ['batch', 'counts', 'items'] },
        { autoStart: false },
    );
    // The page mounts while previewed and stays mounted after confirming: follow the status.
    useEffect(() => {
        if (batch.status === 'applying') {
            start();
        } else {
            stop();
        }

        return stop;
    }, [batch.status, start, stop]);
    const total = Object.values(counts).reduce((sum, count) => sum + count, 0);

    return (
        <>
            <Head title={`Importação ${batch.filename}`} />
            <Page>
                <PageHeader
                    eyebrow={`Importação · ${batch.source_system}`}
                    title={batch.filename}
                    description={`${batchStatusLabels[batch.status]}. ${batch.received} linhas recebidas; ${total} contabilizadas.`}
                />

                <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {(Object.keys(statusLabels) as Status[]).map((status) => (
                        <Link
                            key={status}
                            href={show(batch.id, {
                                query: filter === status ? {} : { status },
                            })}
                            preserveScroll
                            aria-current={
                                filter === status ? 'true' : undefined
                            }
                            className={cn(
                                'rounded-lg border bg-card p-3 text-sm hover:bg-muted/40',
                                filter === status && 'border-primary',
                            )}
                        >
                            <dt className="text-muted-foreground">
                                {statusLabels[status]}
                            </dt>
                            <dd className="text-2xl font-semibold tabular-nums">
                                {counts[status]}
                            </dd>
                        </Link>
                    ))}
                </dl>

                {batch.status !== 'applied' && (
                    <Form
                        {...ImportController.confirm.form(batch.id)}
                        className="mb-6 grid gap-3 rounded-lg border bg-card p-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {batch.status === 'previewed'
                                        ? `Confirmar cria ${counts.ready} ${createdLabels[batch.type]}. Linhas idênticas, conflitantes ou rejeitadas não alteram nada.`
                                        : 'Aplicando as linhas válidas. Se o processo parar, você pode retomar sem duplicar.'}
                                </p>
                                <InputError message={errors.batch} />
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        (batch.status === 'previewed' &&
                                            counts.ready === 0)
                                    }
                                    className="min-h-11 justify-self-start"
                                >
                                    {processing && <Spinner />}
                                    {batch.status === 'previewed'
                                        ? 'Confirmar importação'
                                        : 'Retomar'}
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                <div className="overflow-x-auto rounded-lg border bg-card">
                    <table className="w-full text-sm">
                        <caption className="sr-only">
                            Resultado por linha do arquivo
                        </caption>
                        <thead className="border-b bg-muted/60 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            <tr>
                                <th scope="col" className="px-4 py-3">
                                    Linha
                                </th>
                                <th scope="col" className="px-4 py-3">
                                    Identificador
                                </th>
                                <th scope="col" className="px-4 py-3">
                                    {batch.type === 'shifts'
                                        ? 'Início'
                                        : 'Nome'}
                                </th>
                                <th scope="col" className="px-4 py-3">
                                    Resultado
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {items.data.map((item) => (
                                <tr key={item.id} className="align-top">
                                    <td className="px-4 py-3 tabular-nums">
                                        {item.line}
                                    </td>
                                    <td className="px-4 py-3">
                                        {item.sourceId ?? '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        {item.name ?? '—'}
                                    </td>
                                    <td className="px-4 py-3">
                                        {['conflict', 'rejected'].includes(
                                            item.status,
                                        ) ? (
                                            <WarningBadge>
                                                {statusLabels[item.status]}
                                            </WarningBadge>
                                        ) : (
                                            <span className="font-medium">
                                                {statusLabels[item.status]}
                                            </span>
                                        )}
                                        {item.messages.length > 0 && (
                                            <ul className="mt-1 list-disc pl-5 text-muted-foreground">
                                                {item.messages.map(
                                                    (message) => (
                                                        <li key={message}>
                                                            {message}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {items.links.length > 3 && (
                    <nav
                        aria-label="Páginas"
                        className="mt-4 flex flex-wrap gap-2"
                    >
                        {items.links.map((link, position) =>
                            link.url === null ? null : (
                                <Link
                                    key={position}
                                    href={link.url}
                                    preserveScroll
                                    aria-current={
                                        link.active ? 'page' : undefined
                                    }
                                    className={cn(
                                        'flex min-h-11 min-w-11 items-center justify-center rounded-md border px-3 text-sm',
                                        link.active &&
                                            'border-primary bg-primary text-primary-foreground',
                                    )}
                                >
                                    {link.label
                                        .replace('&laquo; Previous', 'Anterior')
                                        .replace('Next &raquo;', 'Próxima')}
                                </Link>
                            ),
                        )}
                    </nav>
                )}
            </Page>
        </>
    );
}

ImportShow.layout = {
    breadcrumbs: [{ title: 'Importações', href: index() }],
};
