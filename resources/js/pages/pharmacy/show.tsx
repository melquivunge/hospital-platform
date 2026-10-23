import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import PharmacyController from '@/actions/App/Http/Controllers/PharmacyController';
import StockController from '@/actions/App/Http/Controllers/StockController';
import { sexLabels } from '@/components/patient-display';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import type { PatientAllergy } from '@/components/patient-allergies';
import { PatientAllergies } from '@/components/patient-allergies';
import type { PrescriptionItemView } from '@/components/prescription-panel';
import { PrescriptionItemLine } from '@/components/prescription-panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/pharmacy';

/**
 * One prescription for the pharmacist (step 4b.3a, ADR-0005): only the prescription, the allergies and the
 * patient's identification. Each item is reviewed once; an approved one is dispensed once for the 24 hours (step
 * 4b.3b), and what comes back is recorded as its return, also once the prescription is out of the queue. Step 4c.2:
 * the dispensing takes from the unit's lots, the ones that expire first suggested; a lot left out of the stock is
 * entered right here.
 */
type StockLotOption = {
    id: number;
    code: string;
    expiresOn: string;
    balance: number;
};

type PharmacyItem = PrescriptionItemView & {
    returnable: boolean;
    medicationId: number;
    stockUnit: string;
    lots: StockLotOption[];
};

export default function PharmacyShow({
    prescription,
    patient,
    allergies,
    items,
    decisions,
    today,
}: {
    prescription: {
        id: number;
        place: string;
        signedAt: string | null;
        validUntil: string | null;
        author: string | null;
        unitId: number;
        current: boolean;
    };
    patient: { name: string; age: number | null; sex: string | null };
    allergies: PatientAllergy[];
    items: PharmacyItem[];
    decisions: Record<string, string>;
    today: string;
}) {
    return (
        <>
            <Head title="Avaliação farmacêutica" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={`Prescrição · ${prescription.place}`}
                    title={patient.name}
                    description={`${[patient.age !== null && `${patient.age} anos`, patient.sex && sexLabels[patient.sex]].filter(Boolean).join(' · ')} · assinada em ${prescription.signedAt} por ${prescription.author} · válida até ${prescription.validUntil}`}
                    actions={
                        <Button asChild variant="outline" className="min-h-11">
                            <Link
                                href={
                                    index({
                                        query: { unit: prescription.unitId },
                                    }).url
                                }
                            >
                                Voltar à fila
                            </Link>
                        </Button>
                    }
                />
                {!prescription.current && (
                    <p className="rounded-xl border bg-muted px-5 py-4 text-sm">
                        Esta prescrição não está mais em vigor. Só se registra a
                        devolução do que foi dispensado.
                    </p>
                )}
                <PatientAllergies allergies={allergies} />
                <ol className="grid gap-4">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="grid gap-3 rounded-xl border bg-card p-5"
                        >
                            <PrescriptionItemLine item={item} />
                            {prescription.current &&
                                !item.review &&
                                !item.suspendedAt && (
                                    <ReviewForm
                                        item={item}
                                        decisions={decisions}
                                    />
                                )}
                            {prescription.current &&
                                item.review &&
                                item.review.decision !== 'refused' &&
                                !item.dispensing &&
                                !item.suspendedAt && (
                                    <>
                                        <DispenseForm
                                            key={item.lots
                                                .map(
                                                    (lot) =>
                                                        `${lot.id}:${lot.balance}`,
                                                )
                                                .join()}
                                            item={item}
                                        />
                                        <EntryForm
                                            item={item}
                                            unitId={prescription.unitId}
                                            today={today}
                                        />
                                    </>
                                )}
                            {item.returnable && item.dispensing && (
                                <ReturnForm item={item} />
                            )}
                        </li>
                    ))}
                </ol>
            </Page>
        </>
    );
}

