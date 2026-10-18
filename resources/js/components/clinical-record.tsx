import type { ReactNode } from 'react';
import { formatBirthDate, sexLabels } from '@/components/patient-display';

export type SignedNote = {
    id: number;
    body: string;
    signedAt: string | null;
    author: string | null;
};

export type RecordNote = SignedNote & {
    unit: string | null;
    addenda: SignedNote[];
};

export type RecordPatient = {
    name: string;
    civilName: string | null;
    birthDate: string | null;
    sex: string | null;
};

/** Identification line under the patient's name; never clinical content. */
export function patientDescription(patient: RecordPatient): string {
    return [
        patient.civilName && `Nome civil: ${patient.civilName}`,
        patient.birthDate &&
            `Nascimento: ${formatBirthDate(patient.birthDate)}`,
        patient.sex && sexLabels[patient.sex],
    ]
        .filter(Boolean)
        .join(' · ');
}

export function NoteBody({
    note,
    detail,
}: {
    note: SignedNote;
    detail: string | null;
}) {
    return (
        <article className="grid gap-1 text-sm">
            <p className="text-xs text-muted-foreground">
                {note.signedAt} · {note.author}
                {detail && ` · ${detail}`}
            </p>
            <p className="whitespace-pre-wrap">{note.body}</p>
        </article>
    );
}

/** A signed note with its signed addenda; `children` adds actions below them. */
export function RecordEntry({
    note,
    children,
}: {
    note: RecordNote;
    children?: ReactNode;
}) {
    return (
        <li className="grid gap-3 rounded-lg border p-4">
            <NoteBody note={note} detail={note.unit} />
            {note.addenda.map((addendum) => (
                <div
                    key={addendum.id}
                    className="grid gap-2 border-l-2 border-primary/40 pl-4"
                >
                    <NoteBody note={addendum} detail="Adendo" />
                </div>
            ))}
            {children}
        </li>
    );
}
