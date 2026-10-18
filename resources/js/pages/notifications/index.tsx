import { Form, Head, Link } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/notifications';

type Notification = {
    id: number;
    title: string;
    url: string | null;
    read: boolean;
    at: string;
};

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

/** Internal notifications (step 9a): requests, decisions and schedule changes that involve you. */
export default function Notifications({
    notifications,
}: {
    notifications: Notification[];
}) {
    const unread = notifications.some((notification) => !notification.read);

    return (
        <>
            <Head title="Notificações" />
            <Page width="narrow">
                <PageHeader
                    title="Notificações"
                    description="Pedidos, decisões e mudanças de escala que envolvem você."
                    actions={
                        unread && (
                            <Form {...NotificationController.readAll.form()}>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    Marcar todas como lidas
                                </Button>
                            </Form>
                        )
                    }
                />
                {notifications.length === 0 ? (
                    <div className="rounded-lg border bg-card px-5 py-12 text-center">
                        <Bell
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Nenhuma notificação</p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border bg-card text-sm">
                        {notifications.map((notification) => (
                            <li
                                key={notification.id}
                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                            >
                                <span className="grid gap-0.5">
                                    <span
                                        className={
                                            notification.read
                                                ? ''
                                                : 'font-semibold'
                                        }
                                    >
                                        {!notification.read && (
                                            <span className="sr-only">
                                                Não lida:{' '}
                                            </span>
                                        )}
                                        {notification.title}
                                    </span>
                                    <time
                                        dateTime={notification.at}
                                        className="text-muted-foreground tabular-nums"
                                    >
                                        {dateTime.format(
                                            new Date(notification.at),
                                        )}
                                    </time>
                                </span>
                                {notification.url && (
                                    <Link
                                        href={notification.url}
                                        className="flex min-h-11 items-center font-medium text-primary underline"
                                    >
                                        Abrir
                                    </Link>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Notificações', href: index() }],
};
