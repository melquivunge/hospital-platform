import type { ReactNode } from 'react';

export type SurgeryDescriptionAddendum = {
    id: number;
    body: string;
    author: string | null;
    signedAt: string;
};

export type SurgeryDescriptionData = {
    id: number;
    procedure: string;
    laterality: string;
    findings: string;
    technique: string;
    complications: string;
    specimens: string | null;
    author: string | null;
    signedAt: string;
    addenda: SurgeryDescriptionAddendum[];
};

const lateralities: Record<string, string> = {
    left: 'esquerdo',
    right: 'direito',
    bilateral: 'bilateral',
};

/** A signed surgical description (step 5b.5) with its addenda; `children` adds actions below them. */
export function SurgeryDescriptionEntry({
    description,
    children,
}: {
    description: SurgeryDescriptionData;
    children?: ReactNode;
}) {
    const side = lateralities[description.laterality];

    return (
        <li className="grid gap-3 rounded-lg border p-4 text-sm">
            <div className="grid gap-0.5">
                <span className="font-medium">
                    {description.procedure}
                    {side && ` (${side})`}
                </span>
                <span className="text-xs text-muted-foreground">
                    Assinada em {description.signedAt} por {description.author}
                </span>
            </div>
            <dl className="grid gap-2">
                {(
                    [
                        ['Achados', description.findings],
                        ['Técnica', description.technique],
                        ['Intercorrências', description.complications],
                        ['Peças enviadas', description.specimens ?? 'Nenhuma'],
                    ] as const
                ).map(([label, text]) => (
                    <div key={label}>
                        <dt className="text-xs font-medium text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="whitespace-pre-wrap">{text}</dd>
                    </div>
                ))}
            </dl>
            {description.addenda.map((addendum) => (
                <article
                    key={addendum.id}
                    className="grid gap-1 border-l-2 border-primary/40 pl-4"
                >
                    <p className="text-xs text-muted-foreground">
                        {addendum.signedAt} · {addendum.author} · Adendo
                    </p>
                    <p className="whitespace-pre-wrap">{addendum.body}</p>
                </article>
            ))}
            {children}
        </li>
    );
}

/** The signed surgical descriptions of the record; nothing when the patient has none. */
export function SurgeryDescriptions({
    descriptions,
}: {
    descriptions: SurgeryDescriptionData[];
}) {
    if (descriptions.length === 0) {
        return null;
    }

    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">Descrições cirúrgicas</h2>
            <ul className="grid gap-4">
                {descriptions.map((description) => (
                    <SurgeryDescriptionEntry
                        key={description.id}
                        description={description}
                    />
                ))}
            </ul>
        </section>
    );
}
