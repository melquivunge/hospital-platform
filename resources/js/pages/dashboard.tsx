import { Head, Link, usePage } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import {
    AlarmClock,
    Building2,
    CalendarCheck,
    ChevronRight,
    CircleCheck,
    CircleDashed,
    ClipboardPlus,
    FileHeart,
    Hourglass,
    LayoutList,
    ListOrdered,
    MapPinned,
    Megaphone,
    Plus,
    Repeat,
    Siren,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Page, PageHeader } from '@/components/page';
import { formatDay } from '@/components/shift-display';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { queue } from '@/routes/appointments';
import { index as billingAccounts } from '@/routes/billing-accounts';
import { index as emergency } from '@/routes/emergency';
import { index as examOverdue } from '@/routes/exam-overdue';
import { index as requests } from '@/routes/requests';
import { mine as myShifts } from '@/routes/shifts';
import { index as susAih } from '@/routes/sus-aih';
import { index as surgeryOverdue } from '@/routes/surgery-overdue';
import { create as createUnit, index as units } from '@/routes/units';
import { index as vacancies } from '@/routes/vacancies';
import { index as visits } from '@/routes/visits';

type Stats = { units: number; sectors: number; incomplete: number };

type NextShift = {
    id: number;
    date: string;
    start: string;
    end: string;
    unit: string;
    sector: string | null;
    function: string | null;
};

type Pending = { requests: number; occurrences: number };

type Shortcut = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
};

