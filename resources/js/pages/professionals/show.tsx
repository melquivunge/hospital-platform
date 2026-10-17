import { Head, Link } from '@inertiajs/react';
import { History, Pencil } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { councilLabel } from '@/pages/professionals/index';
import { edit, index } from '@/routes/professionals';

type Professional = {
    id: number;
    name: string;
    baseUnit: string | null;
    council: string | null;
    council_number: string | null;
    council_uf: string | null;
    external_id: string | null;
    email: string | null;
    phone: string | null;
};

type HistoryEntry = {
    id: number;
    action: 'professional.created' | 'professional.base_unit_changed';
    from: string | null;
    to: string | null;
    reason: string | null;
    actor: string | null;
    occurredAt: string;
};

export default function ProfessionalShow({
    professional,
    history,
    canUpdate,
}: {
    professional: Professional;
    history: HistoryEntry[];
    canUpdate: boolean;
}) {
    return (
        <>
            <Head title={professional.name} />
            <Page>
                <PageHeader
                    eyebrow="Profissional"
                    title={professional.name}
                    actions={
                        canUpdate && (
                            <Button
                                variant="outline"
                                asChild
                                className="min-h-11"
                            >
                                <Link href={edit(professional.id)}>
                                    <Pencil aria-hidden="true" />
                                    Editar profissional
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="grid gap-8 lg:grid-cols-3">
                    <section
                        aria-labelledby="history-heading"
                        className="overflow-hidden rounded-lg border bg-card lg:col-span-2"
                    >
                        <h2
                            id="history-heading"
                            className="flex items-center gap-2 border-b px-5 py-4 font-semibold tracking-tight"
                        >
                            <History
                                className="size-4 text-primary"
                                aria-hidden="true"
                            />
                            Histórico da unidade-base
                        </h2>
                        <ol className="divide-y">
                            {history.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="px-5 py-4 text-sm"
                                >
                                    <p className="font-medium">
                                        {entry.action === 'professional.created'
                                            ? `Cadastrado com base em ${entry.to ?? 'unidade não encontrada'}`
                                            : `${entry.from ?? '—'} → ${entry.to ?? '—'}`}
                                    </p>
                                    {entry.reason && (
                                        <p className="mt-1">
                                            Motivo: {entry.reason}
                                        </p>
                                    )}
                                    <p className="mt-1 text-muted-foreground">
                                        {new Date(
                                            entry.occurredAt,
                                        ).toLocaleString('pt-BR')}
                                        {entry.actor && ` · ${entry.actor}`}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <aside aria-labelledby="details-heading">
                        <h2
                            id="details-heading"
                            className="mb-3 text-sm font-semibold tracking-tight"
                        >
                            Informações
                        </h2>
                        <dl className="divide-y rounded-lg border bg-card text-sm">
                            <Detail
                                label="Unidade-base"
                                value={professional.baseUnit}
                            />
                            <Detail
                                label="Registro"
                                value={councilLabel(professional)}
                            />
                            <Detail
                                label="Identificador externo"
                                value={professional.external_id}
                            />
                            <Detail label="E-mail" value={professional.email} />
                            <Detail
                                label="Telefone"
                                value={professional.phone}
                            />
                        </dl>
                    </aside>
                </div>
            </Page>
        </>
    );
}

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex items-start justify-between gap-3 px-4 py-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium break-all">
                {value ?? 'Não informado'}
            </dd>
        </div>
    );
}

ProfessionalShow.layout = {
    breadcrumbs: [{ title: 'Profissionais', href: index() }],
};
