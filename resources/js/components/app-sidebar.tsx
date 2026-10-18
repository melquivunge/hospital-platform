import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BriefcaseMedical,
    CalendarCheck,
    CalendarDays,
    GitMerge,
    ChartColumn,
    FileUp,
    Building2,
    LayoutGrid,
    Megaphone,
    Repeat,
    SlidersHorizontal,
    Stethoscope,
    UsersRound,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as selectTenant } from '@/routes/current-tenant';
import { index as imports } from '@/routes/imports';
import { index as jobFunctions } from '@/routes/job-functions';
import { index as patientMerges } from '@/routes/patient-merges';
import { index as patients } from '@/routes/patients';
import { index as people } from '@/routes/people';
import { index as policies } from '@/routes/policies';
import { index as reports } from '@/routes/reports';
import { index as requests } from '@/routes/requests';
import { index as professionals } from '@/routes/professionals';
import { index as shifts, mine as myShifts } from '@/routes/shifts';
import { index as units } from '@/routes/units';
import { index as vacancies } from '@/routes/vacancies';
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
        canViewRequests,
        canViewPatients,
        canMergePatients,
    } = usePage().props;
    const navItems: NavItem[] = [
        ...mainNavItems,
        ...(canViewPatients
            ? [{ title: 'Pacientes', href: patients(), icon: UsersRound }]
            : []),
        ...(canMergePatients
            ? [
                  {
                      title: 'Unificar cadastros',
                      href: patientMerges(),
                      icon: GitMerge,
                  },
              ]
            : []),
        ...(canViewMyShifts
            ? [
                  {
                      title: 'Meus plantões',
                      href: myShifts(),
                      icon: CalendarCheck,
                  },
                  { title: 'Vagas', href: vacancies(), icon: Megaphone },
              ]
            : []),
        ...(canViewRequests
            ? [{ title: 'Solicitações', href: requests(), icon: Repeat }]
            : []),
        ...(canViewShifts
            ? [
                  { title: 'Escala', href: shifts(), icon: CalendarDays },
                  { title: 'Relatórios', href: reports(), icon: ChartColumn },
              ]
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
        </Sidebar>
    );
}
