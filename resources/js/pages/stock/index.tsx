import { Form, Head, Link, router } from '@inertiajs/react';
import { FileUp } from 'lucide-react';
import { useState } from 'react';
import StockController from '@/actions/App/Http/Controllers/StockController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create as newImport } from '@/routes/imports';
import { index } from '@/routes/stock';

type Lot = {
    id: number;
    code: string;
    expiresOn: string;
    manufacturer: string | null;
    balance: number;
    state: 'valid' | 'expiring' | 'expired';
};

type StockMedication = {
    id: number;
    name: string;
    stockUnit: string;
    total: number;
    minimum: number | null;
    below: boolean;
    lots: Lot[];
};

type CatalogOption = { id: number; name: string; stockUnit: string };

type Discard = {
    id: number;
    at: string;
    name: string;
    lot: string;
    quantity: number;
    reason: string;
    note: string | null;
};

const discardReason: Record<string, string> = {
    expired: 'Vencido',
    damage: 'Avaria',
    loss: 'Perda',
    other: 'Outro',
};

const lotState = {
    expired: {
        label: 'Vencido',
        className: 'bg-destructive text-white',
    },
    expiring: {
        label: 'Vence em até 30 dias',
        className:
            'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
    },
    valid: { label: 'Válido', className: 'text-muted-foreground' },
};

/**
 * The unit's pharmacy stock (step 4c.1a, ADR-0007): balance by medication and lot, from the movements. Lot
 * entries and the minimum of each medication. No patient data.
 */