function ReviewForm({
    item,
    decisions,
}: {
    item: PrescriptionItemView;
    decisions: Record<string, string>;
}) {
    const [decision, setDecision] = useState('approved');
    const name = `decision-${item.id}`;

    return (
        <Form
            {...PharmacyController.review.form(item.id)}
            options={{ preserveScroll: true }}
            errorBag={`review${item.id}`}
            className="grid gap-3 border-t pt-3"
        >
            {({ processing, errors }) => (
                <>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            Avaliação de {item.medication}
                        </legend>
                        <div className="flex flex-wrap gap-4">
                            {Object.entries(decisions).map(([value, label]) => (
                                <label
                                    key={value}
                                    className="flex min-h-11 items-center gap-2 text-sm"
                                >
                                    <input
                                        type="radio"
                                        name="decision"
                                        value={value}
                                        checked={decision === value}
                                        onChange={() => setDecision(value)}
                                        aria-describedby={name}
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                        <InputError id={name} message={errors.decision} />
                    </fieldset>
                    {decision !== 'approved' && (
                        <div className="grid gap-2">
                            <Label htmlFor={`note-${item.id}`}>
                                {decision === 'refused'
                                    ? 'Motivo da recusa'
                                    : 'Observação'}
                            </Label>
                            <Input
                                id={`note-${item.id}`}
                                name="note"
                                required
                                maxLength={500}
                                className="h-11"
                            />
                            <InputError message={errors.note} />
                        </div>
                    )}
                    <div>
                        <Button
                            type="submit"
                            variant={
                                decision === 'refused'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={processing}
                            className="min-h-11"
                        >
                            Registrar avaliação
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * The valid lots that expire first, filled up to the quantity: the suggestion the pharmacy accepts or changes.
 */
function suggest(lots: StockLotOption[], quantity: number): number[] {
    let remaining = quantity;

    return lots.map((lot) => {
        const take = Math.min(lot.balance, remaining);
        remaining -= take;

        return take;
    });
}

function DispenseForm({ item }: { item: PharmacyItem }) {
    const [taken, setTaken] = useState(() =>
        suggest(item.lots, item.doses?.length || 1),
    );
    const total = taken.reduce((sum, quantity) => sum + quantity, 0);

    if (item.lots.length === 0) {
        return (
            <p className="border-t pt-3 text-sm">
                Sem saldo de {item.medication} no estoque da unidade. Registre a
                entrada do lote abaixo para dispensar.
            </p>
        );
    }

    return (
        <Form
            {...PharmacyController.dispense.form(item.id)}
            options={{ preserveScroll: true }}
            errorBag={`dispense${item.id}`}
            className="grid gap-3 border-t pt-3"
        >
            {({ processing, errors }) => (
                <>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            Lotes para 24 horas ({item.stockUnit}) · os que
                            vencem primeiro vêm sugeridos
                        </legend>
                        <input type="hidden" name="quantity" value={total} />
                        {item.lots.map((lot, index) => (
                            <div
                                key={lot.id}
                                className="flex flex-wrap items-center gap-3"
                            >
                                <input
                                    type="hidden"
                                    name={`lots[${index}][id]`}
                                    value={lot.id}
                                />
                                <Label
                                    htmlFor={`lot-${item.id}-${lot.id}`}
                                    className="font-normal"
                                >
                                    Lote {lot.code} · vence {lot.expiresOn} ·
                                    saldo {lot.balance}
                                </Label>
                                <Input
                                    id={`lot-${item.id}-${lot.id}`}
                                    name={`lots[${index}][quantity]`}
                                    type="number"
                                    inputMode="numeric"
                                    min={0}
                                    max={Math.min(lot.balance, 500)}
                                    value={taken[index]}
                                    onChange={(event) =>
                                        setTaken(
                                            taken.map((quantity, other) =>
                                                other === index
                                                    ? Math.max(
                                                          0,
                                                          Number(
                                                              event.target
                                                                  .value,
                                                          ) || 0,
                                                      )
                                                    : quantity,
                                            ),
                                        )
                                    }
                                    className="h-11 w-24"
                                />
                            </div>
                        ))}
                        <p className="text-sm text-muted-foreground">
                            Total: {total} {item.stockUnit}
                        </p>
                        <InputError message={errors.quantity} />
                    </fieldset>
                    <div>
                        <Button
                            type="submit"
                            disabled={processing || total < 1}
                            className="h-auto min-h-11 whitespace-normal"
                        >
                            Dispensar {item.medication}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * The lot the stock missed, entered from the dispensing (question 4 of delivery 4c): an ordinary entry.
 */
function EntryForm({
    item,
    unitId,
    today,
}: {
    item: PharmacyItem;
    unitId: number;
    today: string;
}) {
    const id = `entry-${item.id}`;

    return (
        <Form
            {...StockController.storeEntry.form()}
            options={{ preserveScroll: true }}
            errorBag="entry"
            resetOnSuccess
        >
            {({ processing, errors }) => (
                <details open={item.lots.length === 0}>
                    <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                        Registrar entrada de lote de {item.medication}
                    </summary>
                    <input type="hidden" name="unit_id" value={unitId} />
                    <input
                        type="hidden"
                        name="medication_id"
                        value={item.medicationId}
                    />
                    <div className="grid gap-3 pt-2 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-lot`}>Lote</Label>
                            <Input
                                id={`${id}-lot`}
                                name="lot_code"
                                required
                                maxLength={50}
                                autoCapitalize="characters"
                                className="h-11"
                            />
                            <InputError message={errors.lot_code} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-expiry`}>Validade</Label>
                            <Input
                                id={`${id}-expiry`}
                                name="expires_on"
                                type="date"
                                min={today}
                                required
                                className="h-11"
                            />
                            <InputError message={errors.expires_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-quantity`}>
                                Quantidade ({item.stockUnit})
                            </Label>
                            <Input
                                id={`${id}-quantity`}
                                name="quantity"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={100000}
                                required
                                className="h-11"
                            />
                            <InputError
                                message={
                                    errors.quantity ??
                                    errors.medication_id ??
                                    errors.unit_id
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-manufacturer`}>
                                Fabricante (opcional)
                            </Label>
                            <Input
                                id={`${id}-manufacturer`}
                                name="manufacturer"
                                maxLength={100}
                                className="h-11"
                            />
                            <InputError message={errors.manufacturer} />
                        </div>
                    </div>
                    <div className="pt-3">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Registrar entrada
                        </Button>
                    </div>
                </details>
            )}
        </Form>
    );
}

function ReturnForm({ item }: { item: PharmacyItem }) {
    const dispensing = item.dispensing!;
    const id = `return-${item.id}`;

    return (
        <Form
            {...PharmacyController.returnItem.form(dispensing.id)}
            options={{ preserveScroll: true }}
            errorBag={`return${dispensing.id}`}
            className="grid gap-3 border-t pt-3"
        >
            {({ processing, errors }) => (
                <details>
                    <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                        Registrar devolução de {item.medication}
                    </summary>
                    <div className="grid gap-3 pt-2">
                        <div className="grid max-w-xs gap-2">
                            <Label htmlFor={`${id}-quantity`}>
                                Quantidade devolvida (até {dispensing.quantity})
                            </Label>
                            <Input
                                id={`${id}-quantity`}
                                name="quantity"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={dispensing.quantity}
                                required
                                className="h-11"
                            />
                            <InputError message={errors.quantity} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${id}-reason`}>Motivo</Label>
                            <Input
                                id={`${id}-reason`}
                                name="reason"
                                required
                                maxLength={255}
                                className="h-11"
                            />
                            <InputError message={errors.reason} />
                        </div>
                        <div>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Registrar devolução
                            </Button>
                        </div>
                    </div>
                </details>
            )}
        </Form>
    );
}
