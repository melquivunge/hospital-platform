import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import PrescriptionController from '@/actions/App/Http/Controllers/PrescriptionController';
import { textareaClassName } from '@/components/clinical-notes';
import type { RecordPatient } from '@/components/clinical-record';
import { patientDescription } from '@/components/clinical-record';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import type { PatientAllergy } from '@/components/patient-allergies';
import { PatientAllergies } from '@/components/patient-allergies';
import type { PrescriptionItemView } from '@/components/prescription-panel';
import { PrescriptionItemLine } from '@/components/prescription-panel';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type MedicationOption = {
    id: number;
    label: string;
    doseUnit: string;
    routes: { value: string; label: string }[];
    controlled: boolean;
    highAlert: boolean;
};

type DraftItem = PrescriptionItemView & { alerts: string[]; pending: string[] };

/**
 * The prescriber's draft (step 4b.2a): items checked against allergies, maximum doses and interactions as they
 * are added. An alert needs a justification; signing with the password checks every alert again.
 */
export default function PrescriptionEdit({
    prescription,
    patient,
    allergies,
    items,
    medications,
    frequencies,
    now,
}: {
    prescription: { id: number; encounter: string; backUrl: string };
    patient: RecordPatient;
    allergies: PatientAllergy[];
    items: DraftItem[];
    medications: MedicationOption[];
    frequencies: number[];
    now: string;
}) {
    return (
        <>
            <Head title="Prescrição" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={`Rascunho de prescrição · ${prescription.encounter}`}
                    title={patient.name}
                    description={patientDescription(patient)}
                    actions={
                        <Button asChild variant="outline" className="min-h-11">
                            <Link href={prescription.backUrl}>
                                Voltar ao prontuário
                            </Link>
                        </Button>
                    }
                />
                <PatientAllergies allergies={allergies} />
                <div className="grid gap-8">
                    <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
                        <h2 className="font-semibold">Itens</h2>
                        {items.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhum item ainda.
                            </p>
                        ) : (
                            <ol className="grid gap-3">
                                {items.map((item) => (
                                    <li
                                        key={item.id}
                                        className="grid gap-3 rounded-lg border px-4 py-3"
                                    >
                                        <PrescriptionItemLine item={item} />
                                        {item.pending.length > 0 && (
                                            <JustifyForm item={item} />
                                        )}
                                        <Form
                                            {...PrescriptionController.destroyItem.form(
                                                item.id,
                                            )}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    disabled={processing}
                                                    aria-label={`Retirar ${item.medication}`}
                                                    className="min-h-11 px-3 text-muted-foreground"
                                                >
                                                    Retirar
                                                </Button>
                                            )}
                                        </Form>
                                    </li>
                                ))}
                            </ol>
                        )}
                        <AddItem
                            prescriptionId={prescription.id}
                            medications={medications}
                            frequencies={frequencies}
                            now={now}
                        />
                    </section>
                    <Sign prescriptionId={prescription.id} />
                </div>
            </Page>
        </>
    );
}