export default function StockIndex({
    units,
    unitId,
    today,
    medications,
    discards,
    discardPages,
    catalog,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    today: string;
    medications: StockMedication[];
    discards: Discard[];
    discardPages: { newer: string | null; older: string | null };
    catalog: CatalogOption[];
}) {
    const unit = units.find((option) => option.id === unitId);

    return (
        <>
            <Head title="Estoque" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Estoque da farmácia"
                    description="Saldo por medicamento e lote. O saldo só muda por movimentos registrados; lote vencido não é dispensado."
                />
                <div className="mb-6">
                    <Button asChild variant="outline" className="min-h-11">
                        <Link href={newImport()}>
                            <FileUp />
                            Importar saldo inicial (CSV)
                        </Link>
                    </Button>
                </div>
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

                {medications.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Nenhum medicamento em estoque nesta unidade.
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {medications.map((medication) => (
                            <li
                                key={medication.id}
                                className="rounded-xl border bg-card px-5 py-4"
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <h2 className="font-medium">
                                        {medication.name}
                                    </h2>
                                    <p className="text-sm">
                                        <span className="font-semibold">
                                            {medication.total}
                                        </span>{' '}
                                        {medication.stockUnit}
                                        {medication.minimum !== null &&
                                            ` · mínimo ${medication.minimum}`}
                                        {medication.below && (
                                            <span className="ml-2 inline-block rounded-full bg-destructive px-2 py-0.5 text-xs font-medium whitespace-nowrap text-white">
                                                Abaixo do mínimo
                                            </span>
                                        )}
                                    </p>
                                </div>
                                {medication.lots.length > 0 && (
                                    <ul className="mt-3 divide-y border-t text-sm">
                                        {medication.lots.map((lot) => (
                                            <li key={lot.id} className="py-2">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <span>
                                                        Lote {lot.code} ·
                                                        validade {lot.expiresOn}
                                                        {lot.manufacturer &&
                                                            ` · ${lot.manufacturer}`}
                                                    </span>
                                                    <span className="flex items-center gap-2">
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${lotState[lot.state].className}`}
                                                        >
                                                            {
                                                                lotState[
                                                                    lot.state
                                                                ].label
                                                            }
                                                        </span>
                                                        <span className="font-medium">
                                                            {lot.balance}
                                                        </span>
                                                    </span>
                                                </div>
                                                {/* Remounted when the balance changes: the reason and the quantity start over. */}
                                                <DiscardForm
                                                    key={lot.balance}
                                                    lot={lot}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {discards.length > 0 && (
                    <section className="mt-10 grid gap-3">
                        <h2 className="text-lg font-semibold">Descartes</h2>
                        <ul className="divide-y rounded-xl border bg-card px-5 text-sm">
                            {discards.map((discard) => (
                                <li
                                    key={discard.id}
                                    className="flex flex-wrap items-center justify-between gap-2 py-3"
                                >
                                    <span>
                                        {discard.at} · {discard.name} · lote{' '}
                                        {discard.lot}
                                        <span className="block text-muted-foreground">
                                            {discardReason[discard.reason] ??
                                                discard.reason}
                                            {discard.note &&
                                                `: ${discard.note}`}
                                        </span>
                                    </span>
                                    <span className="font-medium">
                                        −{discard.quantity}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {(discardPages.newer || discardPages.older) && (
                            <nav
                                aria-label="Páginas de descartes"
                                className="flex flex-wrap gap-2"
                            >
                                {discardPages.newer && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <Link
                                            href={discardPages.newer}
                                            preserveScroll
                                        >
                                            Mais recentes
                                        </Link>
                                    </Button>
                                )}
                                {discardPages.older && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <Link
                                            href={discardPages.older}
                                            preserveScroll
                                        >
                                            Anteriores
                                        </Link>
                                    </Button>
                                )}
                            </nav>
                        )}
                    </section>
                )}

                <div className="mt-10 grid gap-8 lg:grid-cols-2">
                    <EntryForm
                        unitId={unitId}
                        today={today}
                        catalog={catalog}
                    />
                    <MinimumForm unitId={unitId} catalog={catalog} />
                </div>
            </Page>
        </>
    );
}

function DiscardForm({ lot }: { lot: Lot }) {
    const expired = lot.state === 'expired';
    const [reason, setReason] = useState(expired ? 'expired' : '');

    return (
        <details className="mt-1">
            <summary className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary">
                Descartar
            </summary>
            <Form
                {...StockController.discard.form(lot.id)}
                options={{ preserveScroll: true }}
                errorBag={`discard${lot.id}`}
                resetOnSuccess
                className="mt-2 grid gap-3 rounded-lg border p-4 sm:grid-cols-2"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor={`discard-reason-${lot.id}`}>
                                Motivo
                            </Label>
                            <select
                                id={`discard-reason-${lot.id}`}
                                name="reason"
                                required
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                className={selectClassName}
                            >
                                <option value="" disabled>
                                    Selecione
                                </option>
                                {Object.entries(discardReason)
                                    .filter(
                                        ([code]) =>
                                            code !== 'expired' || expired,
                                    )
                                    .map(([code, label]) => (
                                        <option key={code} value={code}>
                                            {label}
                                        </option>
                                    ))}
                            </select>
                            <InputError message={errors.reason} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`discard-quantity-${lot.id}`}>
                                Quantidade (saldo {lot.balance})
                            </Label>
                            <Input
                                id={`discard-quantity-${lot.id}`}
                                name="quantity"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={lot.balance}
                                defaultValue={expired ? lot.balance : undefined}
                                required
                                className="h-11"
                            />
                            <InputError message={errors.quantity} />
                        </div>
                        {reason === 'other' && (
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor={`discard-note-${lot.id}`}>
                                    Descrição (sem dado de paciente)
                                </Label>
                                <Input
                                    id={`discard-note-${lot.id}`}
                                    name="note"
                                    required
                                    maxLength={200}
                                    className="h-11"
                                />
                                <InputError message={errors.note} />
                            </div>
                        )}
                        <div className="sm:col-span-2">
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Registrar descarte
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </details>
    );
}

function MedicationSelect({
    id,
    catalog,
}: {
    id: string;
    catalog: CatalogOption[];
}) {
    return (
        <select
            id={id}
            name="medication_id"
            required
            defaultValue=""
            className={selectClassName}
        >
            <option value="" disabled>
                Selecione
            </option>
            {catalog.map((option) => (
                <option key={option.id} value={option.id}>
                    {option.name} — {option.stockUnit}
                </option>
            ))}
        </select>
    );
}

function EntryForm({
    unitId,
    today,
    catalog,
}: {
    unitId: number;
    today: string;
    catalog: CatalogOption[];
}) {
    return (
        <section className="grid content-start gap-3">
            <h2 className="text-lg font-semibold">Entrada de lote</h2>
            <Form
                {...StockController.storeEntry.form()}
                options={{ preserveScroll: true }}
                errorBag="entry"
                resetOnSuccess
                className="grid gap-4 rounded-xl border bg-card p-5"
            >
                {({ processing, errors }) => (
                    <>
                        <input type="hidden" name="unit_id" value={unitId} />
                        <div className="grid gap-2">
                            <Label htmlFor="entry-medication">
                                Medicamento
                            </Label>
                            <MedicationSelect
                                id="entry-medication"
                                catalog={catalog}
                            />
                            <InputError
                                message={errors.medication_id ?? errors.unit_id}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="entry-lot">Lote</Label>
                                <Input
                                    id="entry-lot"
                                    name="lot_code"
                                    required
                                    maxLength={50}
                                    autoCapitalize="characters"
                                    className="h-11"
                                />
                                <InputError message={errors.lot_code} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="entry-expiry">Validade</Label>
                                <Input
                                    id="entry-expiry"
                                    name="expires_on"
                                    type="date"
                                    min={today}
                                    required
                                    className="h-11"
                                />
                                <InputError message={errors.expires_on} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="entry-quantity">
                                    Quantidade
                                </Label>
                                <Input
                                    id="entry-quantity"
                                    name="quantity"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={100000}
                                    required
                                    className="h-11"
                                />
                                <InputError message={errors.quantity} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="entry-manufacturer">
                                    Fabricante (opcional)
                                </Label>
                                <Input
                                    id="entry-manufacturer"
                                    name="manufacturer"
                                    maxLength={100}
                                    className="h-11"
                                />
                                <InputError message={errors.manufacturer} />
                            </div>
                        </div>
                        <div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Registrar entrada
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </section>
    );
}

function MinimumForm({
    unitId,
    catalog,
}: {
    unitId: number;
    catalog: CatalogOption[];
}) {
    return (
        <section className="grid content-start gap-3">
            <h2 className="text-lg font-semibold">Estoque mínimo</h2>
            <Form
                {...StockController.updateMinimum.form()}
                options={{ preserveScroll: true }}
                errorBag="minimum"
                resetOnSuccess
                className="grid gap-4 rounded-xl border bg-card p-5"
            >
                {({ processing, errors }) => (
                    <>
                        <input type="hidden" name="unit_id" value={unitId} />
                        <div className="grid gap-2">
                            <Label htmlFor="minimum-medication">
                                Medicamento
                            </Label>
                            <MedicationSelect
                                id="minimum-medication"
                                catalog={catalog}
                            />
                            <InputError message={errors.medication_id} />
                        </div>
                        <div className="grid max-w-xs gap-2">
                            <Label htmlFor="minimum-value">
                                Mínimo (vazio retira)
                            </Label>
                            <Input
                                id="minimum-value"
                                name="minimum"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={100000}
                                className="h-11"
                            />
                            <InputError message={errors.minimum} />
                        </div>
                        <div>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Salvar mínimo
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </section>
    );
}
