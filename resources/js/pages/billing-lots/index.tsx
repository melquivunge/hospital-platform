import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import BillingLotController from '@/actions/App/Http/Controllers/BillingLotController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { money } from '@/lib/utils';
import { index } from '@/routes/billing-lots';

type ReadyGroup = {
    insurerId: number;
    insurer: string | null;
    competence: string;
    guideType: string;
    accounts: number;
    totalCents: number;
};

type Lot = {
    id: number;
    insurer: string | null;
    competence: string;
    guideType: string;
    guideCount: number;
    totalCents: number;
    status: 'generated' | 'cancelled';
    createdAt: string;
    createdBy: string | null;
    cancelledAt: string | null;
    cancelledBy: string | null;
    cancelReason: string | null;
    file: { sha256: string; generatedAt: string } | null;
    problems: string[];
    return: {
        paidCents: number;
        glossedCents: number;
        appealingCents: number;
        recoveredCents: number;
    } | null;
};

/** "2026-10" → "10/2026". */
function competenceLabel(competence: string): string {
    const [year, month] = competence.split('-');

    return `${month}/${year}`;
}

/**
 * Step 6.5b1 (ADR-0010): the unit's TISS lots. Closed insurance accounts outside any lot are grouped by insurer,
 * competence and guide type, each group generating a lot of up to 100 guides; a lot can be cancelled with a reason,
 * which releases its accounts. Numbers only: no patient here.
 */
