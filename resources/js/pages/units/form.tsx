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
    cnes?: string | null;
    cnpj?: string | null;
    sus_acronym?: string | null;
    sus_manager_name?: string | null;
    sus_manager_level?: string | null;
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

                                <div className="grid gap-2 sm:max-w-xs">
                                    <Label htmlFor="cnes">CNES</Label>
                                    <Input
                                        id="cnes"
                                        name="cnes"
                                        inputMode="numeric"
                                        maxLength={7}
                                        pattern="[0-9]{7}"
                                        defaultValue={unit?.cnes ?? ''}
                                        aria-invalid={!!errors.cnes}
                                        aria-describedby="cnes-hint"
                                        className="h-11"
                                    />
                                    <p
                                        id="cnes-hint"
                                        className="text-sm text-muted-foreground"
                                    >
                                        7 dígitos. Vai nas guias dos convênios
                                        (TISS).
                                    </p>
                                    <InputError message={errors.cnes} />
                                </div>

                                <fieldset className="grid gap-4 border-t pt-5">
                                    <legend className="font-medium">
                                        Faturamento SUS (BPA)
                                    </legend>
                                    <p className="text-sm text-muted-foreground">
                                        Vão no cabeçalho do arquivo BPA da
                                        unidade. Gestor e esfera são informados
                                        juntos.
                                    </p>
                                    <div className="grid gap-4 sm:grid-cols-[1fr_10rem]">
                                        <div className="grid gap-2">
                                            <Label htmlFor="cnpj">
                                                CNPJ do prestador
                                            </Label>
                                            <Input
                                                id="cnpj"
                                                name="cnpj"
                                                inputMode="numeric"
                                                maxLength={18}
                                                placeholder="00.000.000/0000-00"
                                                defaultValue={unit?.cnpj ?? ''}
                                                aria-invalid={!!errors.cnpj}
                                                className="h-11"
                                            />
                                            <InputError message={errors.cnpj} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="sus_acronym">
                                                Sigla
                                            </Label>
                                            <Input
                                                id="sus_acronym"
                                                name="sus_acronym"
                                                maxLength={6}
                                                defaultValue={
                                                    unit?.sus_acronym ?? ''
                                                }
                                                aria-invalid={
                                                    !!errors.sus_acronym
                                                }
                                                className="h-11 uppercase"
                                            />
                                            <InputError
                                                message={errors.sus_acronym}
                                            />
                                        </div>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
                                        <div className="grid gap-2">
                                            <Label htmlFor="sus_manager_name">
                                                Gestor de destino
                                            </Label>
                                            <Input
                                                id="sus_manager_name"
                                                name="sus_manager_name"
                                                maxLength={40}
                                                placeholder="Ex.: Secretaria Municipal de Saúde"
                                                defaultValue={
                                                    unit?.sus_manager_name ?? ''
                                                }
                                                aria-invalid={
                                                    !!errors.sus_manager_name
                                                }
                                                className="h-11"
                                            />
                                            <InputError
                                                message={
                                                    errors.sus_manager_name
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="sus_manager_level">
                                                Esfera
                                            </Label>
                                            <select
                                                id="sus_manager_level"
                                                name="sus_manager_level"
                                                defaultValue={
                                                    unit?.sus_manager_level ??
                                                    ''
                                                }
                                                aria-invalid={
                                                    !!errors.sus_manager_level
                                                }
                                                className={selectClassName}
                                            >
                                                <option value="">
                                                    Selecione
                                                </option>
                                                <option value="M">
                                                    Municipal
                                                </option>
                                                <option value="E">
                                                    Estadual
                                                </option>
                                            </select>
                                            <InputError
                                                message={
                                                    errors.sus_manager_level
                                                }
                                            />
                                        </div>
                                    </div>
                                </fieldset>
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