function JustifyForm({ item }: { item: DraftItem }) {
    const id = `justify-${item.id}`;

    return (
        <Form
            {...PrescriptionController.justifyItem.form(item.id)}
            options={{ preserveScroll: true }}
            errorBag={`justify${item.id}`}
            className="grid gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3"
        >
            {({ processing, errors }) => (
                <>
                    <p className="text-sm font-medium text-destructive">
                        {item.pending.join(' ')}
                    </p>
                    <Label htmlFor={id}>Justificativa</Label>
                    <Input
                        id={id}
                        name="justification"
                        required
                        maxLength={500}
                        className="h-11"
                    />
                    <InputError message={errors.justification} />
                    <div>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Justificar
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function AddItem({
    prescriptionId,
    medications,
    frequencies,
    now,
}: {
    prescriptionId: number;
    medications: MedicationOption[];
    frequencies: number[];
    now: string;
}) {
    const [medicationId, setMedicationId] = useState('');
    const [schedule, setSchedule] = useState<'interval' | 'times'>('interval');
    const [prn, setPrn] = useState(false);
    const medication = medications.find(
        (option) => String(option.id) === medicationId,
    );

    return (
        <Form
            {...PrescriptionController.storeItem.form(prescriptionId)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={() => {
                setMedicationId('');
                setPrn(false);
            }}
            className="grid gap-4 border-t pt-4 sm:grid-cols-2"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="font-medium sm:col-span-2">Incluir item</h3>
                    <div className="grid content-start gap-2 sm:col-span-2">
                        <Label htmlFor="item-medication">Medicamento</Label>
                        <select
                            id="item-medication"
                            name="medication_id"
                            required
                            value={medicationId}
                            onChange={(event) =>
                                setMedicationId(event.target.value)
                            }
                            className={selectClassName}
                        >
                            <option value="" disabled>
                                Escolha no catálogo
                            </option>
                            {medications.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                    {option.highAlert ? ' · alto alerta' : ''}
                                    {option.controlled ? ' · controlado' : ''}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.medication_id} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="item-dose">
                            Dose{medication ? ` (${medication.doseUnit})` : ''}
                        </Label>
                        <Input
                            id="item-dose"
                            name="dose"
                            required
                            inputMode="decimal"
                            className="h-11"
                        />
                        <InputError message={errors.dose} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="item-route">Via</Label>
                        <select
                            id="item-route"
                            name="route"
                            required
                            defaultValue=""
                            key={medicationId}
                            className={selectClassName}
                        >
                            <option value="" disabled>
                                Escolha
                            </option>
                            {medication?.routes.map((route) => (
                                <option key={route.value} value={route.value}>
                                    {route.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.route} />
                    </div>
                    <fieldset className="grid content-start gap-2 sm:col-span-2">
                        <legend className="mb-2 text-sm font-medium">
                            Frequência
                        </legend>
                        <div className="flex flex-wrap gap-4">
                            <label className="flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="schedule"
                                    value="interval"
                                    checked={schedule === 'interval'}
                                    onChange={() => setSchedule('interval')}
                                />
                                A cada intervalo
                            </label>
                            <label className="flex min-h-11 items-center gap-2 text-sm">
                                <input
                                    type="radio"
                                    name="schedule"
                                    value="times"
                                    checked={schedule === 'times'}
                                    disabled={prn}
                                    onChange={() => setSchedule('times')}
                                />
                                Em horários fixos
                            </label>
                        </div>
                        {schedule === 'interval' ? (
                            <div className="grid gap-2">
                                <Label htmlFor="item-frequency">
                                    {prn ? 'Intervalo mínimo' : 'Intervalo'}
                                </Label>
                                <select
                                    id="item-frequency"
                                    name="frequency_hours"
                                    required
                                    defaultValue="8"
                                    className={selectClassName}
                                >
                                    {frequencies.map((hours) => (
                                        <option key={hours} value={hours}>
                                            {hours === 24
                                                ? '1 vez ao dia'
                                                : `De ${hours}/${hours} h`}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.frequency_hours} />
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor="item-times">Horários</Label>
                                <Input
                                    id="item-times"
                                    name="times"
                                    required
                                    placeholder="08:00, 14:00, 20:00"
                                    className="h-11"
                                />
                                <InputError message={errors.times} />
                            </div>
                        )}
                        <InputError message={errors.schedule} />
                    </fieldset>
                    <div className="grid content-start gap-2 sm:col-span-2">
                        <input type="hidden" name="prn" value="0" />
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="prn"
                                value="1"
                                checked={prn}
                                onChange={(event) => {
                                    setPrn(event.target.checked);
                                    if (event.target.checked)
                                        setSchedule('interval');
                                }}
                            />
                            Se necessário
                        </label>
                        {prn && (
                            <>
                                <Label htmlFor="item-prn-condition">
                                    Condição
                                </Label>
                                <Input
                                    id="item-prn-condition"
                                    name="prn_condition"
                                    required
                                    maxLength={255}
                                    placeholder="Dor ou febre acima de 38 °C"
                                    className="h-11"
                                />
                                <InputError message={errors.prn_condition} />
                            </>
                        )}
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="item-starts">Início</Label>
                        <Input
                            id="item-starts"
                            name="starts_at"
                            type="datetime-local"
                            defaultValue={now}
                            className="h-11"
                        />
                        <InputError message={errors.starts_at} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="item-notes">
                            Observação (opcional)
                        </Label>
                        <Input
                            id="item-notes"
                            name="notes"
                            maxLength={500}
                            className="h-11"
                        />
                        <InputError message={errors.notes} />
                    </div>
                    <div className="grid content-start gap-2 sm:col-span-2">
                        <Label htmlFor="item-justification">
                            Justificativa (obrigatória se houver alerta)
                        </Label>
                        <textarea
                            id="item-justification"
                            name="justification"
                            maxLength={500}
                            rows={2}
                            className={textareaClassName}
                        />
                        <InputError message={errors.justification} />
                    </div>
                    <div className="sm:col-span-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Incluir item
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Sign({ prescriptionId }: { prescriptionId: number }) {
    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">Assinar</h2>
            <p className="text-sm text-muted-foreground">
                Assinada, a prescrição não muda e vale por 24 horas. Os alertas
                são conferidos de novo agora.
            </p>
            <Form
                {...PrescriptionController.sign.form(prescriptionId)}
                resetOnError={['password']}
                className="flex flex-wrap items-end gap-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid min-w-0 flex-1 gap-2">
                            <Label htmlFor="sign-password">Sua senha</Label>
                            <Input
                                id="sign-password"
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                className="h-11"
                            />
                            <InputError message={errors.password} />
                        </div>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Assinar prescrição
                        </Button>
                    </>
                )}
            </Form>
            <Form
                {...PrescriptionController.discard.form(prescriptionId)}
                className="border-t pt-4"
            >
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="ghost"
                        disabled={processing}
                        className="min-h-11 px-3 text-muted-foreground"
                    >
                        Descartar rascunho
                    </Button>
                )}
            </Form>
        </section>
    );
}
