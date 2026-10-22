import { Head, Link } from '@inertiajs/react';
import { BedSingle } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/ward';

type Item = {
    id: number;
    patient: string | null;
    unit: string | null;
    bed: string | null;
    status: 'requested' | 'admitted';
    responsible: string | null;
    mine: boolean;
    /** Step 4b.4: late doses and the next time of the active prescription. */
    doses: { late: number; next: string | null } | null;
};

/** Inpatients the professional cares for (step 4.4b): as responsible doctor or on the bed's sector team. */
export default function Ward({ admissions }: { admissions: Item[] }) {
    return (
        <>
            <Head title="Internados" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Internação"
                    title="Internados"
                    description="Pacientes internados nos setores em que você tem habilitação e aqueles de quem você é o médico responsável. O prontuário abre enquanto o paciente estiver lá, e cada leitura fica registrada."
                />
                {admissions.length === 0 ? (
                    <div className="rounded-xl border bg-card px-5 py-12 text-center">
                        <BedSingle
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Nenhum paciente internado</p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {admissions.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm"
                            >
                                <span className="grid gap-0.5">
                                    <span className="font-medium">
                                        {item.patient}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {item.status === 'requested'
                                            ? 'Aguardando leito'
                                            : item.bed}{' '}
                                        · {item.unit}
                                        {item.mine
                                            ? ' · você é o responsável'
                                            : ` · responsável: ${item.responsible}`}
                                    </span>
                                    {item.doses && (
                                        <span className="flex flex-wrap gap-2 pt-1">
                                            {item.doses.late > 0 && (
                                                <span className="rounded-full bg-destructive px-2.5 py-0.5 text-xs font-medium text-white">
                                                    {item.doses.late === 1
                                                        ? '1 horário atrasado'
                                                        : `${item.doses.late} horários atrasados`}
                                                </span>
                                            )}
                                            {item.doses.next && (
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    Próximo medicamento às{' '}
                                                    {item.doses.next}
                                                </span>
                                            )}
                                        </span>
                                    )}
                                </span>
                                <Button asChild className="min-h-11">
                                    <Link
                                        href={show(item.id)}
                                        aria-label={`Abrir prontuário: ${item.patient}`}
                                    >
                                        Prontuário
                                    </Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

Ward.layout = {
    breadcrumbs: [{ title: 'Internados', href: index() }],
};
