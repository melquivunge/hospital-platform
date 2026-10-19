import { Form, router, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import VisitController from '@/actions/App/Http/Controllers/VisitController';
import type { RecordNote, SignedNote } from '@/components/clinical-record';
import { RecordEntry } from '@/components/clinical-record';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type FormAction = { action: string; method: 'post' };

export type Draft = { id: number; body: string; addendumOf: number | null };

export const textareaClassName =
    'min-h-40 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

/**
 * The note being written (drafts, signing) and the signed record with addenda: shared by the visit (step
 * 3.6a) and the admission (step 4.4b). `children` go between the two sections (documents).
 */
export function NotesWorkspace({
    title,
    action,
    drafts,
    record,
    children,
}: {
    title: string;
    action: FormAction;
    drafts: Draft[];
    record: RecordNote[];
    children?: ReactNode;
}) {
    const ownDraft = drafts.some((draft) => draft.addendumOf === null);

    return (
        <div className="grid gap-8">
            <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
                <h2 className="font-semibold">{title}</h2>
                <p className="text-sm text-muted-foreground">
                    O rascunho é visto só por você. Depois de assinada, a
                    evolução não muda: para corrigir, registre um adendo.
                </p>
                {drafts.map((draft) => (
                    <DraftEditor
                        key={draft.id}
                        draft={draft}
                        original={record.find(
                            (note) => note.id === draft.addendumOf,
                        )}
                    />
                ))}
                {!ownDraft && <NewNote action={action} />}
            </section>

            {children}

            <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
                <h2 className="font-semibold">Prontuário</h2>
                {record.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nenhuma evolução assinada ainda.
                    </p>
                ) : (
                    <ol className="grid gap-4">
                        {record.map((note) => (
                            <RecordEntry key={note.id} note={note}>
                                {!drafts.some(
                                    (draft) => draft.addendumOf === note.id,
                                ) && (
                                    <NewNote
                                        action={action}
                                        addendumOf={note}
                                    />
                                )}
                            </RecordEntry>
                        ))}
                    </ol>
                )}
            </section>
        </div>
    );
}

/** A new draft: the note of this visit or admission, or (inside a disclosure) an addendum to a signed note. */
function NewNote({
    action,
    addendumOf,
}: {
    action: FormAction;
    addendumOf?: SignedNote;
}) {
    const id = addendumOf ? `addendum-${addendumOf.id}` : 'new-note';
    const form = (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    {addendumOf && (
                        <input
                            type="hidden"
                            name="addendum_of_id"
                            value={addendumOf.id}
                        />
                    )}
                    <Label htmlFor={id}>
                        {addendumOf ? 'Texto do adendo' : 'Nova evolução'}
                    </Label>
                    <textarea
                        id={id}
                        name="body"
                        required
                        maxLength={20000}
                        className={textareaClassName}
                    />
                    <InputError message={errors.body} />
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Salvar rascunho
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );

    return addendumOf ? (
        <details>
            <summary className="inline-flex min-h-11 cursor-pointer items-center rounded-md px-3 text-sm text-muted-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                Registrar adendo
            </summary>
            <div className="mt-2">{form}</div>
        </details>
    ) : (
        form
    );
}

/** The author's draft: saved as often as needed, then signed with the password. */
function DraftEditor({
    draft,
    original,
}: {
    draft: Draft;
    original?: RecordNote;
}) {
    const { errors } = usePage().props;
    const [body, setBody] = useState(draft.body);
    const [password, setPassword] = useState('');
    const [processing, setProcessing] = useState(false);
    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };
    const id = `draft-${draft.id}`;

    return (
        <form
            className="grid gap-3 rounded-lg border border-dashed p-4"
            onSubmit={(event) => {
                event.preventDefault();
                router.post(
                    VisitController.signNote.url(draft.id),
                    { body, password },
                    { ...options, onError: () => setPassword('') },
                );
            }}
        >
            <Label htmlFor={id}>
                {original
                    ? `Rascunho de adendo à evolução de ${original.signedAt}`
                    : 'Rascunho da evolução'}
            </Label>
            <textarea
                id={id}
                value={body}
                onChange={(event) => setBody(event.target.value)}
                required
                maxLength={20000}
                className={textareaClassName}
            />
            <InputError message={errors.body} />
            <div className="flex flex-wrap items-end justify-between gap-3">
                <Button
                    type="button"
                    variant="outline"
                    disabled={processing}
                    onClick={() =>
                        router.put(
                            VisitController.updateNote.url(draft.id),
                            { body },
                            options,
                        )
                    }
                    className="min-h-11"
                >
                    Salvar rascunho
                </Button>
                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-password`}>
                            Senha para assinar
                        </Label>
                        <Input
                            id={`${id}-password`}
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(event) =>
                                setPassword(event.target.value)
                            }
                            required
                            className="h-11"
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={processing}
                        className="min-h-11"
                    >
                        Assinar
                    </Button>
                </div>
            </div>
            <InputError message={errors.password} />
        </form>
    );
}
