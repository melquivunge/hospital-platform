import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AihFileController from '@/actions/App/Http/Controllers/AihFileController';
import BillingAccountController from '@/actions/App/Http/Controllers/BillingAccountController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/sus-aih';

type Aih = {
    id: number;
    accountId: number;
    number: string | null;
    approved: boolean | null;
    reason: string | null;
    recordedAt: string | null;
    editable: boolean;
};

/**
 * Step 6b.7a: the AIHs a file presented and the manager's return of each (approved, or rejected with the reason). A
 * rejected AIH goes back to the file of a later competence; its account reopens to be corrected first.
 */
export default function SusAihShow({
    file,
    aihs,
}: {
    file: { id: number; unitId: number; unit: string; competence: string };
    aihs: Aih[];
}) {
    return (
        <>
            <Head title={`AIH ${file.competence}`} />
            <Page width="narrow">
                <PageHeader
                    eyebrow={file.unit}
                    title={`Retorno da AIH · ${file.competence}`}
                    description="Registre o que o gestor aprovou e rejeitou neste arquivo. A AIH rejeitada volta no arquivo de uma competência seguinte; para corrigi-la antes, reabra a conta."
                />
                <ul className="divide-y rounded-xl border bg-card">
                    {aihs.map((aih) => (
                        <AihReturn key={aih.id} fileId={file.id} aih={aih} />
                    ))}
                </ul>
            </Page>
        </>
    );
}

function AihReturn({ fileId, aih }: { fileId: number; aih: Aih }) {
    const [rejecting, setRejecting] = useState(false);

    return (
        <li className="grid gap-3 px-5 py-4 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <Link
                    href={BillingAccountController.show.url(aih.accountId)}
                    className="font-medium tabular-nums underline-offset-4 hover:underline"
                >
                    AIH {aih.number ?? 'sem número'} · conta {aih.accountId}
                </Link>
                <span className="text-muted-foreground">
                    {aih.approved === null
                        ? 'Sem retorno'
                        : aih.approved
                          ? `Aprovada em ${aih.recordedAt}`
                          : `Rejeitada em ${aih.recordedAt}: ${aih.reason}`}
                </span>
            </div>
            {aih.editable && (
                <Form
                    {...AihFileController.recordReturn.form(fileId)}
                    errorBag={`return${aih.id}`}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setRejecting(false)}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="presentation_id"
                                value={aih.id}
                            />
                            <input
                                type="hidden"
                                name="approved"
                                value={rejecting ? '0' : '1'}
                            />
                            {rejecting ? (
                                <>
                                    <div className="grid min-w-0 flex-1 gap-2">
                                        <Label htmlFor={`reason-${aih.id}`}>
                                            Motivo da rejeição
                                        </Label>
                                        <Input
                                            id={`reason-${aih.id}`}
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

SusAihShow.layout = {
    breadcrumbs: [{ title: 'AIH (SUS)', href: index() }],
};
