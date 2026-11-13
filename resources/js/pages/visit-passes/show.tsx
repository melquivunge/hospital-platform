import { Form, Head, Link } from '@inertiajs/react';
import VisitPassController from '@/actions/App/Http/Controllers/VisitPassController';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/**
 * Step 7b.1: the arrival's QR, shown once to the patient (or printed). It opens only the journey of this arrival.
 */
export default function VisitPassShow({
    pass,
    qr,
    url,
    back,
}: {
    pass: { id: number; revoked: boolean; createdAt: string };
    qr: string | null;
    url: string | null;
    back: string;
}) {
    return (
        <>
            <Head title="QR da visita" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Atendimento"
                    title="QR da visita"
                    description="O paciente acompanha a etapa da visita pelo celular, sem conta. Não mostra nome nem resultados; vale até 12 horas depois da alta."
                />
                <div className="grid gap-4 rounded-xl border bg-card p-4 text-sm">
                    {pass.revoked ? (
                        <p>Este QR foi revogado.</p>
                    ) : qr && url ? (
                        <>
                            <div
                                className="w-fit rounded-md bg-white p-3"
                                role="img"
                                aria-label="QR da visita"
                                dangerouslySetInnerHTML={{ __html: qr }}
                            />
                            <p className="break-all text-muted-foreground">
                                {url}
                            </p>
                            <div className="flex flex-wrap gap-3 print:hidden">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => window.print()}
                                >
                                    Imprimir
                                </Button>
                            </div>
                        </>
                    ) : (
                        <p>
                            O QR só aparece logo depois de gerado. Para mostrar
                            de novo, gere um novo QR (o anterior deixa de
                            valer).
                        </p>
                    )}
                    {!pass.revoked && (
                        <Form
                            {...VisitPassController.revoke.form(pass.id)}
                            className="print:hidden"
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    {processing && <Spinner />}
                                    Revogar este QR
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
                <p className="mt-4 text-sm">
                    <Link
                        href={back}
                        className="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Voltar
                    </Link>
                </p>
            </Page>
        </>
    );
}
