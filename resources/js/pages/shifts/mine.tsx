import { Form, Head, Link } from '@inertiajs/react';
import { CalendarCheck } from 'lucide-react';
import VacancyController from '@/actions/App/Http/Controllers/VacancyController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { ShiftSummary } from '@/components/shift-display';
import { formatDay, PeriodLabel, timeRange } from '@/components/shift-display';
import { index as requests } from '@/routes/requests';
import { mine } from '@/routes/shifts';
import { create as createTransfer } from '@/routes/shifts/transfer';

type MyShift = ShiftSummary & {
    unit: string;
    upcoming: boolean;
    transferPending: boolean;
};

/** Professional's own published shifts, grouped by local start day (reference IMG_0911). */
export default function MyShifts({
    professional,
    shifts,
}: {
    professional: string;
    shifts: MyShift[];
}) {
    const days = [...new Set(shifts.map((shift) => shift.date))];

    return (
        <>
            <Head title="Meus plantões" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={professional}
                    title="Meus plantões"
                    description="Plantões publicados para você, no horário de cada unidade. Rascunhos não aparecem aqui."
                />

                {shifts.length === 0 ? (
                    <div className="rounded-lg border bg-card px-5 py-12 text-center">
                        <CalendarCheck
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">
                            Nenhum plantão publicado para você
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-6">
                        {days.map((day) => (
                            <section key={day} aria-labelledby={`my-${day}`}>
                                <h2
                                    id={`my-${day}`}
                                    className="mb-2 text-sm font-semibold first-letter:uppercase"
                                >
                                    {formatDay(day)}
                                </h2>
                                <ul className="grid gap-3">
                                    {shifts
                                        .filter((shift) => shift.date === day)
                                        .map((shift) => (
                                            <li
                                                key={shift.id}
                                                className="grid gap-1 rounded-lg border bg-card p-4 text-sm"
                                            >
                                                <p className="font-medium">
                                                    {shift.unit} ·{' '}
                                                    {shift.sector}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {shift.function}
                                                </p>
                                                <p className="mt-1 flex flex-wrap items-center justify-between gap-2 border-t pt-2">
                                                    <PeriodLabel
                                                        period={shift.period}
                                                    />
                                                    <span className="tabular-nums">
                                                        {timeRange(shift)}
                                                    </span>
                                                </p>
                                                {shift.upcoming && (
                                                    <AnnounceForm
                                                        shift={shift}
                                                    />
                                                )}
                                            </li>
                                        ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
            </Page>
        </>
    );
}

/**
 * Announce for replacement (step 6c) or transfer to a colleague (step 7a). The professional stays
 * responsible until the coordinator decides.
 */
function AnnounceForm({ shift }: { shift: MyShift }) {
    if (shift.transferPending) {
        return (
            <p className="border-t pt-3 font-medium text-primary">
                Transferência pendente.{' '}
                <Link href={requests()} className="underline">
                    Acompanhar em Solicitações
                </Link>
            </p>
        );
    }

    const action = shift.announced
        ? VacancyController.cancelAnnouncement.form(shift.id)
        : VacancyController.announce.form(shift.id);

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="grid gap-2 border-t pt-3"
        >
            {({ processing, errors }) => (
                <>
                    {shift.announced && (
                        <p className="font-medium text-primary">
                            Anunciado para substituição. Você continua
                            responsável até o coordenador escolher.
                        </p>
                    )}
                    <InputError message={errors.shift} />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11"
                        >
                            {processing && <Spinner />}
                            {shift.announced
                                ? 'Cancelar anúncio'
                                : 'Anunciar para substituição'}
                        </Button>
                        {!shift.announced && (
                            <Button
                                variant="outline"
                                asChild
                                className="min-h-11"
                            >
                                <Link href={createTransfer(shift.id)}>
                                    Transferir a um colega
                                </Link>
                            </Button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

MyShifts.layout = {
    breadcrumbs: [{ title: 'Meus plantões', href: mine() }],
};
