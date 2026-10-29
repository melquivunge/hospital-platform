import { Form, Head, Link, router } from '@inertiajs/react';
import BpaFileController from '@/actions/App/Http/Controllers/BpaFileController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/sus-bpa';

type BpaFile = {
    id: number;
    competence: string;
    lines: number;
    sheets: number;
    accounts: number;
    generatedAt: string;
    by: string | null;
};

/**
 * Step 6b.6a2 (ADR-0011): the unit's BPA files. The file of a competence is generated once, with every closed SUS
 * account of the BPA not presented yet, and downloaded to import into the BPA Magnético.
 */
export default function SusBpaIndex({
    units,
    unitId,
    ready,
    competence,
    files,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    ready: number;
    competence: string;
    files: BpaFile[];
}) {
    const unit = units.find((option) => option.id === unitId);

    return (
        <>
            <Head title="BPA (SUS)" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={unit?.name}
                    title="BPA (SUS)"
                    description="Arquivo da produção ambulatorial no layout do BPA Magnético. Leva as contas SUS fechadas ainda não apresentadas e os itens de linhas rejeitadas pelo gestor numa competência anterior."
                />
                {units.length > 1 && (
                    <div className="mb-6 grid w-full max-w-sm gap-2">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            value={unitId}
                            onChange={(event) =>
                                router.get(
                                    index({
                                        query: { unit: event.target.value },
                                    }).url,
                                )
                            }
                            className={selectClassName}
                        >
                            {units.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                <section
                    aria-labelledby="generate-heading"
                    className="mb-8 grid gap-4 rounded-xl border bg-card p-5 sm:p-6"
                >
                    <div>
                        <h2 id="generate-heading" className="font-medium">
                            Gerar arquivo
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {ready === 0
                                ? 'Nenhuma conta SUS de BPA fechada esperando apresentação.'
                                : ready === 1
                                  ? '1 conta fechada esperando apresentação.'
                                  : `${ready} contas fechadas esperando apresentação.`}
                        </p>
                    </div>
                    <Form
                        {...BpaFileController.store.form()}
                        className="flex flex-wrap items-end gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="unit_id"
                                    value={unitId}
                                />
                                <div className="grid gap-2">
                                    <Label htmlFor="competence">
                                        Competência de apresentação
                                    </Label>
                                    <Input
                                        id="competence"
                                        name="competence"
                                        type="month"
                                        required
                                        max={competence}
                                        defaultValue={competence}
                                        aria-invalid={!!errors.competence}
                                        aria-describedby={
                                            errors.competence
                                                ? 'competence-error'
                                                : undefined
                                        }
                                        className="h-11 w-48"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing || ready === 0}
                                    className="min-h-11"
                                >
                                    Gerar BPA
                                </Button>
                                <InputError
                                    id="competence-error"
                                    message={errors.competence}
                                    className="w-full whitespace-pre-line"
                                />
                            </>
                        )}
                    </Form>
                </section>

                <section aria-labelledby="files-heading">
                    <h2
                        id="files-heading"
                        className="mb-3 text-lg font-semibold tracking-tight"
                    >
                        Arquivos gerados
                    </h2>
                    {files.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                            Nenhum BPA gerado nesta unidade.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border bg-card">
                            {files.map((file) => (
                                <li
                                    key={file.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm"
                                >
                                    <span className="grid gap-1">
                                        <span className="font-medium tabular-nums">
                                            Competência {file.competence}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {file.accounts}{' '}
                                            {file.accounts === 1
                                                ? 'conta'
                                                : 'contas'}{' '}
                                            · {file.lines}{' '}
                                            {file.lines === 1
                                                ? 'linha'
                                                : 'linhas'}{' '}
                                            · {file.sheets}{' '}
                                            {file.sheets === 1
                                                ? 'folha'
                                                : 'folhas'}{' '}
                                            · gerado em {file.generatedAt}
                                            {file.by && ` por ${file.by}`}
                                        </span>
                                    </span>
                                    <span className="flex flex-wrap gap-2">
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="min-h-11"
                                        >
                                            <Link
                                                href={BpaFileController.show.url(
                                                    file.id,
                                                )}
                                            >
                                                Retorno do gestor
                                            </Link>
                                        </Button>
                                        <Button
                                            asChild
                                            variant="outline"
                                            className="min-h-11"
                                        >
                                            <a
                                                href={BpaFileController.download.url(
                                                    file.id,
                                                )}
                                            >
                                                Baixar arquivo
                                            </a>
                                        </Button>
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

SusBpaIndex.layout = {
    breadcrumbs: [{ title: 'BPA (SUS)', href: index() }],
};
