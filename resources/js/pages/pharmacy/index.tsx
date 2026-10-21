import { Head, Link, router } from '@inertiajs/react';
import PharmacyController from '@/actions/App/Http/Controllers/PharmacyController';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/pharmacy';

/**
 * The pharmacy queue (step 4b.3a, ADR-0005): the valid prescriptions of the unit, those with items to review
 * first. Only identification and counts; opening one is logged.
 */
export default function PharmacyIndex({
    units,
    unitId,
    prescriptions,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    prescriptions: {
        id: number;
        patient: string | null;
        place: string;
        signedAt: string | null;
        pending: number;
    }[];
}) {
    const unit = units.find((option) => option.id === unitId);

    return (
        <>
            <Head title="Farmácia" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Avaliação de prescrições"
                    description="Prescrições válidas da unidade. Abrir uma prescrição fica registrado na trilha de acesso."
                />
                {units.length > 1 && (
                    <div className="mb-6 grid max-w-sm gap-2">
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
                {prescriptions.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Nenhuma prescrição válida nesta unidade.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {prescriptions.map((prescription) => (
                            <li key={prescription.id}>
                                <Link
                                    href={PharmacyController.show.url(
                                        prescription.id,
                                    )}
                                    className="flex min-h-11 flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {prescription.patient}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {prescription.place} · assinada em{' '}
                                            {prescription.signedAt}
                                        </span>
                                    </span>
                                    <span
                                        className={
                                            prescription.pending > 0
                                                ? 'rounded-full bg-primary px-3 py-1 text-sm font-medium text-primary-foreground'
                                                : 'text-sm text-muted-foreground'
                                        }
                                    >
                                        {prescription.pending > 0
                                            ? `${prescription.pending} a avaliar`
                                            : 'Avaliada'}
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
