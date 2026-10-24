import { Form, Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import ExamController from '@/actions/App/Http/Controllers/ExamController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/exams';

type Analyte = {
    id?: number;
    name: string;
    unit: string | null;
    reference_min: string | null;
    reference_max: string | null;
    reference_text: string | null;
};

type Exam = {
    id: number;
    type: 'laboratory' | 'imaging';
    name: string;
    specimen: string;
    preparation: string | null;
    code: string | null;
    active: boolean;
    analytes: Analyte[];
};

type Row = Analyte & { key: number };

let nextKey = 1;
const row = (analyte?: Analyte): Row => ({
    key: nextKey++,
    name: '',
    unit: null,
    reference_min: null,
    reference_max: null,
    reference_text: null,
    ...analyte,
});

/**
 * The network exam catalog (step 5.1a, delivery 5): laboratory exams with their analytes and imaging exams,
 * kept by the network admin. Deactivated, never deleted. No patient data.
 */
export default function ExamsIndex({
    q,
    total,
    limit,
    exams,
    types,
    targets,
}: {
    q: string;
    total: number;
    limit: number;
    exams: Exam[];
    types: Record<string, string>;
    targets: {
        exam_target_urgent_minutes: number;
        exam_target_routine_minutes: number;
    };
}) {
    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const value = new FormData(event.currentTarget).get('q');
        router.get(
            index({
                query: typeof value === 'string' && value ? { q: value } : {},
            }).url,
            {},
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title="Exames" />
            <Page>
                <PageHeader
                    title="Exames"
                    description="Catálogo de exames da rede. Laboratório com analitos e faixa de referência; imagem com laudo em texto. Exame é desativado, nunca apagado."
                />

                <section className="mb-8 grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Tempo-alvo das filas de exames
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Espera, em minutos, a partir da qual o pedido aparece
                        fora do prazo nas filas de Laboratório e Imagem.
                    </p>
                    <Form
                        {...ExamController.targets.form()}
                        errorBag="targets"
                        options={{ preserveScroll: true }}
                        className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="exam_target_urgent_minutes">
                                        Urgente (minutos)
                                    </Label>
                                    <Input
                                        id="exam_target_urgent_minutes"
                                        name="exam_target_urgent_minutes"
                                        type="number"
                                        min={1}
                                        max={10080}
                                        required
                                        defaultValue={
                                            targets.exam_target_urgent_minutes
                                        }
                                        className="h-11"
                                    />
                                    <InputError
                                        message={
                                            errors.exam_target_urgent_minutes
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="exam_target_routine_minutes">
                                        Rotina (minutos)
                                    </Label>
                                    <Input
                                        id="exam_target_routine_minutes"
                                        name="exam_target_routine_minutes"
                                        type="number"
                                        min={1}
                                        max={10080}
                                        required
                                        defaultValue={
                                            targets.exam_target_routine_minutes
                                        }
                                        className="h-11"
                                    />
                                    <InputError
                                        message={
                                            errors.exam_target_routine_minutes
                                        }
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    Salvar
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section className="mb-8 grid gap-3">
                    <h2 className="text-lg font-semibold">Novo exame</h2>
                    <ExamForm types={types} />
                </section>

                <form
                    onSubmit={search}
                    role="search"
                    className="mb-4 flex flex-wrap items-end gap-3"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="q">Buscar pelo nome</Label>
                        <Input
                            id="q"
                            name="q"
                            defaultValue={q}
                            className="h-11 w-72 max-w-full"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        className="min-h-11"
                    >
                        Buscar
                    </Button>
                </form>
                <p className="mb-3 text-sm text-muted-foreground">
                    {total} exame(s) no catálogo
                    {exams.length >= limit &&
                        `; mostrando os primeiros ${limit}`}
                    .
                </p>

                {exams.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Nenhum exame encontrado.
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {exams.map((exam) => (
                            <li
                                key={exam.id}
                                className="rounded-xl border bg-card px-5 py-4"
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <h3 className="font-medium">{exam.name}</h3>
                                    <span className="text-sm text-muted-foreground">
                                        {types[exam.type]} · {exam.specimen}
                                        {exam.code && ` · ${exam.code}`}
                                        {!exam.active && ' · inativo'}
                                    </span>
                                </div>
                                {exam.analytes.length > 0 && (
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {exam.analytes
                                            .map((analyte) => analyte.name)
                                            .join(', ')}
                                    </p>
                                )}
                                <details className="mt-2">
                                    <summary className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary">
                                        Alterar
                                    </summary>
                                    {/* Remounted from what was saved: new analytes get their ids. */}
                                    <ExamForm
                                        key={exam.analytes
                                            .map(
                                                (analyte) =>
                                                    `${analyte.id}:${analyte.name}`,
                                            )
                                            .join('|')}
                                        types={types}
                                        exam={exam}
                                    />
                                </details>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

function ExamForm({
    types,
    exam,
}: {
    types: Record<string, string>;
    exam?: Exam;
}) {
    const prefix = exam ? `exam-${exam.id}` : 'new-exam';
    const [type, setType] = useState<string>(exam?.type ?? 'laboratory');
    const [rows, setRows] = useState<Row[]>(
        exam ? exam.analytes.map((analyte) => row(analyte)) : [],
    );
    const action = exam
        ? ExamController.update.form(exam.id)
        : ExamController.store.form();

    return (
        <Form
            {...action}
            errorBag={exam ? `exam-${exam.id}` : 'newExam'}
            options={{ preserveScroll: true }}
            resetOnSuccess={!exam}
            onSuccess={() => !exam && setRows([])}
            className="mt-2 grid gap-4 rounded-xl border bg-card p-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            {exam ? (
                                <p className="grid gap-2 text-sm">
                                    <span className="font-medium">Tipo</span>
                                    <span className="min-h-11 py-2.5">
                                        {types[exam.type]} (não muda)
                                    </span>
                                </p>
                            ) : (
                                <Label htmlFor={`${prefix}-type`}>Tipo</Label>
                            )}
                            {!exam && (
                                <select
                                    id={`${prefix}-type`}
                                    name="type"
                                    value={type}
                                    onChange={(event) =>
                                        setType(event.target.value)
                                    }
                                    className={selectClassName}
                                >
                                    {Object.entries(types).map(
                                        ([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ),
                                    )}
                                </select>
                            )}
                            <InputError message={errors.type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${prefix}-name`}>Nome</Label>
                            <Input
                                id={`${prefix}-name`}
                                name="name"
                                required
                                maxLength={255}
                                defaultValue={exam?.name}
                                className="h-11"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${prefix}-specimen`}>
                                {type === 'imaging' ? 'Região' : 'Material'}
                            </Label>
                            <Input
                                id={`${prefix}-specimen`}
                                name="specimen"
                                required
                                maxLength={100}
                                defaultValue={exam?.specimen}
                                className="h-11"
                            />
                            <InputError message={errors.specimen} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${prefix}-code`}>
                                Código TUSS/SIGTAP (opcional)
                            </Label>
                            <Input
                                id={`${prefix}-code`}
                                name="code"
                                maxLength={20}
                                defaultValue={exam?.code ?? ''}
                                className="h-11"
                            />
                            <InputError message={errors.code} />
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor={`${prefix}-preparation`}>
                                Preparo (opcional)
                            </Label>
                            <textarea
                                id={`${prefix}-preparation`}
                                name="preparation"
                                maxLength={1000}
                                rows={2}
                                defaultValue={exam?.preparation ?? ''}
                                className="rounded-md border bg-background px-3 py-2 text-sm"
                            />
                            <InputError message={errors.preparation} />
                        </div>
                    </div>

                    {type === 'laboratory' && (
                        <fieldset className="grid gap-3">
                            <legend className="mb-1 text-sm font-medium">
                                Analitos
                            </legend>
                            {rows.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Nenhum analito. O resultado fica só em texto
                                    e anexo.
                                </p>
                            )}
                            {rows.map((analyte, position) => {
                                const field = (name: string) =>
                                    `${prefix}-analyte-${analyte.key}-${name}`;
                                const error = (name: string) =>
                                    errors[`analytes.${position}.${name}`];

                                return (
                                    <div
                                        key={analyte.key}
                                        className="grid gap-3 rounded-lg border p-3 sm:grid-cols-6"
                                    >
                                        {analyte.id && (
                                            <input
                                                type="hidden"
                                                name={`analytes[${position}][id]`}
                                                value={analyte.id}
                                            />
                                        )}
                                        <div className="grid gap-1 sm:col-span-2">
                                            <Label htmlFor={field('name')}>
                                                Nome
                                            </Label>
                                            <Input
                                                id={field('name')}
                                                name={`analytes[${position}][name]`}
                                                required
                                                maxLength={100}
                                                defaultValue={analyte.name}
                                                className="h-11"
                                            />
                                            <InputError
                                                message={error('name')}
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor={field('unit')}>
                                                Unidade
                                            </Label>
                                            <Input
                                                id={field('unit')}
                                                name={`analytes[${position}][unit]`}
                                                maxLength={30}
                                                defaultValue={
                                                    analyte.unit ?? ''
                                                }
                                                className="h-11"
                                            />
                                            <InputError
                                                message={error('unit')}
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor={field('min')}>
                                                Mínimo
                                            </Label>
                                            <Input
                                                id={field('min')}
                                                name={`analytes[${position}][reference_min]`}
                                                inputMode="decimal"
                                                defaultValue={
                                                    analyte.reference_min ?? ''
                                                }
                                                className="h-11"
                                            />
                                            <InputError
                                                message={error('reference_min')}
                                            />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor={field('max')}>
                                                Máximo
                                            </Label>
                                            <Input
                                                id={field('max')}
                                                name={`analytes[${position}][reference_max]`}
                                                inputMode="decimal"
                                                defaultValue={
                                                    analyte.reference_max ?? ''
                                                }
                                                className="h-11"
                                            />
                                            <InputError
                                                message={error('reference_max')}
                                            />
                                        </div>
                                        <div className="flex items-end">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="min-h-11"
                                                onClick={() =>
                                                    setRows(
                                                        rows.filter(
                                                            (other) =>
                                                                other.key !==
                                                                analyte.key,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 aria-hidden="true" />
                                                Remover
                                            </Button>
                                        </div>
                                        <div className="grid gap-1 sm:col-span-6">
                                            <Label htmlFor={field('text')}>
                                                Referência em texto (opcional)
                                            </Label>
                                            <Input
                                                id={field('text')}
                                                name={`analytes[${position}][reference_text]`}
                                                maxLength={255}
                                                defaultValue={
                                                    analyte.reference_text ?? ''
                                                }
                                                className="h-11"
                                            />
                                            <InputError
                                                message={
                                                    error('reference_text') ??
                                                    error('id')
                                                }
                                            />
                                        </div>
                                    </div>
                                );
                            })}
                            <InputError message={errors.analytes} />
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => setRows([...rows, row()])}
                                >
                                    <Plus aria-hidden="true" />
                                    Adicionar analito
                                </Button>
                            </div>
                        </fieldset>
                    )}

                    {exam && (
                        <label className="flex min-h-11 items-center gap-3 text-sm">
                            <input type="hidden" name="active" value="0" />
                            <input
                                type="checkbox"
                                name="active"
                                value="1"
                                defaultChecked={exam.active}
                                className="size-5"
                            />
                            Ativo (exame inativo não pode ser pedido)
                        </label>
                    )}
                    <div>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11"
                        >
                            {exam ? 'Salvar exame' : 'Cadastrar exame'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
