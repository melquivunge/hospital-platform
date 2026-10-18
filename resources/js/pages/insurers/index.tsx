import { Form, Head } from '@inertiajs/react';
import { Landmark, Plus } from 'lucide-react';
import InsurerController from '@/actions/App/Http/Controllers/InsurerController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/insurers';

type Insurer = {
    id: number;
    name: string;
    ans_code: string | null;
    active: boolean;
};

/** Network insurers (step 3.5): chosen by the reception when a visit is through an insurer. */
export default function InsurersIndex({ insurers }: { insurers: Insurer[] }) {
    return (
        <>
            <Head title="Convênios" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Rede"
                    title="Convênios"
                    description="Convênios aceitos pela rede. A recepção escolhe um deles ao confirmar a chegada de um atendimento por convênio. Desativar esconde o convênio só das novas chegadas."
                />

                <section
                    aria-labelledby="insurers-heading"
                    className="overflow-hidden rounded-lg border bg-card"
                >
                    <header className="flex items-center justify-between gap-3 border-b px-5 py-4">
                        <h2
                            id="insurers-heading"
                            className="flex items-center gap-2 font-semibold tracking-tight"
                        >
                            <Landmark
                                className="size-4 text-primary"
                                aria-hidden="true"
                            />
                            Convênios cadastrados
                        </h2>
                        <span className="text-sm text-muted-foreground tabular-nums">
                            {insurers.length}
                        </span>
                    </header>

                    {insurers.length === 0 ? (
                        <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                            Nenhum convênio cadastrado. Adicione o primeiro
                            abaixo.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {insurers.map((insurer) => (
                                <li key={insurer.id} className="px-5 py-4">
                                    <EditForm insurer={insurer} />
                                </li>
                            ))}
                        </ul>
                    )}

                    <Form
                        {...InsurerController.store.form()}
                        errorBag="newInsurer"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-3 border-t bg-muted/40 px-5 py-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <p className="text-sm font-medium">
                                    Novo convênio
                                </p>
                                <div className="grid gap-3 sm:grid-cols-[1fr_10rem]">
                                    <div className="grid content-start gap-2">
                                        <Label htmlFor="new-name">Nome</Label>
                                        <Input
                                            id="new-name"
                                            name="name"
                                            required
                                            maxLength={255}
                                            className="h-11 bg-card"
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid content-start gap-2">
                                        <Label htmlFor="new-ans">
                                            Registro ANS (opcional)
                                        </Label>
                                        <Input
                                            id="new-ans"
                                            name="ans_code"
                                            inputMode="numeric"
                                            pattern="\d{6}"
                                            maxLength={6}
                                            className="h-11 bg-card"
                                        />
                                        <InputError message={errors.ans_code} />
                                    </div>
                                </div>
                                <div className="flex justify-end">
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
                            </>
                        )}
                    </Form>
                </section>
            </Page>
        </>
    );
}

function EditForm({ insurer }: { insurer: Insurer }) {
    const id = `insurer-${insurer.id}`;

    return (
        <Form
            {...InsurerController.update.form(insurer.id)}
            errorBag={id}
            options={{ preserveScroll: true }}
            className="grid gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-3 sm:grid-cols-[1fr_10rem]">
                        <div className="grid content-start gap-2">
                            <Label htmlFor={`${id}-name`}>Nome</Label>
                            <Input
                                id={`${id}-name`}
                                name="name"
                                required
                                maxLength={255}
                                defaultValue={insurer.name}
                                className="h-11"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor={`${id}-ans`}>Registro ANS</Label>
                            <Input
                                id={`${id}-ans`}
                                name="ans_code"
                                inputMode="numeric"
                                pattern="\d{6}"
                                maxLength={6}
                                defaultValue={insurer.ans_code ?? ''}
                                className="h-11"
                            />
                            <InputError message={errors.ans_code} />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <label className="flex min-h-11 items-center gap-2 text-sm">
                            <input type="hidden" name="active" value="0" />
                            <input
                                type="checkbox"
                                name="active"
                                value="1"
                                defaultChecked={insurer.active}
                                className="size-4 accent-primary"
                            />
                            Ativo
                        </label>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            aria-label={`Salvar ${insurer.name}`}
                            className="min-h-11"
                        >
                            Salvar
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

InsurersIndex.layout = {
    breadcrumbs: [{ title: 'Convênios', href: index() }],
};
