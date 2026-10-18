import { Form } from '@inertiajs/react';
import { FileText, Paperclip } from 'lucide-react';
import ClinicalDocumentController from '@/actions/App/Http/Controllers/ClinicalDocumentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type ClinicalDocument = {
    id: number;
    name: string;
    type: 'PDF' | 'JPG' | 'PNG';
    sizeKb: number;
    attachedAt: string | null;
    author: string | null;
    withdrawnAt: string | null;
    withdrawReason: string | null;
    canWithdraw: boolean;
};

/**
 * Documents of the record (step 3.7). With `visitId` the professional attaches files and withdraws their
 * own; without it the list is read-only (emergency access). Downloads are plain links: the server logs each.
 */
export function ClinicalDocuments({
    documents,
    visitId,
}: {
    documents: ClinicalDocument[];
    visitId?: number;
}) {
    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">Documentos</h2>
            {documents.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nenhum documento anexado.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {documents.map((document) => (
                        <li
                            key={document.id}
                            className="grid gap-2 px-4 py-3 text-sm"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="flex min-w-0 items-center gap-2">
                                    <FileText
                                        className="size-4 shrink-0 text-primary"
                                        aria-hidden="true"
                                    />
                                    <span className="grid min-w-0 gap-0.5">
                                        <span className="truncate font-medium">
                                            {document.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {document.type} · {document.sizeKb}{' '}
                                            KB · {document.attachedAt} ·{' '}
                                            {document.author}
                                        </span>
                                    </span>
                                </span>
                                {document.withdrawnAt === null && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <a
                                            href={ClinicalDocumentController.download.url(
                                                document.id,
                                            )}
                                            aria-label={`Baixar ${document.name}`}
                                        >
                                            Baixar
                                        </a>
                                    </Button>
                                )}
                            </div>
                            {document.withdrawnAt !== null ? (
                                <p className="text-muted-foreground">
                                    Retirado em {document.withdrawnAt}:{' '}
                                    {document.withdrawReason}
                                </p>
                            ) : (
                                visitId !== undefined &&
                                document.canWithdraw && (
                                    <WithdrawForm document={document} />
                                )
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {visitId !== undefined && <AttachForm visitId={visitId} />}
        </section>
    );
}

function AttachForm({ visitId }: { visitId: number }) {
    return (
        <Form
            {...ClinicalDocumentController.store.form(visitId)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-3 border-t pt-4"
        >
            {({ processing, errors, progress }) => (
                <>
                    <Label htmlFor="document-file">
                        Anexar documento (PDF, JPG ou PNG, até 10 MB)
                    </Label>
                    <div className="flex flex-wrap items-center gap-3">
                        <Input
                            id="document-file"
                            name="file"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                            required
                            className="h-11 min-w-0 flex-1"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11"
                        >
                            <Paperclip aria-hidden="true" />
                            {progress
                                ? `Enviando ${progress.percentage ?? 0}%`
                                : 'Anexar'}
                        </Button>
                    </div>
                    <InputError message={errors.file} />
                </>
            )}
        </Form>
    );
}

function WithdrawForm({ document }: { document: ClinicalDocument }) {
    const id = `withdraw-${document.id}`;

    return (
        <details>
            <summary
                aria-label={`Retirar ${document.name}`}
                className="inline-flex min-h-11 cursor-pointer items-center rounded-md px-3 text-muted-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                Retirar
            </summary>
            <Form
                {...ClinicalDocumentController.withdraw.form(document.id)}
                options={{ preserveScroll: true }}
                className="mt-2 flex flex-wrap items-end gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid content-start gap-2">
                            <Label htmlFor={id}>Motivo da retirada</Label>
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
                            Confirmar retirada
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}
