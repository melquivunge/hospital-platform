import { Form, Head, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import ImportController from '@/actions/App/Http/Controllers/ImportController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, template } from '@/routes/imports';

const columnHelp: Record<string, string> = {
    source_id: 'identificador no sistema de origem (obrigatório)',
    name: 'nome completo (obrigatório)',
    base_unit: 'nome da unidade-base, igual ao cadastro (obrigatório)',
    council: 'conselho, por exemplo CRM ou COREN',
    council_number: 'número no conselho',
    council_uf: 'UF do conselho',
    email: 'e-mail',
    phone: 'telefone',
    unit: 'nome da unidade (obrigatório)',
    sector: 'nome do setor da unidade (obrigatório)',
    function: 'nome da função (obrigatório)',
    start: 'início com data, hora e fuso, por exemplo 2026-11-10T07:00-03:00',
    end: 'fim no mesmo formato',
    active_ingredient:
        'princípio ativo (DCB), por exemplo Dipirona (obrigatório)',
    form: 'forma farmacêutica, por exemplo Comprimido (obrigatório)',
    strength: 'concentração, por exemplo 500 mg (obrigatório)',
    dose_unit:
        'mg, mcg, g, mL, UI, gota, unidade, aplicação ou jato (obrigatório)',
    routes: 'vias separadas por |: oral, sublingual, enteral, iv, im, sc, inhalation, nasal, topical, ophthalmic, otic, rectal, vaginal',
    controlled: 'sim ou não (vazio = não)',
    high_alert: 'sim ou não (vazio = não)',
    max_single_dose:
        'dose máxima por administração, na unidade de dose (opcional)',
    max_daily_dose: 'dose máxima por dia, na unidade de dose (opcional)',
    ingredient_a: 'princípio ativo já no catálogo (obrigatório)',
    ingredient_b: 'outro princípio ativo já no catálogo (obrigatório)',
    severity: 'leve, moderada, grave ou contraindicada',
    note: 'orientação ao prescritor (obrigatório)',
    professional_source_id:
        'source_id do profissional importado pela mesma origem; vazio = vaga aberta',
};

type ImportType =
    | 'professionals'
    | 'shifts'
    | 'medications'
    | 'medication_interactions';

const typeLabels: Record<ImportType, string> = {
    professionals: 'Profissionais',
    shifts: 'Plantões (entram como rascunho)',
    medications: 'Catálogo de medicamentos',
    medication_interactions:
        'Interações entre princípios ativos (depois do catálogo)',
};

export default function ImportCreate({
    columns,
    maxLines,
}: {
    columns: Partial<Record<ImportType, string[]>>;
    maxLines: number;
}) {
    const [type, setType] = useState<ImportType>('professionals');

    return (
        <>
            <Head title="Nova importação" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Importações"
                    title="Nova importação"
                    description={`CSV em UTF-8, separado por ponto e vírgula ou vírgula, até ${maxLines} linhas. Primeiro você vê a prévia; nada é gravado sem confirmação. Importar não aprova habilitações nem publica plantões.`}
                />

                <fieldset className="mb-6 grid gap-2 rounded-lg border bg-card p-5 text-sm">
                    <legend className="px-1 font-semibold tracking-tight">
                        O que importar
                    </legend>
                    {(Object.keys(columns) as ImportType[]).map((option) => (
                        <label
                            key={option}
                            className="flex min-h-11 items-center gap-3"
                        >
                            <input
                                type="radio"
                                name="import-type"
                                value={option}
                                checked={type === option}
                                onChange={() => setType(option)}
                                className="size-4 accent-primary"
                            />
                            {typeLabels[option]}
                        </label>
                    ))}
                </fieldset>

                <section
                    aria-labelledby="columns-heading"
                    className="mb-6 rounded-lg border bg-card p-5 text-sm"
                >
                    <h2
                        id="columns-heading"
                        className="mb-3 font-semibold tracking-tight"
                    >
                        Colunas do arquivo
                    </h2>
                    <dl className="grid gap-1">
                        {(columns[type] ?? []).map((column) => (
                            <div
                                key={column}
                                className="flex flex-wrap gap-x-2"
                            >
                                <dt className="font-mono font-medium">
                                    {column}
                                </dt>
                                <dd className="text-muted-foreground">
                                    {columnHelp[column]}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <Button variant="outline" asChild className="mt-4 min-h-11">
                        <a href={template({ query: { type } }).url}>
                            <Download aria-hidden="true" />
                            Baixar modelo
                        </a>
                    </Button>
                </section>

                <Form
                    {...ImportController.store.form()}
                    className="overflow-hidden rounded-lg border bg-card"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="type" value={type} />
                            <div className="grid gap-6 p-5 sm:p-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="source_system">
                                        Sistema de origem
                                    </Label>
                                    <Input
                                        id="source_system"
                                        name="source_system"
                                        required
                                        maxLength={100}
                                        placeholder="Ex.: Planilha RH 2026"
                                        aria-invalid={!!errors.source_system}
                                        aria-describedby="source_system-hint source_system-error"
                                        className="h-11"
                                    />
                                    <p
                                        id="source_system-hint"
                                        className="text-xs text-muted-foreground"
                                    >
                                        Use sempre o mesmo nome para a mesma
                                        origem: é o que evita duplicar quem já
                                        foi importado.
                                    </p>
                                    <InputError
                                        id="source_system-error"
                                        message={errors.source_system}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="file">Arquivo CSV</Label>
                                    <Input
                                        id="file"
                                        name="file"
                                        type="file"
                                        required
                                        accept=".csv,text/csv"
                                        aria-invalid={!!errors.file}
                                        aria-describedby={
                                            errors.file
                                                ? 'file-error'
                                                : undefined
                                        }
                                        className="h-11"
                                    />
                                    <InputError
                                        id="file-error"
                                        message={errors.file}
                                    />
                                </div>
                            </div>
                            <div className="flex flex-wrap justify-end gap-3 border-t bg-muted/40 px-5 py-4 sm:px-6">
                                <Button
                                    variant="ghost"
                                    asChild
                                    className="min-h-11"
                                >
                                    <Link href={index()}>Cancelar</Link>
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    {processing && <Spinner />}
                                    Ver prévia
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

ImportCreate.layout = {
    breadcrumbs: [{ title: 'Importações', href: index() }],
};
