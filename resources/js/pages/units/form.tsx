import { Form, Head, Link } from '@inertiajs/react';
import UnitController from '@/actions/App/Http/Controllers/UnitController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
    'h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-invalid:border-destructive';

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
            <div className="max-w-xl p-4 md:p-8">
                <Heading
                    level={1}
                    title={title}
                    description="UF e fuso horário são obrigatórios para publicar escalas; podem ser informados depois."
                />

                <Form
                    {...(unit
                        ? UnitController.update.form(unit.id)
                        : UnitController.store.form())}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nome</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    required
                                    maxLength={255}
                                    defaultValue={unit?.name}
                                    aria-invalid={!!errors.name}
                                    aria-describedby={
                                        errors.name ? 'name-error' : undefined
                                    }
                                    className="h-11"
                                />
                                <InputError
                                    id="name-error"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="uf">UF</Label>
                                <select
                                    id="uf"
                                    name="uf"
                                    defaultValue={unit?.uf ?? ''}
                                    aria-invalid={!!errors.uf}
                                    aria-describedby={
                                        errors.uf ? 'uf-error' : undefined
                                    }
                                    className={selectClassName}
                                >
                                    <option value="">Não informada</option>
                                    {ufs.map((uf) => (
                                        <option key={uf} value={uf}>
                                            {uf}
                                        </option>
                                    ))}
                                </select>
                                <InputError id="uf-error" message={errors.uf} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="timezone">Fuso horário</Label>
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
                                    <option value="">Não informado</option>
                                    {timezones.map((timezone) => (
                                        <option key={timezone} value={timezone}>
                                            {timezone.replaceAll('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    id="timezone-error"
                                    message={errors.timezone}
                                />
                            </div>

                            <div className="flex flex-wrap gap-3">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    {processing && <Spinner />}
                                    Salvar
                                </Button>
                                <Button
                                    variant="ghost"
                                    asChild
                                    className="min-h-11"
                                >
                                    <Link href={unit ? show(unit.id) : index()}>
                                        Cancelar
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

UnitForm.layout = {
    breadcrumbs: [{ title: 'Unidades', href: index() }],
};
