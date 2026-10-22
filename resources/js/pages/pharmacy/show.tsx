import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import PharmacyController from '@/actions/App/Http/Controllers/PharmacyController';
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
 * 4b.3b), and what comes back is recorded as its return, also once the prescription is out of the queue.
 */
export default function PharmacyShow({
    prescription,
    patient,
    allergies,
    items,
    decisions,
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
    items: (PrescriptionItemView & { returnable: boolean })[];
    decisions: Record<string, string>;
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
                                    <DispenseForm item={item} />
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

function DispenseForm({ item }: { item: PrescriptionItemView }) {
    const id = `quantity-${item.id}`;

    return (
        <Form
            {...PharmacyController.dispense.form(item.id)}
            options={{ preserveScroll: true }}
            errorBag={`dispense${item.id}`}
            className="grid gap-3 border-t pt-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid max-w-xs gap-2">
                        <Label htmlFor={id}>
                            Quantidade para 24 horas (unidades)
                        </Label>
                        <Input
                            id={id}
                            name="quantity"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={500}
                            required
                            defaultValue={item.doses?.length || undefined}
                            className="h-11"
                        />
                        <InputError message={errors.quantity} />
                    </div>
                    <div>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Dispensar {item.medication}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function ReturnForm({ item }: { item: PrescriptionItemView }) {
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
