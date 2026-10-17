import { Form, Head, Link } from '@inertiajs/react';
import UnitController from '@/actions/App/Http/Controllers/UnitController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, show } from '@/routes/units';

type Unit = {
    id: number;
    name: string;
    uf: string | null;
    timezone: string | null;
};

const selectClassName =
    'h-11 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-invalid:border-destructive';

export default function UnitForm({
    unit,
    ufs,
    timezones,
}: {
    unit: Unit | null;
    ufs: string[];
    timezones: string[];
}) {
    const title = unit ? 'Editar unidade' : 'Nova unidade';

    return (
        <>
            <Head title={title} />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Unidades"
                    title={title}
                    description="UF e fuso horário são necessários para publicar escalas e podem ser informados depois."
                />

                <Form
                    {...(unit
                        ? UnitController.update.form(unit.id)
                        : UnitController.store.form())}
                    className="overflow-hidden rounded-lg border bg-card"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-6 p-5 sm:p-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">
                                        Nome da unidade
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        maxLength={255}
                                        defaultValue={unit?.name}
                                        placeholder="Ex.: Hospital Horizonte"
                                        aria-invalid={!!errors.name}
                                        aria-describedby={
                                            errors.name
                                                ? 'name-error'
                                                : undefined
                                        }
                                        className="h-11"
                                    />
                                    <InputError
                                        id="name-error"
                                        message={errors.name}
                                    />
                                </div>

                                <div className="grid gap-6 sm:grid-cols-[8rem_1fr]">
                                    <div className="grid gap-2">
                                        <Label htmlFor="uf">UF</Label>
                                        <select
                                            id="uf"
                                            name="uf"
                                            defaultValue={unit?.uf ?? ''}
                                            aria-invalid={!!errors.uf}
                                            aria-describedby={
                                                errors.uf
                                                    ? 'uf-error'
                                                    : undefined
                                            }
                                            className={selectClassName}
                                        >
                                            <option value="">—</option>
                                            {ufs.map((uf) => (
                                                <option key={uf} value={uf}>
                                                    {uf}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            id="uf-error"
                                            message={errors.uf}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="timezone">
                                            Fuso horário
                                        </Label>
                                        <select
                                            id="timezone"
                                            name="timezone"
                                            defaultValue={unit?.timezone ?? ''}
                                            aria-invalid={!!errors.timezone}
                                            aria-describedby={
                                                errors.timezone
                                                    ? 'timezone-error'
                                                    : undefined
                                            }
                                            className={selectClassName}
                                        >
                                            <option value="">
                                                Não informado
                                            </option>
                                            {timezones.map((timezone) => (
                                                <option
                                                    key={timezone}
                                                    value={timezone}
                                                >
                                                    {timezone.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            id="timezone-error"
                                            message={errors.timezone}
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="flex flex-wrap justify-end gap-3 border-t bg-muted/40 px-5 py-4 sm:px-6">
                                <Button
                                    variant="ghost"
                                    asChild
                                    className="min-h-11"
                                >
                                    <Link href={unit ? show(unit.id) : index()}>
                                        Cancelar
                                    </Link>
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    {processing && <Spinner />}
                                    {unit
                                        ? 'Salvar alterações'
                                        : 'Cadastrar unidade'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

UnitForm.layout = {
    breadcrumbs: [{ title: 'Unidades', href: index() }],
};
