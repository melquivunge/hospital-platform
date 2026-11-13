import { Form, Link, usePage } from '@inertiajs/react';
import { Activity, Bell, LogOut } from 'lucide-react';
import type { ReactNode } from 'react';
import PortalAuthController from '@/actions/App/Http/Controllers/Portal/PortalAuthController';
import PortalNotificationController from '@/actions/App/Http/Controllers/Portal/PortalNotificationController';
import { Button } from '@/components/ui/button';

type PortalProps = {
    network?: { name: string; slug: string };
    patient?: { name: string };
    unread?: number;
};

/**
 * Step 7a.1a: the patient portal's shell, phone first. Apart from the staff app: no menu, no staff
 * account; the network's name says whose portal this is.
 */
export default function PortalLayout({ children }: { children: ReactNode }) {
    const { network, patient, unread = 0 } = usePage<PortalProps>().props;

    return (
        <div className="min-h-svh bg-background text-foreground">
            <a
                href="#conteudo"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-4 focus:py-3 focus:text-sm focus:font-medium focus:shadow-md focus:ring-2 focus:ring-ring focus:outline-none"
            >
                Pular para o conteúdo
            </a>
            <header className="border-b bg-card">
                <div className="mx-auto flex min-h-16 w-full max-w-2xl items-center justify-between gap-3 px-4">
                    <p className="flex min-w-0 items-center gap-2 text-sm font-semibold">
                        <Activity
                            className="size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <span className="truncate">
                            {network?.name ?? 'Portal do paciente'}
                        </span>
                        <span className="sr-only"> — portal do paciente</span>
                    </p>
                    {patient && network && (
                        <div className="flex items-center gap-1">
                            <Link
                                href={PortalNotificationController.url(
                                    network.slug,
                                )}
                                className="relative inline-flex size-11 items-center justify-center rounded-md hover:bg-accent"
                                aria-label={
                                    unread > 0
                                        ? `Avisos, ${unread} novo${unread > 1 ? 's' : ''}`
                                        : 'Avisos'
                                }
                            >
                                <Bell className="size-5" aria-hidden="true" />
                                {unread > 0 && (
                                    <span
                                        className="absolute top-1.5 right-1.5 min-w-4 rounded-full bg-primary px-1 text-center text-[11px] leading-4 font-semibold text-primary-foreground"
                                        aria-hidden="true"
                                    >
                                        {unread > 9 ? '9+' : unread}
                                    </span>
                                )}
                            </Link>
                            <Form
                                {...PortalAuthController.logout.form(
                                    network.slug,
                                )}
                            >
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    className="min-h-11"
                                >
                                    <LogOut aria-hidden="true" />
                                    Sair
                                </Button>
                            </Form>
                        </div>
                    )}
                </div>
            </header>
            <main
                id="conteudo"
                tabIndex={-1}
                className="mx-auto w-full max-w-2xl px-4 py-8 focus:outline-none sm:py-12"
            >
                {children}
            </main>
        </div>
    );
}