export default function BillingLotsIndex({
    units,
    unitId,
    ready,
    lots,
    guideTypes,
    maxGuides,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    ready: ReadyGroup[];
    lots: Lot[];
    guideTypes: Record<string, string>;
    maxGuides: number;
}) {
    const unit = units.find((option) => option.id === unitId);
    // Every group's form shares the "lot" bag: its message is shown once.
    const lotError = (
        usePage().props.errors as Record<string, Record<string, string>>
    ).lot?.lot;

    return (
        <>
            <Head title="Lotes TISS" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Lotes TISS"
                    description="Contas de convênio fechadas, agrupadas por convênio, competência e tipo de guia, para gerar o lote de envio à operadora."
                />
                {units.length > 1 && (
                    <div className="mb-6 grid w-full max-w-sm gap-2">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            value={unitId}
                            onChange={(event) =>
                                router.get(
                                    index({
                                        query: { unit: event.target.value },
                                    }).url,
                                )
                            }
                            className={selectClassName}
                        >
                            {units.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                <section aria-labelledby="ready-heading" className="mb-10">
                    <h2
                        id="ready-heading"
                        className="mb-3 text-lg font-semibold tracking-tight"
                    >
                        Prontas para lote
                    </h2>
                    <InputError message={lotError} className="mb-3" />
                    {ready.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                            Nenhuma conta de convênio fechada fora de lote.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border bg-card">
                            {ready.map((group) => (
                                <li
                                    key={`${group.insurerId}-${group.competence}-${group.guideType}`}
                                >
                                    <Form
                                        {...BillingLotController.store.form()}
                                        errorBag="lot"
                                        options={{ preserveScroll: true }}
                                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                    >
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="unit_id"
                                                    value={unitId}
                                                />
                                                <input
                                                    type="hidden"
                                                    name="insurer_id"
                                                    value={group.insurerId}
                                                />
                                                <input
                                                    type="hidden"
                                                    name="competence"
                                                    value={group.competence}
                                                />
                                                <input
                                                    type="hidden"
                                                    name="guide_type"
                                                    value={group.guideType}
                                                />
                                                <span className="grid gap-0.5">
                                                    <span className="font-medium">
                                                        {group.insurer ??
                                                            'Convênio'}{' '}
                                                        ·{' '}
                                                        {competenceLabel(
                                                            group.competence,
                                                        )}
                                                    </span>
                                                    <span className="text-sm text-muted-foreground">
                                                        {guideTypes[
                                                            group.guideType
                                                        ] ??
                                                            group.guideType}{' '}
                                                        · {group.accounts}{' '}
                                                        {group.accounts === 1
                                                            ? 'conta'
                                                            : 'contas'}{' '}
                                                        ·{' '}
                                                        {money(
                                                            group.totalCents,
                                                        )}
                                                        {group.accounts >
                                                            maxGuides &&
                                                            ` · o lote leva as ${maxGuides} mais antigas`}
                                                    </span>
                                                </span>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                    className="min-h-11"
                                                    aria-label={`Gerar lote de ${group.insurer ?? 'convênio'}, ${competenceLabel(group.competence)}, ${guideTypes[group.guideType] ?? group.guideType}`}
                                                >
                                                    {processing && <Spinner />}
                                                    Gerar lote
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="lots-heading">
                    <h2
                        id="lots-heading"
                        className="mb-3 text-lg font-semibold tracking-tight"
                    >
                        Lotes gerados
                    </h2>
                    {lots.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                            Nenhum lote gerado nesta unidade.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border bg-card">
                            {lots.map((lot) => (
                                <LotItem
                                    key={lot.id}
                                    lot={lot}
                                    guideTypes={guideTypes}
                                />
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}

function LotItem({
    lot,
    guideTypes,
}: {
    lot: Lot;
    guideTypes: Record<string, string>;
}) {
    const cancelled = lot.status === 'cancelled';

    return (
        <li className="grid gap-3 px-5 py-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <span className="grid gap-0.5">
                    <span className="font-medium">
                        Lote {lot.id} · {lot.insurer ?? 'Convênio'} ·{' '}
                        {lot.competence}
                    </span>
                    <span className="text-sm text-muted-foreground">
                        {guideTypes[lot.guideType] ?? lot.guideType} ·{' '}
                        {lot.guideCount}{' '}
                        {lot.guideCount === 1 ? 'guia' : 'guias'} · gerado em{' '}
                        {lot.createdAt}
                        {lot.createdBy && ` por ${lot.createdBy}`}
                    </span>
                    {cancelled && (
                        <span className="text-sm text-muted-foreground">
                            Cancelado em {lot.cancelledAt}
                            {lot.cancelledBy && ` por ${lot.cancelledBy}`}:{' '}
                            {lot.cancelReason}
                        </span>
                    )}
                </span>
                <span className="flex items-center gap-3">
                    {cancelled && (
                        <span className="rounded-full bg-muted px-3 py-1 text-sm font-medium text-muted-foreground">
                            Cancelado
                        </span>
                    )}
                    <span className="font-medium tabular-nums">
                        {money(lot.totalCents)}
                    </span>
                </span>
            </div>
            {!cancelled && <LotFile lot={lot} />}
            {!cancelled && !lot.return && (
                <details>
                    <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary">
                        Cancelar lote
                    </summary>
                    <Form
                        {...BillingLotController.cancel.form(lot.id)}
                        errorBag={`cancel-${lot.id}`}
                        options={{ preserveScroll: true }}
                        className="mt-2 grid gap-2 sm:max-w-lg"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Label htmlFor={`cancel-${lot.id}-reason`}>
                                    Motivo do cancelamento
                                </Label>
                                <Input
                                    id={`cancel-${lot.id}-reason`}
                                    name="reason"
                                    required
                                    maxLength={500}
                                    aria-invalid={!!errors.reason}
                                    className="h-11"
                                />
                                <InputError message={errors.reason} />
                                <p className="text-sm text-muted-foreground">
                                    As contas voltam para as prontas e podem
                                    entrar num novo lote.
                                </p>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    className="min-h-11 justify-self-start"
                                >
                                    {processing && <Spinner />}
                                    Cancelar lote {lot.id}
                                </Button>
                            </>
                        )}
                    </Form>
                </details>
            )}
        </li>
    );
}

/** Step 6.5b2a: the lot's TISS file, or what is missing to generate it. */
function LotFile({ lot }: { lot: Lot }) {
    if (lot.file) {
        return (
            <div className="flex flex-wrap items-center gap-3">
                <a
                    href={BillingLotController.download.url(lot.id)}
                    className="inline-flex min-h-11 items-center rounded-md border px-4 text-sm font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Baixar arquivo TISS
                </a>
                <Link
                    href={BillingLotController.showReturn.url(lot.id)}
                    className="inline-flex min-h-11 items-center rounded-md border px-4 text-sm font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {lot.return ? 'Ver retorno' : 'Registrar retorno'}
                </Link>
                <span className="text-sm break-all text-muted-foreground">
                    Gerado em {lot.file.generatedAt} · SHA-256{' '}
                    <span className="font-mono">{lot.file.sha256}</span>
                </span>
                {lot.return && (
                    <span className="basis-full text-sm">
                        Cobrado {money(lot.totalCents)} = pago{' '}
                        {money(lot.return.paidCents)} + glosado{' '}
                        {money(lot.return.glossedCents)}
                        {(lot.return.appealingCents > 0 ||
                            lot.return.recoveredCents > 0) &&
                            ` · do glosado: em recurso ${money(lot.return.appealingCents)}, recuperado ${money(lot.return.recoveredCents)}`}
                    </span>
                )}
            </div>
        );
    }

    if (lot.problems.length > 0) {
        return (
            <div className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">
                <p className="font-medium">Para gerar o arquivo TISS, falta:</p>
                <ul className="mt-1 list-disc pl-5">
                    {lot.problems.map((problem) => (
                        <li key={problem}>{problem}</li>
                    ))}
                </ul>
            </div>
        );
    }

    return (
        <Form
            {...BillingLotController.file.form(lot.id)}
            errorBag={`file-${lot.id}`}
            options={{ preserveScroll: true }}
            className="grid gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        disabled={processing}
                        className="min-h-11 justify-self-start"
                    >
                        {processing && <Spinner />}
                        Gerar arquivo TISS
                    </Button>
                    <InputError message={errors.file} />
                </>
            )}
        </Form>
    );
}

BillingLotsIndex.layout = {
    breadcrumbs: [{ title: 'Lotes TISS', href: index() }],
};