export default function Dashboard({
    stats,
    canCreateUnit,
    nextShift,
    pending,
}: {
    stats: Stats;
    canCreateUnit: boolean;
    nextShift: NextShift | null;
    pending: Pending | null;
}) {
    const {
        currentTenant,
        canViewMyShifts,
        canAttend,
        canBookAppointments,
        canViewEmergency,
        canUseBilling,
        canViewExamOverdue,
    } = usePage().props;

    // The day's work of each role, one click away; the full list stays in the menu.
    const shortcuts: Shortcut[] = [
        ...(canViewMyShifts
            ? [{ title: 'Vagas abertas', href: vacancies(), icon: Megaphone }]
            : []),
        ...(canAttend
            ? [
                  {
                      title: 'Meus atendimentos',
                      href: visits(),
                      icon: ClipboardPlus,
                  },
              ]
            : []),
        ...(canBookAppointments
            ? [{ title: 'Fila do dia', href: queue(), icon: ListOrdered }]
            : []),
        ...(canViewEmergency
            ? [{ title: 'Urgência', href: emergency(), icon: Siren }]
            : []),
        ...(canViewExamOverdue
            ? [
                  {
                      title: 'Exames atrasados',
                      href: examOverdue(),
                      icon: AlarmClock,
                  },
                  {
                      title: 'Cirurgias paradas',
                      href: surgeryOverdue(),
                      icon: Hourglass,
                  },
              ]
            : []),
        ...(canUseBilling
            ? [
                  {
                      title: 'Contas de convênio',
                      href: billingAccounts(),
                      icon: Wallet,
                  },
                  { title: 'AIH (SUS)', href: susAih(), icon: FileHeart },
              ]
            : []),
    ];

    const steps = [
        {
            title: 'Cadastrar unidades',
            description: 'Hospitais da instituição, com UF e fuso horário.',
            done: stats.units > 0,
            action: 'Ver unidades',
            adminOnly: true,
        },
        {
            title: 'Completar cadastros',
            description:
                'Unidades sem UF ou fuso horário não poderão publicar escalas.',
            done: stats.units > 0 && stats.incomplete === 0,
            action: 'Revisar unidades',
            adminOnly: true,
        },
        {
            title: 'Organizar setores',
            description: 'Setores de cada unidade, como UTI e pronto-socorro.',
            done: stats.sectors > 0,
            action: 'Abrir unidades',
        },
        // Only show as pending what this role can actually resolve.
    ].filter((step) => !step.adminOnly || canCreateUnit);
    const setupDone = steps.every((step) => step.done);

    return (
        <>
            <Head title="Visão geral" />
            <Page>
                <PageHeader
                    eyebrow={currentTenant?.name ?? 'Instituição'}
                    title="Visão geral"
                    description="O que espera por você hoje e a estrutura da instituição."
                    actions={
                        canCreateUnit && (
                            <Button asChild className="min-h-11">
                                <Link href={createUnit()}>
                                    <Plus aria-hidden="true" />
                                    Nova unidade
                                </Link>
                            </Button>
                        )
                    }
                />

                {canViewMyShifts && (
                    <section
                        aria-labelledby="next-shift-heading"
                        className="mb-8"
                    >
                        <h2
                            id="next-shift-heading"
                            className="text-base font-semibold tracking-tight"
                        >
                            Seu próximo plantão
                        </h2>
                        {nextShift ? (
                            <Link
                                href={myShifts()}
                                className="mt-3 flex items-center gap-4 rounded-lg border bg-card px-5 py-4 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <CalendarCheck
                                    className="size-5 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                <span className="grid min-w-0 flex-1 gap-0.5">
                                    <span className="font-medium first-letter:uppercase">
                                        {formatDay(nextShift.date)},{' '}
                                        <span className="tabular-nums">
                                            {nextShift.start} – {nextShift.end}
                                        </span>
                                    </span>
                                    <span className="truncate text-sm text-muted-foreground">
                                        {[
                                            nextShift.unit,
                                            nextShift.sector,
                                            nextShift.function,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </span>
                                <ChevronRight
                                    className="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </Link>
                        ) : (
                            <div className="mt-3 flex flex-wrap items-center gap-4 rounded-lg border bg-card px-5 py-4">
                                <p className="min-w-0 flex-1 text-sm text-muted-foreground">
                                    Nenhum plantão publicado para você.
                                </p>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link href={vacancies()}>
                                        Ver vagas abertas
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </section>
                )}

                {pending && (
                    <section aria-labelledby="pending-heading" className="mb-8">
                        <h2
                            id="pending-heading"
                            className="text-base font-semibold tracking-tight"
                        >
                            Aguardando você
                        </h2>
                        <ul className="mt-3 divide-y rounded-lg border bg-card">
                            <PendingRow
                                icon={Repeat}
                                count={pending.requests}
                                one="troca ou repasse para aprovar"
                                many="trocas ou repasses para aprovar"
                                none="Nenhuma troca ou repasse para aprovar"
                            />
                            <PendingRow
                                icon={MapPinned}
                                count={pending.occurrences}
                                one="ocorrência de presença em aberto"
                                many="ocorrências de presença em aberto"
                                none="Nenhuma ocorrência de presença em aberto"
                            />
                        </ul>
                    </section>
                )}

                {shortcuts.length > 0 && (
                    <section
                        aria-labelledby="shortcuts-heading"
                        className="mb-8"
                    >
                        <h2
                            id="shortcuts-heading"
                            className="text-base font-semibold tracking-tight"
                        >
                            Seu trabalho
                        </h2>
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {shortcuts.map((shortcut) => (
                                <li key={shortcut.title}>
                                    <Link
                                        href={shortcut.href}
                                        className="flex min-h-11 items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm font-medium transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <shortcut.icon
                                            className="size-4 shrink-0 text-primary"
                                            aria-hidden="true"
                                        />
                                        <span className="flex-1">
                                            {shortcut.title}
                                        </span>
                                        <ChevronRight
                                            className="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section aria-labelledby="structure-heading">
                    <h2
                        id="structure-heading"
                        className="text-base font-semibold tracking-tight"
                    >
                        Estrutura
                    </h2>
                    <dl className="mt-3 grid gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-3">
                        <Stat
                            icon={<Building2 aria-hidden="true" />}
                            label="Unidades"
                            value={stats.units}
                            hint="Visíveis para o seu perfil"
                        />
                        <Stat
                            icon={<LayoutList aria-hidden="true" />}
                            label="Setores"
                            value={stats.sectors}
                            hint="Somados entre as unidades"
                        />
                        <Stat
                            icon={<TriangleAlert aria-hidden="true" />}
                            label="Cadastros incompletos"
                            value={stats.incomplete}
                            hint={
                                stats.incomplete > 0
                                    ? 'Faltam UF ou fuso horário'
                                    : 'Nenhuma pendência'
                            }
                            warning={stats.incomplete > 0}
                        />
                    </dl>
                </section>

                {!setupDone && (
                    <section aria-labelledby="steps-heading" className="mt-8">
                        <h2
                            id="steps-heading"
                            className="text-base font-semibold tracking-tight"
                        >
                            Para começar
                        </h2>
                        <ol className="mt-3 divide-y rounded-lg border bg-card">
                            {steps.map((step) => (
                                <li
                                    key={step.title}
                                    className="flex flex-wrap items-center gap-4 px-5 py-4"
                                >
                                    {step.done ? (
                                        <CircleCheck
                                            className="size-5 shrink-0 text-primary"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <CircleDashed
                                            className="size-5 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">
                                            {step.title}
                                            <span className="sr-only">
                                                {step.done
                                                    ? ' (concluído)'
                                                    : ' (pendente)'}
                                            </span>
                                        </p>
                                        <p className="mt-0.5 text-sm text-muted-foreground">
                                            {step.description}
                                        </p>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        asChild
                                        className="min-h-11"
                                    >
                                        <Link href={units()}>
                                            {step.action}
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}
            </Page>
        </>
    );
}

function PendingRow({
    icon: Icon,
    count,
    one,
    many,
    none,
}: {
    icon: LucideIcon;
    count: number;
    one: string;
    many: string;
    none: string;
}) {
    if (count === 0) {
        return (
            <li className="flex items-center gap-3 px-5 py-4 text-sm text-muted-foreground">
                <CircleCheck
                    className="size-4 shrink-0 text-primary"
                    aria-hidden="true"
                />
                {none}
            </li>
        );
    }

    return (
        <li>
            <Link
                href={requests()}
                className="flex min-h-11 items-center gap-3 px-5 py-4 text-sm transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <Icon
                    className="size-4 shrink-0 text-warning"
                    aria-hidden="true"
                />
                <span className="flex-1">
                    <strong className="font-semibold tabular-nums">
                        {count}
                    </strong>{' '}
                    {count === 1 ? one : many}
                </span>
                <span className="text-sm font-medium text-primary">
                    Resolver
                </span>
                <ChevronRight
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            </Link>
        </li>
    );
}

function Stat({
    icon,
    label,
    value,
    hint,
    warning = false,
}: {
    icon: ReactNode;
    label: string;
    value: number;
    hint: string;
    warning?: boolean;
}) {
    return (
        <div className="bg-card p-5">
            <dt className="flex items-center gap-2 text-sm text-muted-foreground [&_svg]:size-4">
                {icon}
                {label}
            </dt>
            <dd className="mt-3 text-3xl font-semibold tracking-tight tabular-nums">
                {value}
            </dd>
            <dd
                className={cn(
                    'mt-1 text-xs',
                    warning
                        ? 'font-medium text-warning'
                        : 'text-muted-foreground',
                )}
            >
                {hint}
            </dd>
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Visão geral', href: dashboard() }],
};
