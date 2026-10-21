import { Form } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import PatientAllergyController from '@/actions/App/Http/Controllers/PatientAllergyController';
import InputError from '@/components/input-error';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type PatientAllergy = {
    id: number;
    substance: string;
    severity: string;
    reaction: string | null;
    recordedAt: string | null;
    author: string | null;
    inactivatedAt: string | null;
    inactivationReason: string | null;
};

export type AllergyForm = {
    patientId: number;
    substances: string[];
    severities: Record<string, string>;
};

/**
 * Allergies at the top of the record (step 4b.1c). With `form` (the open visit or the admission) the
 * professional records and inactivates them; without it the list is read-only (emergency access).
 */
export function PatientAllergies({
    allergies,
    form,
}: {
    allergies: PatientAllergy[];
    form?: AllergyForm | null;
}) {
    const active = allergies.filter(
        (allergy) => allergy.inactivatedAt === null,
    );
    const inactive = allergies.filter(
        (allergy) => allergy.inactivatedAt !== null,
    );

    return (
        <section
            aria-labelledby="allergies-title"
            className={`mb-6 grid gap-3 rounded-xl border px-4 py-3 text-sm ${active.length > 0 ? 'border-destructive/40 bg-destructive/5' : 'bg-card'}`}
        >
            <h2
                id="allergies-title"
                className="flex items-center gap-2 font-semibold"
            >
                {active.length > 0 && (
                    <TriangleAlert
                        className="size-4 shrink-0 text-destructive"
                        aria-hidden="true"
                    />
                )}
                Alergias
            </h2>
            {active.length === 0 ? (
                <p className="text-muted-foreground">
                    Nenhuma alergia registrada.
                </p>
            ) : (
                <ul className="grid gap-2">
                    {active.map((allergy) => (
                        <li key={allergy.id} className="grid gap-1">
                            <p>
                                <span className="font-medium">
                                    {allergy.substance}
                                </span>{' '}
                                · {allergy.severity}
                                {allergy.reaction && ` · ${allergy.reaction}`}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {allergy.recordedAt} · {allergy.author}
                            </p>
                            {form && <InactivateForm allergy={allergy} />}
                        </li>
                    ))}
                </ul>
            )}
            {inactive.length > 0 && (
                <details>
                    <summary className="inline-flex min-h-11 cursor-pointer items-center text-muted-foreground">
                        Inativadas ({inactive.length})
                    </summary>
                    <ul className="grid gap-2 pt-1">
                        {inactive.map((allergy) => (
                            <li
                                key={allergy.id}
                                className="text-muted-foreground"
                            >
                                <span className="line-through">
                                    {allergy.substance}
                                </span>{' '}
                                · inativada em {allergy.inactivatedAt}:{' '}
                                {allergy.inactivationReason}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
            {form && <RecordForm form={form} />}
        </section>
    );
}

function RecordForm({ form }: { form: AllergyForm }) {
    return (
        <details className="border-t pt-2">
            <summary className="inline-flex min-h-11 cursor-pointer items-center font-medium text-primary">
                Registrar alergia
            </summary>
            <Form
                {...PatientAllergyController.store.form(form.patientId)}
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="grid gap-3 pt-2 sm:grid-cols-2"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="allergy-substance">
                                Substância ou princípio ativo
                            </Label>
                            <Input
                                id="allergy-substance"
                                name="substance"
                                list="allergy-substances"
                                required
                                maxLength={255}
                                autoComplete="off"
                                className="h-11"
                            />
                            <datalist id="allergy-substances">
                                {form.substances.map((substance) => (
                                    <option key={substance} value={substance} />
                                ))}
                            </datalist>
                            <InputError message={errors.substance} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="allergy-severity">Gravidade</Label>
                            <select
                                id="allergy-severity"
                                name="severity"
                                required
                                defaultValue=""
                                className={selectClassName}
                            >
                                <option value="" disabled>
                                    Escolha
                                </option>
                                {Object.entries(form.severities).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                            <InputError message={errors.severity} />
                        </div>
                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="allergy-reaction">
                                Reação (opcional)
                            </Label>
                            <Input
                                id="allergy-reaction"
                                name="reaction"
                                maxLength={255}
                                className="h-11"
                            />
                            <InputError message={errors.reaction} />
                        </div>
                        <div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Registrar
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </details>
    );
}

function InactivateForm({ allergy }: { allergy: PatientAllergy }) {
    const id = `inactivate-allergy-${allergy.id}`;

    return (
        <details>
            <summary
                aria-label={`Inativar alergia a ${allergy.substance}`}
                className="inline-flex min-h-11 cursor-pointer items-center text-muted-foreground"
            >
                Inativar
            </summary>
            <Form
                {...PatientAllergyController.inactivate.form(allergy.id)}
                options={{ preserveScroll: true }}
                className="flex flex-wrap items-end gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid content-start gap-2">
                            <Label htmlFor={id}>Motivo da inativação</Label>
                            <Input
                                id={id}
                                name="reason"
                                required
                                maxLength={255}
                                className="h-11"
                            />
                            <InputError message={errors.reason} />
                        </div>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Confirmar inativação
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}
