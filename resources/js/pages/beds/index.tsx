import { Form, Head, router, usePage } from '@inertiajs/react';
import { BedDouble } from 'lucide-react';
import type { ReactNode } from 'react';
import BedController from '@/actions/App/Http/Controllers/BedController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/beds';

type Status = 'free' | 'reserved' | 'occupied' | 'cleaning' | 'blocked';

type Bed = {
    id: number;
    room: string;
    code: string;
    type: 'ward' | 'private' | 'icu';
    sex_restriction: 'female' | 'male' | null;
    isolation: boolean;
    extra: boolean;
    status: Status;
    block_reason: string | null;
};

const statusLabels: Record<Status, string> = {
    free: 'Livre',
    reserved: 'Reservado',
    occupied: 'Ocupado',
    cleaning: 'Em higienização',
    blocked: 'Bloqueado',
};

const statusClasses: Record<Status, string> = {
    free: 'border-emerald-600/30 bg-emerald-600/10 text-emerald-800 dark:text-emerald-300',
    reserved: 'border-sky-600/30 bg-sky-600/10 text-sky-800 dark:text-sky-300',
    occupied: 'border-primary/30 bg-primary/10 text-primary',
    cleaning:
        'border-amber-600/30 bg-amber-500/10 text-amber-800 dark:text-amber-300',
    blocked: 'border-border bg-muted text-muted-foreground',
};

const typeLabels = { ward: 'Enfermaria', private: 'Apartamento', icu: 'UTI' };

