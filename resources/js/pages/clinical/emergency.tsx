import { Form, Head } from '@inertiajs/react';
import ClinicalRecordController from '@/actions/App/Http/Controllers/ClinicalRecordController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

/** Emergency access request (step 3.6b): justified, read-only, time-limited and reported to management. */
export default function Emergency({
    patient,
    units,
    hours,
}: {
    patient: { id: number; name: string };
    units: { id: number; name: string }[];
    hours: number;
}) {
    return (
        <>
            <Head title="Acesso de emergência" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={patient.name}
                    title="Acesso de emergência"
                    description={`Você não tem atendimento aberto com este paciente. Em uma emergência, o prontuário abre só para leitura por ${hours} horas, com justificativa. A gestão da unidade é avisada e a auditoria registra cada leitura.`}
                />
                <Form
                    {...ClinicalRecordController.store.form(patient.id)}
                    className="grid gap-5 rounded-xl border bg-card p-5 sm:p-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="unit_id">
                                    Unidade onde você está atendendo
                                </Label>
                                <select
                                    id="unit_id"
                                    name="unit_id"
                                    required
                                    defaultValue={
                                        units.length === 1 ? units[0].id : ''
                                    }
                                    className={selectClassName}
                                >
                                    <option value="" disabled>
                                        Selecione
                                    </option>
                                    {units.map((unit) => (
                                        <option key={unit.id} value={unit.id}>
                                            {unit.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.unit_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="justification">
                                    Justificativa (mínimo de 20 caracteres)
                                </Label>
                                <textarea
                                    id="justification"
                                    name="justification"
                                    aria-describedby="justification-hint"
                                    required
                                    minLength={20}
                                    maxLength={2000}
                                    className="min-h-32 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                />
                                <p
                                    id="justification-hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    Descreva a situação que exige o acesso, sem
                                    diagnóstico nem sintomas: a gestão da
                                    unidade e a auditoria leem este texto.
                                </p>
                                <InputError message={errors.justification} />
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    Abrir prontuário em emergência
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}
