import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BedDouble,
    BedSingle,
    Boxes,
    TestTube,
    BriefcaseMedical,
    CalendarCheck,
    CalendarPlus,
    ClipboardCheck,
    ClipboardPlus,
    CalendarClock,
    CalendarDays,
    GitMerge,
    History,
    ChartColumn,
    ChartLine,
    ChartPie,
    FileUp,
    Gauge,
    Hospital,
    Siren,
    Building2,
    Landmark,
    Pill,
    FlaskConical,
    LayoutGrid,
    ListOrdered,
    Megaphone,
    Repeat,
    ShieldCheck,
    SlidersHorizontal,
    Stethoscope,
    UsersRound,
    TestTubeDiagonal,
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
import { index as appointments, queue } from '@/routes/appointments';
import { index as admissions } from '@/routes/admissions';
import { index as beds } from '@/routes/beds';
import { index as pharmacy } from '@/routes/pharmacy';
import { index as examQueue } from '@/routes/exam-queue';
import { index as exams } from '@/routes/exams';
import { index as stock } from '@/routes/stock';
import { index as inventories } from '@/routes/stock/inventories';
import { index as stockIndicators } from '@/routes/stock/indicators';
import { index as stockMovements } from '@/routes/stock/movements';
import { index as census } from '@/routes/census';
import { index as emergency } from '@/routes/emergency';
import { index as triageLevels } from '@/routes/triage-levels';
import { index as clinicalAccess } from '@/routes/clinical-access';
import { index as insurers } from '@/routes/insurers';
import { index as medications } from '@/routes/medications';
import { index as visits } from '@/routes/visits';
import { index as ward } from '@/routes/ward';
import { index as patients } from '@/routes/patients';
import { index as people } from '@/routes/people';
import { index as policies } from '@/routes/policies';
import { index as reports } from '@/routes/reports';
import { index as requests } from '@/routes/requests';
import { index as schedules } from '@/routes/schedules';
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
        canManageSchedules,
        canBookAppointments,
        canManageInsurers,
        canManageMedications,
        canManageExams,
        canAttend,
        canViewClinicalAccess,
        canViewBeds,
        canViewCensus,
        canUsePharmacy,
        canUseExamQueue,
        canUseInventory,
        canViewStockIndicators,
        canViewEmergency,
        canManageAdmissions,
        canManageTriageLevels,
    } = usePage().props;
    const navItems: NavItem[] = [
        ...mainNavItems,
        ...(canViewPatients
            ? [{ title: 'Pacientes', href: patients(), icon: UsersRound }]
            : []),
        ...(canAttend
            ? [
                  {
                      title: 'Meus atendimentos',
                      href: visits(),
                      icon: ClipboardPlus,
                  },
                  { title: 'Internados', href: ward(), icon: BedSingle },
              ]
            : []),
        ...(canBookAppointments
            ? [
                  {
                      title: 'Fila do dia',
                      href: queue(),
                      icon: ListOrdered,
                  },
                  {
                      title: 'Agendamentos',
                      href: appointments(),
                      icon: CalendarPlus,
                  },
              ]
            : []),
        ...(canManageSchedules
            ? [{ title: 'Agendas', href: schedules(), icon: CalendarClock }]
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
        ...(canViewEmergency
            ? [{ title: 'Urgência', href: emergency(), icon: Siren }]
            : []),
        ...(canManageTriageLevels
            ? [
                  {
                      title: 'Classificação de risco',
                      href: triageLevels(),
                      icon: Gauge,
                  },
              ]
            : []),
        ...(canViewBeds
            ? [{ title: 'Leitos', href: beds(), icon: BedDouble }]
            : []),
        ...(canManageAdmissions
            ? [{ title: 'Internações', href: admissions(), icon: Hospital }]
            : []),
        ...(canViewCensus
            ? [{ title: 'Censo', href: census(), icon: ChartPie }]
            : []),
        ...(canUsePharmacy
            ? [{ title: 'Farmácia', href: pharmacy(), icon: FlaskConical }]
            : []),
        ...(canUseExamQueue
            ? [{ title: 'Fila de exames', href: examQueue(), icon: TestTube }]
            : []),
        ...(canUseInventory
            ? [
                  { title: 'Estoque', href: stock(), icon: Boxes },
                  {
                      title: 'Movimentos de estoque',
                      href: stockMovements(),
                      icon: History,
                  },
                  {
                      title: 'Inventários',
                      href: inventories(),
                      icon: ClipboardCheck,
                  },
              ]
            : []),
        ...(canViewStockIndicators
            ? [
                  {
                      title: 'Indicadores de estoque',
                      href: stockIndicators(),
                      icon: ChartLine,
                  },
              ]
            : []),
        ...(canViewClinicalAccess
            ? [
                  {
                      title: 'Acessos ao prontuário',
                      href: clinicalAccess(),
                      icon: ShieldCheck,
                  },
              ]
            : []),
        ...(canManageInsurers
            ? [{ title: 'Convênios', href: insurers(), icon: Landmark }]
            : []),
        ...(canManageMedications
            ? [{ title: 'Medicamentos', href: medications(), icon: Pill }]
            : []),
        ...(canManageExams
            ? [{ title: 'Exames', href: exams(), icon: TestTubeDiagonal }]
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
