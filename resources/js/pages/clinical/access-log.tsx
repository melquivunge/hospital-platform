import { Head } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/page';
import { index } from '@/routes/clinical-access';

type Emergency = {
    id: number;
    grantedAt: string;
    expiresAt: string;
    professional: string | null;
    patient: string;
    unit: string | null;
    justification: string;
};

type Read = {
    id: number;
    readAt: string;
    reason: 'visit' | 'emergency' | 'admission' | 'pharmacy' | 'exam';
    user: string;
    patient: string;
};

const reasonLabels: Record<Read['reason'], string> = {
    visit: 'Atendimento',
    emergency: 'Emergência',
    admission: 'Internação',
    pharmacy: 'Farmácia (só a prescrição)',
    exam: 'Execução de exame',
};

/** Who opened which record and why (step 3.6b), never clinical content. */
export default function AccessLog({
    emergencies,
    reads,
    limit,
}: {
    emergencies: Emergency[];
    reads: Read[] | null;
    limit: number;
}) {
    const cut = (count: number) =>
        count >= limit && (
            <p className="text-sm text-muted-foreground">
                Mostrando os {limit} mais recentes do período.
            </p>
        );

    return (
        <>
            <Head title="Acessos ao prontuário" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Auditoria"
                    title="Acessos ao prontuário"
                    description="Últimos 30 dias. Mostra quem acessou, quando e por quê; o conteúdo do prontuário não aparece aqui."
                />
                <div className="grid gap-8">
                    <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
                        <h2 className="font-semibold">Acessos de emergência</h2>
                        {cut(emergencies.length)}
                        {emergencies.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhum acesso de emergência no período.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {emergencies.map((item) => (
                                    <li
                                        key={item.id}
                                        className="grid gap-1 px-4 py-3 text-sm"
                                    >
                                        <span className="font-medium">
                                            {item.professional} → {item.patient}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {item.grantedAt} até{' '}
                                            {item.expiresAt} · {item.unit}
                                        </span>
                                        <span>
                                            Justificativa: {item.justification}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    {reads !== null && (
                        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
                            <h2 className="font-semibold">
                                Leituras do prontuário
                            </h2>
                            {cut(reads.length)}
                            {reads.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nenhuma leitura no período.
                                </p>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {reads.map((read) => (
                                        <li
                                            key={read.id}
                                            className="flex flex-wrap items-baseline justify-between gap-2 px-4 py-3 text-sm"
                                        >
                                            <span>
                                                <span className="font-medium">
                                                    {read.user}
                                                </span>{' '}
                                                leu {read.patient}
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                {new Date(
                                                    read.readAt,
                                                ).toLocaleString('pt-BR')}{' '}
                                                · {reasonLabels[read.reason]}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}
                </div>
            </Page>
        </>
    );
}

AccessLog.layout = {
    breadcrumbs: [{ title: 'Acessos ao prontuário', href: index() }],
};
