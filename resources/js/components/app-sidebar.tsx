import { Link, usePage } from '@inertiajs/react';
import {
    AlarmClock,
    Banknote,
    Bed,
    Clock,
    Database,
    HeartPulse,
    House,
    Settings,
    Syringe,
    UserRoundCheck,
    Warehouse,
    ArrowLeftRight,
    Bandage,
    BedDouble,
    BedSingle,
    BookOpen,
    Boxes,
    BriefcaseMedical,
    Building2,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarPlus,
    CalendarRange,
    ChartColumn,
    ChartLine,
    ChartPie,
    ClipboardCheck,
    ClipboardList,
    ClipboardPlus,
    FileHeart,
    FileSpreadsheet,
    FileUp,
    FlaskConical,
    Gauge,
    GitMerge,
    History,
    Hospital,
    Hourglass,
    Landmark,
    LayoutGrid,
    ListOrdered,
    Megaphone,
    Package,
    Pill,
    Receipt,
    Repeat,
    Scissors,
    ShieldCheck,
    Siren,
    SlidersHorizontal,
    Stethoscope,
    Tags,
    TestTube,
    TestTubeDiagonal,
    Users,
    UsersRound,
    Wallet,
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
import { index as examOverdue } from '@/routes/exam-overdue';
import { index as exams } from '@/routes/exams';
import { index as billingAccounts } from '@/routes/billing-accounts';
import { index as billingLots } from '@/routes/billing-lots';
import { index as susAih } from '@/routes/sus-aih';
import { index as susBpa } from '@/routes/sus-bpa';
import { index as billingReports } from '@/routes/billing-reports';
import { index as sigtap } from '@/routes/sigtap';
import { index as billingPrices } from '@/routes/billing-prices';
import { index as surgeryCatalog } from '@/routes/surgery-catalog';
import { index as stock } from '@/routes/stock';
import { index as inventories } from '@/routes/stock/inventories';
import { index as stockIndicators } from '@/routes/stock/indicators';
import { index as stockMovements } from '@/routes/stock/movements';
import { index as census } from '@/routes/census';
import { index as emergency } from '@/routes/emergency';
import { index as triageLevels } from '@/routes/triage-levels';
import { index as aihRequests } from '@/routes/aih-requests';
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
import { index as surgeryOverdue } from '@/routes/surgery-overdue';
import { mine as mySurgeries } from '@/routes/surgery-record';
import { index as surgerySchedule } from '@/routes/surgery-schedule';
import { index as units } from '@/routes/units';
import { index as vacancies } from '@/routes/vacancies';
import type { NavGroup, NavItem } from '@/types';

// Shows the items only when the person may use them.
const when = (allowed: unknown, items: NavItem[]): NavItem[] =>
    allowed ? items : [];

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
        canManageSurgeryCatalog,
        canAttend,
        canViewClinicalAccess,
        canViewAihRequests,
        canViewBeds,
        canViewCensus,
        canUsePharmacy,
        canUseBilling,
        canViewBillingReports,
        canUseExamQueue,
        canUseSurgerySchedule,
        canUseMySurgeries,
        canViewExamOverdue,
        canUseInventory,
        canViewStockIndicators,
        canViewEmergency,
        canManageAdmissions,
        canManageTriageLevels,
    } = usePage().props;
    // Grouped by the person's journey; a group without allowed items is not shown.
    const navGroups: NavGroup[] = [
        {
            label: 'Início',
            icon: House,
            items: [
                { title: 'Visão geral', href: dashboard(), icon: LayoutGrid },
            ],
        },
        {
            label: 'Meu trabalho',
            icon: UserRoundCheck,
            items: [
                ...when(canViewMyShifts, [
                    {
                        title: 'Meus plantões',
                        href: myShifts(),
                        icon: CalendarCheck,
                    },
                    { title: 'Vagas', href: vacancies(), icon: Megaphone },
                ]),
                ...when(canAttend, [
                    {
                        title: 'Meus atendimentos',
                        href: visits(),
                        icon: ClipboardPlus,
                    },
                ]),
                ...when(canUseMySurgeries, [
                    {
                        title: 'Minhas cirurgias',
                        href: mySurgeries(),
                        icon: Scissors,
                    },
                ]),
            ],
        },
        {
            label: 'Escalas',
            icon: Clock,
            items: [
                ...when(canViewShifts, [
                    { title: 'Escala', href: shifts(), icon: CalendarDays },
                ]),
                ...when(canViewRequests, [
                    { title: 'Solicitações', href: requests(), icon: Repeat },
                ]),
                ...when(canViewShifts, [
                    {
                        title: 'Relatórios da escala',
                        href: reports(),
                        icon: ChartColumn,
                    },
                ]),
            ],
        },
        {
            label: 'Atendimento',
            icon: HeartPulse,
            items: [
                ...when(canViewPatients, [
                    { title: 'Pacientes', href: patients(), icon: UsersRound },
                ]),
                ...when(canBookAppointments, [
                    { title: 'Fila do dia', href: queue(), icon: ListOrdered },
                    {
                        title: 'Agendamentos',
                        href: appointments(),
                        icon: CalendarPlus,
                    },
                ]),
                ...when(canManageSchedules, [
                    {
                        title: 'Agendas',
                        href: schedules(),
                        icon: CalendarClock,
                    },
                ]),
                ...when(canViewEmergency, [
                    { title: 'Urgência', href: emergency(), icon: Siren },
                ]),
                ...when(canUseExamQueue, [
                    {
                        title: 'Fila de exames',
                        href: examQueue(),
                        icon: TestTube,
                    },
                ]),
                ...when(canViewExamOverdue, [
                    {
                        title: 'Exames atrasados',
                        href: examOverdue(),
                        icon: AlarmClock,
                    },
                ]),
            ],
        },
        {
            label: 'Internação',
            icon: Bed,
            items: [
                ...when(canAttend, [
                    { title: 'Internados', href: ward(), icon: BedSingle },
                ]),
                ...when(canViewBeds, [
                    { title: 'Leitos', href: beds(), icon: BedDouble },
                ]),
                ...when(canManageAdmissions, [
                    {
                        title: 'Internações',
                        href: admissions(),
                        icon: Hospital,
                    },
                ]),
                ...when(canViewCensus, [
                    { title: 'Censo', href: census(), icon: ChartPie },
                ]),
                ...when(canViewAihRequests, [
                    {
                        title: 'Laudos da AIH',
                        href: aihRequests(),
                        icon: ClipboardList,
                    },
                ]),
            ],
        },
        {
            label: 'Cirurgia',
            icon: Syringe,
            items: [
                ...when(canUseSurgerySchedule, [
                    {
                        title: 'Agenda cirúrgica',
                        href: surgerySchedule(),
                        icon: CalendarRange,
                    },
                ]),
                // Same viewers as the overdue exams: unit managers and network admins (SurgeryOverdueController).
                ...when(canViewExamOverdue, [
                    {
                        title: 'Cirurgias paradas',
                        href: surgeryOverdue(),
                        icon: Hourglass,
                    },
                ]),
            ],
        },
        {
            label: 'Farmácia e estoque',
            icon: Warehouse,
            items: [
                ...when(canUsePharmacy, [
                    { title: 'Farmácia', href: pharmacy(), icon: FlaskConical },
                ]),
                ...when(canUseInventory, [
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
                ]),
                ...when(canViewStockIndicators, [
                    {
                        title: 'Indicadores de estoque',
                        href: stockIndicators(),
                        icon: ChartLine,
                    },
                ]),
            ],
        },
        {
            label: 'Faturamento',
            icon: Banknote,
            items: [
                ...when(canUseBilling, [
                    { title: 'Contas', href: billingAccounts(), icon: Wallet },
                    { title: 'Lotes TISS', href: billingLots(), icon: Package },
                    {
                        title: 'BPA (SUS)',
                        href: susBpa(),
                        icon: FileSpreadsheet,
                    },
                    { title: 'AIH (SUS)', href: susAih(), icon: FileHeart },
                ]),
                ...when(canViewBillingReports, [
                    {
                        title: 'Relatórios de faturamento',
                        href: billingReports(),
                        icon: Receipt,
                    },
                ]),
            ],
        },
        {
            label: 'Cadastros',
            icon: Database,
            items: [
                { title: 'Unidades', href: units(), icon: Building2 },
                ...when(canViewProfessionals, [
                    {
                        title: 'Profissionais',
                        href: professionals(),
                        icon: Stethoscope,
                    },
                ]),
                ...when(canManageJobFunctions, [
                    {
                        title: 'Funções',
                        href: jobFunctions(),
                        icon: BriefcaseMedical,
                    },
                ]),
                ...when(canManageInsurers, [
                    { title: 'Convênios', href: insurers(), icon: Landmark },
                ]),
                ...when(canManageMedications, [
                    { title: 'Medicamentos', href: medications(), icon: Pill },
                    { title: 'Preços', href: billingPrices(), icon: Tags },
                    { title: 'Tabela SIGTAP', href: sigtap(), icon: BookOpen },
                ]),
                ...when(canManageExams, [
                    {
                        title: 'Exames',
                        href: exams(),
                        icon: TestTubeDiagonal,
                    },
                ]),
                ...when(canManageSurgeryCatalog, [
                    {
                        title: 'Procedimentos, salas e materiais',
                        href: surgeryCatalog(),
                        icon: Bandage,
                    },
                ]),
                ...when(canManageTriageLevels, [
                    {
                        title: 'Classificação de risco',
                        href: triageLevels(),
                        icon: Gauge,
                    },
                ]),
            ],
        },
        {
            label: 'Administração',
            icon: Settings,
            items: [
                ...when(canManagePeople, [
                    { title: 'Pessoas', href: people(), icon: Users },
                ]),
                ...when(canMergePatients, [
                    {
                        title: 'Unificar cadastros',
                        href: patientMerges(),
                        icon: GitMerge,
                    },
                ]),
                ...when(canViewClinicalAccess, [
                    {
                        title: 'Acessos ao prontuário',
                        href: clinicalAccess(),
                        icon: ShieldCheck,
                    },
                ]),
                ...when(canImport, [
                    { title: 'Importações', href: imports(), icon: FileUp },
                ]),
                ...when(canViewPolicies, [
                    {
                        title: 'Regras',
                        href: policies(),
                        icon: SlidersHorizontal,
                    },
                ]),
            ],
        },
    ].filter((group) => group.items.length > 0);

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
                                    className="max-md:h-11"
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
                <NavMain groups={navGroups} />
            </SidebarContent>
        </Sidebar>
    );
}
