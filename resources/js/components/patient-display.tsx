/** Patient display helpers shared by the patient pages (step 3.2). */
export type PatientSummary = {
    id: number;
    name: string;
    socialName: string | null;
    birthDate: string | null;
    sex: string | null;
    status: 'provisional' | 'complete';
    cpf: string | null;
    cns: string | null;
    unit: string | null;
};

export const sexLabels: Record<string, string> = {
    female: 'Feminino',
    male: 'Masculino',
    unknown: 'Não informado',
};

export function formatBirthDate(date: string | null): string {
    if (date === null) {
        return 'Nascimento não informado';
    }

    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

export function StatusBadge({ status }: { status: PatientSummary['status'] }) {
    return status === 'complete' ? null : (
        <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-500/15 dark:text-amber-200">
            Provisório
        </span>
    );
}

/** Name the person wants to be called first, the civil name as context. */
export function PatientName({ patient }: { patient: PatientSummary }) {
    return (
        <span className="grid">
            <span className="font-medium">
                {patient.socialName ?? patient.name}
            </span>
            {patient.socialName && (
                <span className="text-xs text-muted-foreground">
                    Nome civil: {patient.name}
                </span>
            )}
        </span>
    );
}
