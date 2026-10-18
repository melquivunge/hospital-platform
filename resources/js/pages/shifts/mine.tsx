import { Form, Head, Link, router } from '@inertiajs/react';
import { CalendarCheck, MapPin } from 'lucide-react';
import { useState } from 'react';
import VacancyController from '@/actions/App/Http/Controllers/VacancyController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { ShiftSummary } from '@/components/shift-display';
import { formatDay, PeriodLabel, timeRange } from '@/components/shift-display';
import { index as requests } from '@/routes/requests';
import { attendance, mine } from '@/routes/shifts';
import { create as createSwap } from '@/routes/shifts/swap';
import { create as createTransfer } from '@/routes/shifts/transfer';

type MyShift = ShiftSummary & {
    unit: string;
    upcoming: boolean;
    requestPending: boolean;
    checkIn: string | null;
    checkOut: string | null;
    ended: boolean;
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
                                                <AttendanceActions
                                                    shift={shift}
                                                />
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
 * Entry and exit by location (step 8a): one reading taken when the button is pressed, never continuous
 * tracking. The server decides and keeps the attempt; the reason is shown here so the person can retry.
 */
function AttendanceActions({ shift }: { shift: MyShift }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!shift.checkIn && shift.ended) {
        return null;
    }

    const status = [
        shift.checkIn && `Entrada às ${shift.checkIn}`,
        shift.checkOut && `Saída às ${shift.checkOut}`,
    ]
        .filter(Boolean)
        .join(' · ');
    const kind = shift.checkIn ? 'check_out' : 'check_in';

    function register() {
        if (!('geolocation' in navigator)) {
            setError('Este aparelho não informa a localização.');

            return;
        }

        setBusy(true);
        setError(null);
        // One key per press: a duplicated submission of the same press returns the first result.
        const idempotencyKey = crypto.randomUUID();
        navigator.geolocation.getCurrentPosition(
            (position) =>
                router.post(
                    attendance.url(shift.id),
                    {
                        kind,
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                        measured_at: Math.round(position.timestamp),
                        idempotency_key: idempotencyKey,
                    },
                    {
                        preserveScroll: true,
                        onError: (errors) =>
                            setError(
                                Object.values(errors)[0] ??
                                    'Não foi possível registrar.',
                            ),
                        onFinish: () => setBusy(false),
                    },
                ),
            (failure) => {
                setBusy(false);
                setError(
                    failure.code === failure.PERMISSION_DENIED
                        ? 'Permita o acesso à localização do aparelho para registrar.'
                        : 'Não foi possível obter a localização. Tente de novo.',
                );
            },
            { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 },
        );
    }

    return (
        <div className="grid gap-2 border-t pt-3">
            {status && <p className="font-medium text-primary">{status}</p>}
            {!shift.checkOut && (
                <Button
                    type="button"
                    onClick={register}
                    disabled={busy}
                    className="min-h-11 justify-self-start"
                >
                    {busy ? <Spinner /> : <MapPin aria-hidden="true" />}
                    {shift.checkIn ? 'Registrar saída' : 'Registrar entrada'}
                </Button>
            )}
            <InputError message={error ?? undefined} />
        </div>
    );
}

/**
 * Announce for replacement (step 6c) or transfer to a colleague (step 7a). The professional stays
 * responsible until the coordinator decides.
 */
function AnnounceForm({ shift }: { shift: MyShift }) {
    if (shift.checkIn) {
        return null;
    }

    if (shift.requestPending) {
        return (
            <p className="border-t pt-3 font-medium text-primary">
                Solicitação pendente (transferência ou troca).{' '}
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
                            <>
                                <Button
                                    variant="outline"
                                    asChild
                                    className="min-h-11"
                                >
                                    <Link href={createTransfer(shift.id)}>
                                        Transferir a um colega
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    asChild
                                    className="min-h-11"
                                >
                                    <Link href={createSwap(shift.id)}>
                                        Trocar com um colega
                                    </Link>
                                </Button>
                            </>
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
