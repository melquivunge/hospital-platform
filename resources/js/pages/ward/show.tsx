import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ExamOrderController from '@/actions/App/Http/Controllers/ExamOrderController';
import SurgeryOrderController from '@/actions/App/Http/Controllers/SurgeryOrderController';
import TransferController from '@/actions/App/Http/Controllers/TransferController';
import ClinicalDocumentController from '@/actions/App/Http/Controllers/ClinicalDocumentController';
import WardController from '@/actions/App/Http/Controllers/WardController';
import type { ClinicalDocument } from '@/components/clinical-documents';
import { ClinicalDocuments } from '@/components/clinical-documents';
import type { ExamResultItem } from '@/components/exam-results';
import { ExamResults } from '@/components/exam-results';
import { SurgeryDescriptions } from '@/components/surgery-descriptions';
import type { SurgeryDescriptionData } from '@/components/surgery-descriptions';
import type { Draft } from '@/components/clinical-notes';
import { NotesWorkspace, textareaClassName } from '@/components/clinical-notes';
import type { RecordNote, RecordPatient } from '@/components/clinical-record';
import { patientDescription } from '@/components/clinical-record';
import type {
    AllergyForm,
    PatientAllergy,
} from '@/components/patient-allergies';
import { PatientAllergies } from '@/components/patient-allergies';
import type { PrescriptionPanelData } from '@/components/prescription-panel';
import { PrescriptionPanel } from '@/components/prescription-panel';
import PrescriptionController from '@/actions/App/Http/Controllers/PrescriptionController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/ward';

/**
 * The record of an inpatient (step 4.4b, ADR-0004): the responsible doctor and the bed's sector team read
 * and write while the patient is there. Each author keeps one draft; signing is that of delivery 3.
 */