/** Bed map of a unit (step 4.1): state of every bed, by sector, and the actions of each role. */
export default function Beds({
    units,
    unit,
    sectors,
    counts,
    can,
}: {
    units: { id: number; name: string }[];
    unit: { id: number; name: string };
    sectors: { id: number; name: string; beds: Bed[] }[];
    counts: Record<Status, number>;
    can: { configure: boolean; manage: boolean; clean: boolean };
}) {
    const { errors } = usePage().props;
    const withBeds = sectors.filter((sector) => sector.beds.length > 0);

    return (
        <>
            <Head title="Leitos" />
            <Page>
                <PageHeader
                    eyebrow={unit.name}
                    title="Leitos"
                    description="Mapa de leitos por setor. O estado aparece em texto e em cor."
                />
                {units.length > 1 && (
                    <div className="mb-6 grid max-w-sm gap-2">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            value={unit.id}
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

                <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
                    {(Object.keys(statusLabels) as Status[]).map((status) => (
                        <div
                            key={status}
                            className={`rounded-xl border px-3 py-3 ${statusClasses[status]}`}
                        >
                            <dt className="text-xs">{statusLabels[status]}</dt>
                            <dd className="text-2xl font-semibold tabular-nums">
                                {counts[status]}
                            </dd>
                        </div>
                    ))}
                </dl>
                <InputError message={errors.bed} className="mb-4" />

                {withBeds.length === 0 ? (
                    <div className="mb-8 rounded-xl border bg-card px-5 py-12 text-center">
                        <BedDouble
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">Nenhum leito cadastrado</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Um setor passa a ser de internação quando recebe
                            leitos.
                        </p>
                    </div>
                ) : (
                    <div className="mb-8 grid gap-6">
                        {withBeds.map((sector) => (
                            <section key={sector.id} className="grid gap-3">
                                <h2 className="font-semibold">{sector.name}</h2>
                                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    {sector.beds.map((bed) => (
                                        <BedCard
                                            key={bed.id}
                                            bed={bed}
                                            can={can}
                                        />
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}

                {can.configure && sectors.length > 0 && (
                    <NewBed unitId={unit.id} sectors={sectors} />
                )}
            </Page>
        </>
    );
}

function BedCard({
    bed,
    can,
}: {
    bed: Bed;
    can: { configure: boolean; manage: boolean; clean: boolean };
}) {
    const label = `leito ${bed.code}, quarto ${bed.room}`;
    const tags = [
        typeLabels[bed.type],
        bed.sex_restriction === 'female' && 'Feminino',
        bed.sex_restriction === 'male' && 'Masculino',
        bed.isolation && 'Isolamento',
        bed.extra && 'Leito extra',
    ].filter(Boolean);

    return (
        <li className="grid gap-3 rounded-xl border bg-card p-4 text-sm">
            <div className="flex items-start justify-between gap-2">
                <span className="grid gap-0.5">
                    <span className="font-semibold">Leito {bed.code}</span>
                    <span className="text-muted-foreground">
                        Quarto {bed.room} · {tags.join(' · ')}
                    </span>
                </span>
                <span
                    className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${statusClasses[bed.status]}`}
                >
                    {statusLabels[bed.status]}
                </span>
            </div>
            {bed.block_reason && (
                <p className="text-muted-foreground">
                    Motivo: {bed.block_reason}
                </p>
            )}
            <div className="flex flex-wrap items-start gap-2">
                {can.manage && bed.status === 'free' && (
                    <>
                        <Action
                            form={BedController.sendToCleaning.form(bed.id)}
                            label={`Enviar para higienização: ${label}`}
                        >
                            Higienização
                        </Action>
                        <details>
                            <summary
                                aria-label={`Bloquear ${label}`}
                                className="inline-flex min-h-11 cursor-pointer items-center rounded-md px-3 text-muted-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                Bloquear
                            </summary>
                            <Form
                                {...BedController.block.form(bed.id)}
                                options={{ preserveScroll: true }}
                                className="mt-2 flex flex-wrap items-end gap-2"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor={`reason-${bed.id}`}>
                                                Motivo do bloqueio
                                            </Label>
                                            <Input
                                                id={`reason-${bed.id}`}
                                                name="reason"
                                                required
                                                maxLength={255}
                                                className="h-11"
                                            />
                                            <InputError
                                                message={errors.reason}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            className="min-h-11"
                                        >
                                            Confirmar bloqueio
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </details>
                    </>
                )}
                {can.manage && bed.status === 'blocked' && (
                    <Action
                        form={BedController.unblock.form(bed.id)}
                        label={`Liberar ${label}`}
                    >
                        Liberar
                    </Action>
                )}
                {can.clean && bed.status === 'cleaning' && (
                    <Action
                        form={BedController.clean.form(bed.id)}
                        label={`Marcar como pronto: ${label}`}
                    >
                        Pronto
                    </Action>
                )}
                {can.configure &&
                    (bed.status === 'free' || bed.status === 'blocked') && (
                        <Action
                            form={BedController.deactivate.form(bed.id)}
                            label={`Desativar ${label}`}
                            quiet
                        >
                            Desativar
                        </Action>
                    )}
            </div>
        </li>
    );
}

function Action({
    form,
    label,
    quiet,
    children,
}: {
    form: { action: string; method: 'post' };
    label: string;
    quiet?: boolean;
    children: ReactNode;
}) {
    return (
        <Form {...form} options={{ preserveScroll: true }}>
            {({ processing }) => (
                <Button
                    type="submit"
                    variant={quiet ? 'ghost' : 'outline'}
                    disabled={processing}
                    aria-label={label}
                    className="min-h-11"
                >
                    {children}
                </Button>
            )}
        </Form>
    );
}

function NewBed({
    unitId,
    sectors,
}: {
    unitId: number;
    sectors: { id: number; name: string }[];
}) {
    return (
        <Form
            {...BedController.store.form(unitId)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-4 rounded-xl border bg-card p-5 sm:p-6"
        >
            {({ processing, errors }) => (
                <>
                    <h2 className="font-semibold">Cadastrar leito</h2>
                    <div className="grid gap-4 sm:grid-cols-4">
                        <Field
                            id="sector_id"
                            label="Setor"
                            error={errors.sector_id}
                        >
                            <select
                                id="sector_id"
                                name="sector_id"
                                required
                                defaultValue=""
                                className={selectClassName}
                            >
                                <option value="" disabled>
                                    Selecione
                                </option>
                                {sectors.map((sector) => (
                                    <option key={sector.id} value={sector.id}>
                                        {sector.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field id="room" label="Quarto" error={errors.room}>
                            <Input
                                id="room"
                                name="room"
                                required
                                maxLength={50}
                                className="h-11"
                            />
                        </Field>
                        <Field id="code" label="Leito" error={errors.code}>
                            <Input
                                id="code"
                                name="code"
                                required
                                maxLength={50}
                                className="h-11"
                            />
                        </Field>
                        <Field id="type" label="Tipo" error={errors.type}>
                            <select
                                id="type"
                                name="type"
                                required
                                defaultValue="ward"
                                className={selectClassName}
                            >
                                {Object.entries(typeLabels).map(
                                    ([value, text]) => (
                                        <option key={value} value={value}>
                                            {text}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                    </div>
                    <div className="flex flex-wrap items-end gap-6">
                        <Field
                            id="sex_restriction"
                            label="Restrição de sexo"
                            error={errors.sex_restriction}
                        >
                            <select
                                id="sex_restriction"
                                name="sex_restriction"
                                defaultValue=""
                                className={selectClassName}
                            >
                                <option value="">Nenhuma</option>
                                <option value="female">Feminino</option>
                                <option value="male">Masculino</option>
                            </select>
                        </Field>
                        <Check name="isolation" label="Isolamento" />
                        <Check
                            name="extra"
                            label="Leito extra (superlotação)"
                        />
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Cadastrar leito
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function Check({ name, label }: { name: string; label: string }) {
    return (
        <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="hidden" name={name} value="0" />
            <input
                type="checkbox"
                name={name}
                value="1"
                className="size-4 accent-primary"
            />
            {label}
        </label>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

Beds.layout = {
    breadcrumbs: [{ title: 'Leitos', href: index() }],
};
