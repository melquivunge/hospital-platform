import { Form, Head } from '@inertiajs/react';
import { BriefcaseMedical, Plus } from 'lucide-react';
import JobFunctionController from '@/actions/App/Http/Controllers/JobFunctionController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/job-functions';

type JobFunction = { id: number; name: string; attends_patients: boolean };

/** Step 4.9: only functions that attend patients call from the emergency queue (it opens the record). */
function AttendsCheck({ checked }: { checked: boolean }) {
    return (
        <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="hidden" name="attends_patients" value="0" />
            <input
                type="checkbox"
                name="attends_patients"
                value="1"
                defaultChecked={checked}
                className="size-4 accent-primary"
            />
            Atende pacientes (chama da fila da urgência)
        </label>
    );
}

export default function JobFunctionsIndex({
    functions,
}: {
    functions: JobFunction[];
}) {
    return (
        <>
            <Head title="Funções" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Equipe"
                    title="Funções"
                    description="Funções da rede usadas nas habilitações e nas escalas, como médico plantonista ou enfermeiro."
                />

                <section
                    aria-labelledby="functions-heading"
                    className="overflow-hidden rounded-lg border bg-card"
                >
                    <header className="flex items-center justify-between gap-3 border-b px-5 py-4">
                        <h2
                            id="functions-heading"
                            className="flex items-center gap-2 font-semibold tracking-tight"
                        >
                            <BriefcaseMedical
                                className="size-4 text-primary"
                                aria-hidden="true"
                            />
                            Funções cadastradas
                        </h2>
                        <span className="text-sm text-muted-foreground tabular-nums">
                            {functions.length}
                        </span>
                    </header>

                    {functions.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            Nenhuma função cadastrada. Adicione a primeira
                            abaixo.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {functions.map((jobFunction) => (
                                <li key={jobFunction.id} className="px-5 py-3">
                                    <RenameForm jobFunction={jobFunction} />
                                </li>
                            ))}
                        </ul>
                    )}

                    <Form
                        {...JobFunctionController.store.form()}
                        errorBag="newFunction"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-2 border-t bg-muted/40 px-5 py-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <Label htmlFor="new-function">
                                    Nova função
                                </Label>
                                <div className="flex flex-wrap gap-3">
                                    <Input
                                        id="new-function"
                                        name="name"
                                        required
                                        maxLength={255}
                                        placeholder="Ex.: Médico plantonista"
                                        aria-invalid={!!errors.name}
                                        aria-describedby={
                                            errors.name
                                                ? 'new-function-error'
                                                : undefined
                                        }
                                        className="h-11 min-w-0 flex-1 bg-card"
                                    />
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <Plus aria-hidden="true" />
                                        )}
                                        Adicionar
                                    </Button>
                                </div>
                                <AttendsCheck checked />
                                <InputError
                                    id="new-function-error"
                                    message={errors.name}
                                />
                            </>
                        )}
                    </Form>
                </section>
            </Page>
        </>
    );
}

function RenameForm({ jobFunction }: { jobFunction: JobFunction }) {
    const inputId = `function-${jobFunction.id}`;

    return (
        <Form
            {...JobFunctionController.update.form(jobFunction.id)}
            errorBag={inputId}
            options={{ preserveScroll: true }}
            className="grid gap-2"
        >
            {({ processing, errors }) => (
                <>
                    <Label htmlFor={inputId} className="sr-only">
                        Nome da função
                    </Label>
                    <div className="flex flex-wrap gap-3">
                        <Input
                            id={inputId}
                            name="name"
                            required
                            maxLength={255}
                            defaultValue={jobFunction.name}
                            aria-invalid={!!errors.name}
                            aria-describedby={
                                errors.name ? `${inputId}-error` : undefined
                            }
                            className="h-11 min-w-0 flex-1"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            aria-label={`Salvar ${jobFunction.name}`}
                            className="min-h-11"
                        >
                            {processing && <Spinner />}
                            Salvar
                        </Button>
                    </div>
                    <AttendsCheck checked={jobFunction.attends_patients} />
                    <InputError id={`${inputId}-error`} message={errors.name} />
                </>
            )}
        </Form>
    );
}

JobFunctionsIndex.layout = {
    breadcrumbs: [{ title: 'Funções', href: index() }],
};
