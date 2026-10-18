import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BriefcaseMedical,
    CalendarCheck,
    CalendarDays,
    FileUp,
    Building2,
    LayoutGrid,
    SlidersHorizontal,
    Stethoscope,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as selectTenant } from '@/routes/current-tenant';
import { index as imports } from '@/routes/imports';
import { index as jobFunctions } from '@/routes/job-functions';
import { index as people } from '@/routes/people';
import { index as policies } from '@/routes/policies';
import { index as professionals } from '@/routes/professionals';
import { index as shifts, mine as myShifts } from '@/routes/shifts';
import { index as units } from '@/routes/units';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Visão geral',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Unidades',
        href: units(),
        icon: Building2,
    },
];

export function AppSidebar() {
    const {
        currentTenant,
        canSwitchTenant,
        canManagePeople,
        canViewPolicies,
        canViewProfessionals,
        canManageJobFunctions,
        canViewShifts,
        canImport,
        canViewMyShifts,
    } = usePage().props;
    const navItems: NavItem[] = [
        ...mainNavItems,
        ...(canViewMyShifts
            ? [
                  {
                      title: 'Meus plantões',
                      href: myShifts(),
                      icon: CalendarCheck,
                  },
              ]
            : []),
        ...(canViewShifts
            ? [{ title: 'Escala', href: shifts(), icon: CalendarDays }]
            : []),
        ...(canViewProfessionals
            ? [
                  {
                      title: 'Profissionais',
                      href: professionals(),
                      icon: Stethoscope,
                  },
              ]
            : []),
        ...(canManageJobFunctions
            ? [
                  {
                      title: 'Funções',
                      href: jobFunctions(),
                      icon: BriefcaseMedical,
                  },
              ]
            : []),
        ...(canManagePeople
            ? [{ title: 'Pessoas', href: people(), icon: Users }]
            : []),
        ...(canImport
            ? [{ title: 'Importações', href: imports(), icon: FileUp }]
            : []),
        ...(canViewPolicies
            ? [{ title: 'Regras', href: policies(), icon: SlidersHorizontal }]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    {currentTenant && (
                        <SidebarMenuItem>
                            {canSwitchTenant ? (
                                <SidebarMenuButton
                                    asChild
                                    tooltip={{
                                        children: `Instituição: ${currentTenant.name}. Trocar`,
                                    }}
                                >
                                    <Link href={selectTenant()}>
                                        <ArrowLeftRight aria-hidden="true" />
                                        <span className="truncate">
                                            {currentTenant.name}
                                            <span className="sr-only">
                                                {' '}
                                                (trocar instituição)
                                            </span>
                                        </span>
                                    </Link>
                                </SidebarMenuButton>
                            ) : (
                                <p className="truncate px-2 text-xs text-sidebar-foreground/75 group-data-[collapsible=icon]:hidden">
                                    {currentTenant.name}
                                </p>
                            )}
                        </SidebarMenuItem>
                    )}
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
