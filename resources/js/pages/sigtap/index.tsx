import { Form, Head } from '@inertiajs/react';
import SigtapController from '@/actions/App/Http/Controllers/SigtapController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/sigtap';

/**
 * Step 6b.3 (ADR-0011): the official SIGTAP table of each competence, imported from the DATASUS zip by the network
 * admin; the SUS accounts take their procedures and values from it.
 */
export default function SigtapIndex({
    competences,
}: {
    competences: {
        id: number;
        competence: string;
        fileName: string;
        procedures: number;
        importedAt: string;
        by: string | null;
    }[];
}) {
    return (
        <>
            <Head title="Tabela SIGTAP" />
            <Page>
                <PageHeader
                    eyebrow="Faturamento SUS"
                    title="Tabela SIGTAP"
                    description="Procedimentos, valores e compatibilidades do SUS por competência. Baixe o ZIP oficial no site da SIGTAP (TabelaUnificada_AAAAMM) e importe aqui a cada mês."
                />
                <Form
                    {...SigtapController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="mb-8 grid gap-4 rounded-xl border bg-card p-5 sm:max-w-xl"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="sigtap-file">
                                    ZIP oficial da competência
                                </Label>
                                <Input
                                    id="sigtap-file"
                                    name="file"
                                    type="file"
                                    required
                                    accept=".zip,application/zip"
                                    aria-invalid={!!errors.file}
                                    aria-describedby={
                                        errors.file
                                            ? 'sigtap-file-error'
                                            : undefined
                                    }
                                    className="h-11"
                                />
                                <InputError
                                    id="sigtap-file-error"
                                    message={errors.file}
                                />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Importar de novo a mesma competência substitui a
                                anterior; as contas já geradas não mudam.
                            </p>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11 justify-self-start"
                            >
                                {processing && <Spinner />}
                                Importar
                            </Button>
                        </>
                    )}
                </Form>
                <section aria-labelledby="competences" className="grid gap-3">
                    <h2 id="competences" className="text-lg font-semibold">
                        Competências importadas
                    </h2>
                    {competences.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-8 text-center text-muted-foreground">
                            Nenhuma competência importada ainda.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border bg-card">
                            {competences.map((competence) => (
                                <li
                                    key={competence.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {competence.competence}
                                        </span>
                                        <span className="text-sm break-all text-muted-foreground">
                                            {competence.fileName} · importada em{' '}
                                            {competence.importedAt}
                                            {competence.by &&
                                                ` por ${competence.by}`}
                                        </span>
                                    </span>
                                    <span className="text-sm tabular-nums">
                                        {competence.procedures} procedimentos
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}

SigtapIndex.layout = {
    breadcrumbs: [{ title: 'Tabela SIGTAP', href: index() }],
};
