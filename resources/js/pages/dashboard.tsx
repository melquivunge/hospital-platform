import { Head, Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CircleCheck,
    CircleDashed,
    LayoutList,
    Plus,
    TriangleAlert,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { create as createUnit, index as units } from '@/routes/units';

type Stats = { units: number; sectors: number; incomplete: number };

export default function Dashboard({
    stats,
    canCreateUnit,
}: {
    stats: Stats;
    canCreateUnit: boolean;
}) {
    const { currentTenant } = usePage().props;

    const steps = [
        {
            title: 'Cadastrar unidades',
            description: 'Hospitais da instituição, com UF e fuso horário.',
            done: stats.units > 0,
            href: units(),
            action: 'Ver unidades',
            adminOnly: true,
        },
        {
            title: 'Completar cadastros',
            description:
                'Unidades sem UF ou fuso horário não poderão publicar escalas.',
            done: stats.units > 0 && stats.incomplete === 0,
            href: units(),
            action: 'Revisar unidades',
            adminOnly: true,
        },
        {
            title: 'Organizar setores',
            description: 'Setores de cada unidade, como UTI e pronto-socorro.',
            done: stats.sectors > 0,
            href: units(),
            action: 'Abrir unidades',
        },
        {
            title: 'Profissionais e escalas',
            description: 'Disponível nas próximas etapas da plataforma.',
            done: false,
            upcoming: true,
        },
        // Only show as pending what this role can actually resolve.
    ].filter((step) => !step.adminOnly || canCreateUnit);

    return (
        <>
            <Head title="Visão geral" />
            <Page>
                <PageHeader
                    eyebrow={currentTenant?.name ?? 'Instituição'}
                    title="Visão geral"
                    description="Estrutura da instituição e o que ainda precisa de atenção."
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

                <section aria-labelledby="structure-heading">
                    <h2 id="structure-heading" className="sr-only">
                        Estrutura
                    </h2>
                    <dl className="grid gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-3">
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

                <section aria-labelledby="steps-heading" className="mt-10">
                    <h2
                        id="steps-heading"
                        className="text-base font-semibold tracking-tight"
                    >
                        Próximos passos
                    </h2>
                    <ol className="mt-4 divide-y rounded-lg border bg-card">
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
                                                : step.upcoming
                                                  ? ' (em breve)'
                                                  : ' (pendente)'}
                                        </span>
                                    </p>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {step.description}
                                    </p>
                                </div>
                                {step.upcoming ? (
                                    <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        Em breve
                                    </span>
                                ) : (
                                    step.href && (
                                        <Button
                                            variant="ghost"
                                            asChild
                                            className="min-h-11"
                                        >
                                            <Link href={step.href}>
                                                {step.action}
                                            </Link>
                                        </Button>
                                    )
                                )}
                            </li>
                        ))}
                    </ol>
                </section>
            </Page>
        </>
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
