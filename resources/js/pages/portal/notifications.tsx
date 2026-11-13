import { Head, Link } from '@inertiajs/react';
import PortalHomeController from '@/actions/App/Http/Controllers/Portal/PortalHomeController';

type Notice = { id: number; title: string; createdAt: string; unread: boolean };

const when = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

/**
 * Step 7b.3: the portal's inbox, newest first; the ones new since the last visit are marked.
 */
export default function PortalNotifications({
    network,
    notices,
}: {
    network: { name: string; slug: string };
    notices: Notice[];
}) {
    return (
        <>
            <Head title="Avisos" />
            <h1 className="text-2xl font-semibold tracking-tight">Avisos</h1>
            {notices.length === 0 ? (
                <p className="mt-2 text-sm text-muted-foreground">
                    Nenhum aviso ainda. Quando houver uma consulta marcada, um
                    resultado ou uma etapa da sua visita, ele aparece aqui.
                </p>
            ) : (
                <ul className="mt-6 grid gap-2">
                    {notices.map((notice) => (
                        <li
                            key={notice.id}
                            className={`rounded-md border px-4 py-3 ${notice.unread ? 'border-primary bg-primary/10' : 'bg-card'}`}
                        >
                            <p className="text-sm font-medium">
                                {notice.title}
                                {notice.unread && (
                                    <span className="ml-2 text-xs font-semibold text-primary">
                                        Novo
                                    </span>
                                )}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                <time dateTime={notice.createdAt}>
                                    {when.format(new Date(notice.createdAt))}
                                </time>
                            </p>
                        </li>
                    ))}
                </ul>
            )}
            <p className="mt-6 text-sm">
                <Link
                    href={PortalHomeController.url(network.slug)}
                    className="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                >
                    Voltar ao início
                </Link>
            </p>
        </>
    );
}
