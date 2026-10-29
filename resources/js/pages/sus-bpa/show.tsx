import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import BpaFileController from '@/actions/App/Http/Controllers/BpaFileController';
import BillingAccountController from '@/actions/App/Http/Controllers/BillingAccountController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/sus-bpa';

type Line = {
    id: number;
    kind: 'C' | 'I';
    sheet: number;
    sequence: number;
    procedure: string;
    done: string;
    quantity: number;
    accounts: number[];
    approved: boolean | null;
    reason: string | null;
    recordedAt: string | null;
    editable: boolean;
};

/**
 * Step 6b.7b: the lines of a BPA file and the manager's return of each (approved, or rejected with the reason). The
 * items of a rejected line go back to the file of a later competence; their account reopens to be corrected first.
 */
export default function SusBpaShow({
    file,
    lines,
}: {
    file: { id: number; unit: string; competence: string };
    lines: Line[];
}) {
    return (
        <>
            <Head title={`BPA ${file.competence}`} />
            <Page width="narrow">
                <PageHeader
                    eyebrow={file.unit}
                    title={`Retorno do BPA · ${file.competence}`}
                    description="Registre o que o gestor aprovou e rejeitou em cada linha deste arquivo. Os itens de uma linha rejeitada voltam no arquivo de uma competência seguinte; para corrigi-los antes, reabra a conta."
                />
                {lines.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Este arquivo foi gerado antes do registro das linhas:
                        não recebe retorno por linha.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {lines.map((line) => (
                            <LineReturn
                                key={line.id}
                                fileId={file.id}
                                line={line}
                            />
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

function LineReturn({ fileId, line }: { fileId: number; line: Line }) {
    const [rejecting, setRejecting] = useState(false);

    return (
        <li className="grid gap-3 px-5 py-4 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="grid gap-1">
                    <span className="font-medium tabular-nums">
                        BPA-{line.kind} · folha {line.sheet}, linha{' '}
                        {line.sequence} · {line.procedure}
                    </span>
                    <span className="text-muted-foreground tabular-nums">
                        Realizado em {line.done} · quantidade {line.quantity} ·{' '}
                        {line.accounts.map((id, index) => (
                            <span key={id}>
                                {index > 0 && ', '}
                                <Link
                                    href={BillingAccountController.show.url(id)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    conta {id}
                                </Link>
                            </span>
                        ))}
                    </span>
                </span>
                <span className="text-muted-foreground">
                    {line.approved === null
                        ? 'Sem retorno'
                        : line.approved
                          ? `Aprovada em ${line.recordedAt}`
                          : `Rejeitada em ${line.recordedAt}: ${line.reason}`}
                </span>
            </div>
            {line.editable && (
                <Form
                    {...BpaFileController.recordReturn.form(fileId)}
                    errorBag={`return${line.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setRejecting(false)}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="line_id"
                                value={line.id}
                            />
                            <input
                                type="hidden"
                                name="approved"
                                value={rejecting ? '0' : '1'}
                            />
                            {rejecting ? (
                                <>
                                    <div className="grid min-w-0 flex-1 gap-2">
                                        <Label htmlFor={`reason-${line.id}`}>
                                            Motivo da rejeição
                                        </Label>
                                        <Input
                                            id={`reason-${line.id}`}
                                            name="reason"
                                            required
                                            maxLength={500}
                                            autoFocus
                                            aria-invalid={!!errors.reason}
                                            className="h-11"
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        Registrar rejeição
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setRejecting(false)}
                                        className="min-h-11"
                                    >
                                        Cancelar
                                    </Button>
                                </>
                            ) : (
                                <>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        Aprovada
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setRejecting(true)}
                                        className="min-h-11"
                                    >
                                        Rejeitada…
                                    </Button>
                                </>
                            )}
                            <InputError
                                message={errors.reason}
                                className="w-full"
                            />
                        </>
                    )}
                </Form>
            )}
        </li>
    );
}

SusBpaShow.layout = {
    breadcrumbs: [{ title: 'BPA (SUS)', href: index() }],
};
