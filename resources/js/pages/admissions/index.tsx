import { Form, Head, router } from '@inertiajs/react';
import { Hospital } from 'lucide-react';
import AdmissionController from '@/actions/App/Http/Controllers/AdmissionController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { sexLabels } from '@/components/patient-display';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/admissions';

type Admission = {
    id: number;
    status: 'requested' | 'admitted';
    patient: string | null;
    sex: string | null;
    age: number | null;
    sector: string | null;
    responsible: string | null;
    isolation: boolean;
    requestedAt: string;
    bed: string | null;
    admittedAt: string | null;
    justification: string | null;
};

type FreeBed = {
    id: number;
    sectorId: number;
    label: string;
    sex: 'female' | 'male' | null;
    isolation: boolean;
};

const textareaClassName =
    'min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

/** Bed hints next to each option: what breaks the sex or isolation rule for this patient. */
function hints(bed: FreeBed, admission: Admission): string {
    const notes = [
        bed.sex !== null &&
            bed.sex !== admission.sex &&
            `leito ${sexLabels[bed.sex].toLowerCase()}`,
        bed.isolation && !admission.isolation && 'leito de isolamento',
        !bed.isolation && admission.isolation && 'sem isolamento',
    ].filter(Boolean);

    return notes.length === 0 ? '' : ` (fora da regra: ${notes.join(', ')})`;
}

/**
 * Admissions of a unit (step 4.4a): requests waiting for a bed and patients admitted. Bed management sees
 * identification only, never clinical content.
 */
export default function Admissions({
    units,
    unit,
    admissions,
    freeBeds,
}: {
    units: { id: number; name: string }[];
    unit: { id: number; name: string };
    admissions: Admission[];
    freeBeds: FreeBed[];
}) {
    const requested = admissions.filter((item) => item.status === 'requested');
    const admitted = admissions.filter((item) => item.status === 'admitted');

    return (
        <>
            <Head title="Internações" />
            <Page>
                <PageHeader
                    eyebrow={unit.name}
                    title="Internações"
                    description="Pedidos de internação aguardando leito e pacientes internados. Leito fora da regra de sexo ou de isolamento pode ser alocado com justificativa registrada."
                />
                {units.length > 1 && (
                    <div className="mb-6 grid max-w-sm gap-2">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            value={unit.id}
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

                <section
                    aria-labelledby="requested-heading"
                    className="mb-8 grid gap-3"
                >
                    <h2 id="requested-heading" className="font-semibold">
                        Aguardando leito ({requested.length})
                    </h2>
                    {requested.length === 0 ? (
                        <div className="rounded-xl border bg-card px-5 py-10 text-center">
                            <Hospital
                                className="mx-auto mb-3 size-6 text-primary"
                                aria-hidden="true"
                            />
                            <p className="font-medium">
                                Nenhum pedido aguardando leito
                            </p>
                        </div>
                    ) : (
                        <ul className="grid gap-3">
                            {requested.map((admission) => (
                                <li
                                    key={admission.id}
                                    className="grid gap-3 rounded-xl border bg-card p-4 text-sm"
                                >
                                    <Identification admission={admission} />
                                    <Allocate
                                        admission={admission}
                                        freeBeds={freeBeds}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section
                    aria-labelledby="admitted-heading"
                    className="grid gap-3"
                >
                    <h2 id="admitted-heading" className="font-semibold">
                        Internados ({admitted.length})
                    </h2>
                    <ul className="divide-y rounded-xl border bg-card">
                        {admitted.map((admission) => (
                            <li
                                key={admission.id}
                                className="grid gap-1 px-5 py-4 text-sm"
                            >
                                <Identification admission={admission} />
                                <p>
                                    {admission.bed} · desde{' '}
                                    {admission.admittedAt}
                                </p>
                                {admission.justification && (
                                    <p className="text-muted-foreground">
                                        Fora da regra: {admission.justification}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </Page>
        </>
    );
}

function Identification({ admission }: { admission: Admission }) {
    return (
        <span className="grid gap-0.5">
            <span className="font-semibold">{admission.patient}</span>
            <span className="text-muted-foreground">
                {[
                    admission.sex && sexLabels[admission.sex],
                    admission.age !== null && `${admission.age} anos`,
                    `setor pedido: ${admission.sector}`,
                    `responsável: ${admission.responsible}`,
                    admission.isolation && 'precisa de isolamento',
                    `pedido às ${admission.requestedAt}`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </span>
        </span>
    );
}

function Allocate({
    admission,
    freeBeds,
}: {
    admission: Admission;
    freeBeds: FreeBed[];
}) {
    const id = (field: string) => `${field}-${admission.id}`;

    return (
        <Form
            {...AdmissionController.allocate.form(admission.id)}
            errorBag={`admission-${admission.id}`}
            options={{ preserveScroll: true }}
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={id('bed')}>Leito</Label>
                        <select
                            id={id('bed')}
                            name="bed_id"
                            required
                            defaultValue=""
                            className={selectClassName}
                        >
                            <option value="" disabled>
                                {freeBeds.length === 0
                                    ? 'Nenhum leito livre'
                                    : 'Selecione um leito livre'}
                            </option>
                            {freeBeds.map((bed) => (
                                <option key={bed.id} value={bed.id}>
                                    {bed.label}
                                    {hints(bed, admission)}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.bed_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={id('justification')}>
                            Justificativa (só para leito fora da regra)
                        </Label>
                        <textarea
                            id={id('justification')}
                            name="compatibility_justification"
                            maxLength={500}
                            className={textareaClassName}
                        />
                        <InputError
                            message={errors.compatibility_justification}
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={processing || freeBeds.length === 0}
                        aria-label={`Alocar leito: ${admission.patient}`}
                        className="min-h-11 justify-self-start"
                    >
                        Alocar leito
                    </Button>
                </>
            )}
        </Form>
    );
}

Admissions.layout = {
    breadcrumbs: [{ title: 'Internações', href: index() }],
};
