import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavGroup } from '@/types';

// ponytail: a group opens when it is the first one, holds the current page, or the person sees few groups;
// the open/closed choice is not remembered between visits (add storage if people ask for it).
const FEW_GROUPS = 3;

export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <nav aria-label="Principal">
            {groups.map((group, index) => {
                const holdsCurrent = group.items.some((item) =>
                    isCurrentOrParentUrl(item.href),
                );

                return (
                    <Collapsible
                        key={group.label}
                        defaultOpen={
                            index === 0 ||
                            holdsCurrent ||
                            groups.length <= FEW_GROUPS
                        }
                        className="group/collapsible"
                    >
                        <SidebarGroup className="px-2 py-1">
                            <SidebarGroupLabel asChild>
                                <CollapsibleTrigger className="min-h-11 w-full cursor-pointer justify-between hover:bg-sidebar-accent hover:text-sidebar-accent-foreground md:min-h-8">
                                    <span className="flex items-center gap-2">
                                        <group.icon
                                            aria-hidden="true"
                                            className="size-4 shrink-0"
                                        />
                                        {group.label}
                                    </span>
                                    <ChevronRight
                                        aria-hidden="true"
                                        className="transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90 motion-reduce:transition-none"
                                    />
                                </CollapsibleTrigger>
                            </SidebarGroupLabel>
                            {/* In icon-only mode the labels are hidden, so every icon stays visible. */}
                            <CollapsibleContent
                                forceMount
                                className="group-data-[collapsible=icon]:block! data-[state=closed]:hidden"
                            >
                                <SidebarMenu>
                                    {group.items.map((item) => (
                                        <SidebarMenuItem key={item.title}>
                                            <SidebarMenuButton
                                                asChild
                                                isActive={isCurrentUrl(
                                                    item.href,
                                                )}
                                                tooltip={{
                                                    children: item.title,
                                                }}
                                                className="data-[active=true]:font-medium data-[active=true]:shadow-[inset_3px_0_0_var(--sidebar-ring)] max-md:h-11"
                                            >
                                                <Link href={item.href} prefetch>
                                                    {item.icon && (
                                                        <item.icon aria-hidden="true" />
                                                    )}
                                                    <span>{item.title}</span>
                                                </Link>
                                            </SidebarMenuButton>
                                        </SidebarMenuItem>
                                    ))}
                                </SidebarMenu>
                            </CollapsibleContent>
                        </SidebarGroup>
                    </Collapsible>
                );
            })}
        </nav>
    );
}
