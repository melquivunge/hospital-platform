import { Link, usePage } from '@inertiajs/react';
import { Bell, ChevronDown } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { index as notifications } from '@/routes/notifications';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/** Top bar: where you are on the left; notifications and your account on the right. */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { auth, unreadNotifications } = usePage().props;
    const getInitials = useInitials();

    return (
        <header className="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-border px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {auth.user && (
                <div className="flex shrink-0 items-center gap-1">
                    <Link
                        href={notifications()}
                        aria-label={
                            unreadNotifications > 0
                                ? `Notificações, ${unreadNotifications} não lidas`
                                : 'Notificações'
                        }
                        className="relative flex size-11 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <Bell className="size-5" aria-hidden="true" />
                        {unreadNotifications > 0 && (
                            <span
                                aria-hidden="true"
                                className="absolute top-1.5 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-none font-semibold text-white tabular-nums ring-2 ring-background"
                            >
                                {unreadNotifications > 99
                                    ? '99+'
                                    : unreadNotifications}
                            </span>
                        )}
                    </Link>
                    <DropdownMenu>
                        <DropdownMenuTrigger
                            aria-label={`Conta de ${auth.user.name}`}
                            className="flex min-h-11 items-center gap-2 rounded-full py-1 pr-2 pl-1 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none data-[state=open]:bg-accent"
                            data-test="user-menu-button"
                        >
                            <Avatar className="size-8 overflow-hidden rounded-full">
                                <AvatarImage
                                    src={auth.user.avatar ?? undefined}
                                    alt=""
                                />
                                <AvatarFallback className="rounded-full bg-primary/10 text-xs font-medium text-primary">
                                    {getInitials(auth.user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="hidden max-w-40 truncate text-sm font-medium sm:inline">
                                {auth.user.name}
                            </span>
                            <ChevronDown
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            sideOffset={8}
                            className="w-64 rounded-xl p-1.5"
                        >
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            )}
        </header>
    );
}
