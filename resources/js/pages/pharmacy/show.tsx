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
 * patient's identification. Each item is reviewed once.
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
    };
    patient: { name: string; age: number | null; sex: string | null };
    allergies: PatientAllergy[];
    items: PrescriptionItemView[];
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
                <PatientAllergies allergies={allergies} />
                <ol className="grid gap-4">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="grid gap-3 rounded-xl border bg-card p-5"
                        >
                            <PrescriptionItemLine item={item} />
                            {!item.review && !item.suspendedAt && (
                                <ReviewForm item={item} decisions={decisions} />
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