export default function WardShow({
    admission,
    patient,
    allergies,
    allergyForm,
    prescriptions,
    canOrder,
    history,
    handOver,
    transfer,
    transferOptions,
    discharge,
    record,
    drafts,
    documents,
    examResults,
    surgeryDescriptions,
}: {
    admission: {
        id: number;
        status: 'requested' | 'admitted' | 'in_transit';
        bed: string | null;
        since: string;
        responsible: string | null;
    };
    patient: RecordPatient;
    allergies: PatientAllergy[];
    allergyForm: AllergyForm;
    prescriptions: PrescriptionPanelData;
    canOrder: boolean;
    history: { id: number; at: string; text: string; reason: string | null }[];
    handOver: { id: number; name: string }[] | null;
    transfer: {
        id: number;
        status: 'requested' | 'accepted' | 'departed';
        to: string | null;
        mine: boolean;
    } | null;
    transferOptions: TransferUnit[] | null;
    discharge: DischargeOptions | null;
    record: RecordNote[];
    drafts: Draft[];
    documents: ClinicalDocument[];
    examResults: ExamResultItem[];
    surgeryDescriptions: SurgeryDescriptionData[];
}) {
    return (
        <>
            <Head title="Internação" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={
                        {
                            admitted: `Internado desde ${admission.since}`,
                            in_transit: `Em transferência; internado desde ${admission.since}`,
                            requested: `Aguardando leito desde ${admission.since}`,
                        }[admission.status]
                    }
                    title={patient.name}
                    description={[
                        patientDescription(patient),
                        admission.bed,
                        `Responsável: ${admission.responsible}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        canOrder && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link
                                        href={ExamOrderController.forAdmission(
                                            admission.id,
                                        )}
                                    >
                                        Exames
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link
                                        href={SurgeryOrderController.forAdmission(
                                            admission.id,
                                        )}
                                    >
                                        Cirurgias
                                    </Link>
                                </Button>
                            </div>
                        )
                    }
                />
                <PatientAllergies allergies={allergies} form={allergyForm} />
                {transfer && <TransferStatus transfer={transfer} />}
                {transferOptions && transferOptions.length > 0 && (
                    <RequestTransfer
                        admissionId={admission.id}
                        units={transferOptions}
                    />
                )}
                {discharge && (
                    <Discharge admissionId={admission.id} options={discharge} />
                )}
                {handOver && (
                    <details className="mb-6 rounded-xl border bg-card px-5 py-3">
                        <summary className="flex min-h-11 cursor-pointer items-center font-medium">
                            Passar a responsabilidade
                        </summary>
                        <Form
                            {...WardController.handOver.form(admission.id)}
                            className="grid gap-4 py-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="professional_id">
                                            Novo médico responsável
                                        </Label>
                                        <select
                                            id="professional_id"
                                            name="professional_id"
                                            required
                                            defaultValue=""
                                            className={selectClassName}
                                        >
                                            <option value="" disabled>
                                                Selecione
                                            </option>
                                            {handOver.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                >
                                                    {option.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={errors.professional_id}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="reason">
                                            Motivo (opcional)
                                        </Label>
                                        <Input
                                            id="reason"
                                            name="reason"
                                            maxLength={500}
                                            className="h-11"
                                        />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Depois de passar, você deixa de ler o
                                        prontuário por esta internação, salvo se
                                        for da equipe do setor do leito.
                                    </p>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="min-h-11 justify-self-start"
                                    >
                                        Passar a responsabilidade
                                    </Button>
                                </>
                            )}
                        </Form>
                    </details>
                )}
                <div className="mb-8">
                    <PrescriptionPanel
                        data={prescriptions}
                        start={PrescriptionController.startForAdmission.form(
                            admission.id,
                        )}
                    />
                </div>
                <NotesWorkspace
                    title="Evolução da internação"
                    action={WardController.storeNote.form(admission.id)}
                    drafts={drafts}
                    record={record}
                >
                    <section className="grid gap-3 rounded-xl border bg-card p-5 sm:p-6">
                        <h2 className="font-semibold">
                            Histórico de leitos e responsáveis
                        </h2>
                        {history.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Aguardando leito.
                            </p>
                        ) : (
                            <ol className="grid gap-1 text-sm text-muted-foreground">
                                {history.map((entry) => (
                                    <li key={entry.id}>
                                        {entry.at} · {entry.text}
                                        {entry.reason && ` (${entry.reason})`}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>
                    <ExamResults results={examResults} />
                    <SurgeryDescriptions descriptions={surgeryDescriptions} />
                    <ClinicalDocuments
                        documents={documents}
                        attach={ClinicalDocumentController.storeForAdmission.form(
                            admission.id,
                        )}
                    />
                </NotesWorkspace>
            </Page>
        </>
    );
}

type DischargeOptions = {
    types: { value: string; label: string; summary: boolean }[];
    otherDrafts: number;
    now: string;
};

/**
 * Step 4.7: the responsible doctor discharges. Improvement, at request and transfer need a summary signed
 * with the password; death and evasion record the time. Access to the record ends with the discharge.
 */
function Discharge({
    admissionId,
    options,
}: {
    admissionId: number;
    options: DischargeOptions;
}) {
    const [type, setType] = useState(options.types[0].value);
    // Kept across type changes: switching to death and back must not lose a typed summary.
    const [summary, setSummary] = useState('');
    const withSummary =
        options.types.find((option) => option.value === type)?.summary ?? true;

    return (
        <details className="mb-6 rounded-xl border bg-card px-5 py-3">
            <summary className="flex min-h-11 cursor-pointer items-center font-medium">
                Dar alta
            </summary>
            <Form
                {...WardController.discharge.form(admissionId)}
                resetOnError={['password']}
                className="grid gap-4 py-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid max-w-sm gap-2">
                            <Label htmlFor="discharge_type">Tipo de alta</Label>
                            <select
                                id="discharge_type"
                                name="type"
                                value={type}
                                onChange={(event) =>
                                    setType(event.target.value)
                                }
                                className={selectClassName}
                            >
                                {options.types.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                message={errors.type ?? errors.bed_id}
                            />
                        </div>
                        {withSummary ? (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="discharge_summary">
                                        Sumário de alta
                                    </Label>
                                    <textarea
                                        id="discharge_summary"
                                        name="summary"
                                        value={summary}
                                        onChange={(event) =>
                                            setSummary(event.target.value)
                                        }
                                        required
                                        maxLength={20000}
                                        className={textareaClassName}
                                    />
                                    <InputError message={errors.summary} />
                                </div>
                                <div className="grid max-w-sm gap-2">
                                    <Label htmlFor="discharge_password">
                                        Sua senha, para assinar o sumário
                                    </Label>
                                    <Input
                                        id="discharge_password"
                                        name="password"
                                        type="password"
                                        autoComplete="current-password"
                                        required
                                        className="h-11"
                                    />
                                    <InputError message={errors.password} />
                                </div>
                            </>
                        ) : (
                            <div className="grid max-w-sm gap-2">
                                <Label htmlFor="discharge_occurred_at">
                                    Horário
                                </Label>
                                <Input
                                    id="discharge_occurred_at"
                                    name="occurred_at"
                                    type="datetime-local"
                                    required
                                    max={options.now}
                                    defaultValue={options.now}
                                    className="h-11"
                                />
                                <InputError message={errors.occurred_at} />
                            </div>
                        )}
                        <p className="text-sm text-muted-foreground">
                            A alta encerra a internação: o leito vai para
                            higienização e a equipe deixa de ler o prontuário
                            por ela.
                            {options.otherDrafts > 0 &&
                                ` ${options.otherDrafts === 1 ? 'Há 1 rascunho de evolução de outro profissional, que ficará' : `Há ${options.otherDrafts} rascunhos de evolução de outros profissionais, que ficarão`} sem assinatura.`}
                        </p>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            Registrar alta
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}

type TransferUnit = {
    id: number;
    name: string;
    sectors: { id: number; name: string }[];
    professionals: { id: number; name: string }[];
};

const transferLabels = {
    requested: 'aguardando a gestão de leitos do destino',
    accepted: 'aceita, leito reservado; aguardando a saída',
    departed: 'paciente em transferência; aguardando o recebimento',
};

/** Step 4.6: the open transfer; the requester may cancel it before the departure. */
function TransferStatus({
    transfer,
}: {
    transfer: {
        id: number;
        status: 'requested' | 'accepted' | 'departed';
        to: string | null;
        mine: boolean;
    };
}) {
    return (
        <section className="mb-6 grid gap-3 rounded-xl border border-primary/40 bg-card p-5 text-sm">
            <p className="font-medium">
                Transferência para {transfer.to}:{' '}
                {transferLabels[transfer.status]}
            </p>
            {transfer.mine && transfer.status !== 'departed' && (
                <Form
                    {...TransferController.cancel.form(transfer.id)}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="cancel-reason">
                                    Motivo do cancelamento
                                </Label>
                                <Input
                                    id="cancel-reason"
                                    name="reason"
                                    required
                                    maxLength={500}
                                    className="h-11"
                                />
                                <InputError message={errors.reason} />
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Cancelar transferência
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}

/** Step 4.6: the responsible doctor asks for a transfer to another unit of the network. */
function RequestTransfer({
    admissionId,
    units,
}: {
    admissionId: number;
    units: TransferUnit[];
}) {
    const [unitId, setUnitId] = useState(units[0].id);
    const unit = units.find((option) => option.id === unitId) ?? units[0];

    return (
        <details className="mb-6 rounded-xl border bg-card px-5 py-3">
            <summary className="flex min-h-11 cursor-pointer items-center font-medium">
                Transferir para outra unidade
            </summary>
            <Form
                {...TransferController.store.form(admissionId)}
                className="grid gap-4 py-3 sm:grid-cols-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="to_unit_id">
                                Unidade de destino
                            </Label>
                            <select
                                id="to_unit_id"
                                name="to_unit_id"
                                value={unitId}
                                onChange={(event) =>
                                    setUnitId(Number(event.target.value))
                                }
                                className={selectClassName}
                            >
                                {units.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.to_unit_id} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="transfer_sector_id">Setor</Label>
                            <select
                                key={`sector-${unit.id}`}
                                id="transfer_sector_id"
                                name="requested_sector_id"
                                required
                                className={selectClassName}
                            >
                                {unit.sectors.map((sector) => (
                                    <option key={sector.id} value={sector.id}>
                                        {sector.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.requested_sector_id} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="transfer_responsible_id">
                                Médico responsável no destino
                            </Label>
                            <select
                                key={`responsible-${unit.id}`}
                                id="transfer_responsible_id"
                                name="responsible_professional_id"
                                required
                                className={selectClassName}
                            >
                                {unit.professionals.map((professional) => (
                                    <option
                                        key={professional.id}
                                        value={professional.id}
                                    >
                                        {professional.name}
                                    </option>
                                ))}
                            </select>
                            <InputError
                                message={errors.responsible_professional_id}
                            />
                        </div>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start sm:col-span-3"
                        >
                            Solicitar transferência
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}

WardShow.layout = {
    breadcrumbs: [{ title: 'Internados', href: index() }],
};
