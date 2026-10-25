import { Head, Link } from '@inertiajs/react';
import SurgeryRecordController from '@/actions/App/Http/Controllers/SurgeryRecordController';
import { Page, PageHeader } from '@/components/page';

/**
 * "Minhas cirurgias" (step 5b.3b): the open surgeries where the user is on the team. Opening one reads the record
 * and is logged.
 */
export default function MySurgeries({
    surgeries,
    roles,
    lateralities,
    urgencies,
    addendumDays,
}: {
    surgeries: {
        id: number;
        patient: string | null;
        place: string | null;
        procedure: string;
        laterality: string;
        urgency: string;
        room: string | null;
        start: string | null;
        role: string | null;
        status: string;
    }[];
    roles: Record<string, string>;
    lateralities: Record<string, string>;
    urgencies: Record<string, string>;
    addendumDays: number;
}) {
    return (
        <>
            <Head title="Minhas cirurgias" />
            <Page>
                <PageHeader
                    title="Minhas cirurgias"
                    description="Cirurgias abertas em que você está na equipe. Abrir uma abre o prontuário do paciente e fica registrado na trilha de acesso."
                />
                {surgeries.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Nenhuma cirurgia aberta com você na equipe.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {surgeries.map((surgery) => (
                            <li key={surgery.id}>
                                <Link
                                    href={SurgeryRecordController.show.url(
                                        surgery.id,
                                    )}
                                    className="flex min-h-11 flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="grid min-w-0 gap-0.5">
                                        <span className="font-medium">
                                            {surgery.patient}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {surgery.procedure}
                                            {surgery.laterality !== 'none' &&
                                                ` · ${lateralities[surgery.laterality]}`}{' '}
                                            ·{' '}
                                            {surgery.start
                                                ? `${surgery.room}, ${surgery.start}`
                                                : 'aguardando agendamento'}
                                            {surgery.role &&
                                                ` · ${roles[surgery.role]}`}
                                            {surgery.status === 'completed' &&
                                                ` · concluída: adendo por ${addendumDays} dias`}
                                        </span>
                                    </span>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${surgery.urgency === 'urgent' ? 'bg-destructive text-white' : 'bg-muted'}`}
                                    >
                                        {urgencies[surgery.urgency]}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}
