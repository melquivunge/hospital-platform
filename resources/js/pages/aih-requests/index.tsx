import { Head } from '@inertiajs/react';
import AihRequestController from '@/actions/App/Http/Controllers/AihRequestController';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/aih-requests';

type Report = {
    id: number;
    admission: number;
    version: number;
    patient: string;
    unit: string | null;
    signer: string | null;
    signedAt: string;
};

/**
 * Step 6b.5a2: the AIH request reports, also after the discharge: the auditor sees the network's, a doctor the ones
 * they signed. The list has no clinical content; each PDF opened goes to the read trail.
 */
export default function AihRequests({
    auditor,
    reports,
    limit,
}: {
    auditor: boolean;
    reports: Report[];
    limit: number;
}) {
    return (
        <>
            <Head title="Laudos da AIH" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={auditor ? 'Auditoria' : 'Internação SUS'}
                    title="Laudos da AIH"
                    description={
                        auditor
                            ? 'Todos os laudos da rede, inclusive depois da alta. Cada PDF aberto fica registrado nos acessos ao prontuário.'
                            : 'Os laudos que você assinou, inclusive depois da alta. Cada PDF aberto fica registrado nos acessos ao prontuário.'
                    }
                />
                <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
                    {reports.length >= limit && (
                        <p className="text-sm text-muted-foreground">
                            Mostrando os {limit} mais recentes.
                        </p>
                    )}
                    {reports.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nenhum laudo assinado.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {reports.map((report) => (
                                <li
                                    key={report.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm"
                                >
                                    <span className="grid gap-1">
                                        <span className="font-medium">
                                            {report.patient}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {report.signedAt} · {report.unit} ·
                                            internação {report.admission}
                                            {report.version > 1 &&
                                                ` · versão ${report.version}`}
                                            {auditor && ` · ${report.signer}`}
                                        </span>
                                    </span>
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <a
                                            href={AihRequestController.pdf.url(
                                                report.id,
                                            )}
                                        >
                                            Baixar PDF
                                        </a>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}

AihRequests.layout = {
    breadcrumbs: [{ title: 'Laudos da AIH', href: index() }],
};
