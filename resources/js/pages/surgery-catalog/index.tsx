import { Form, Head, router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SurgeryCatalogController from '@/actions/App/Http/Controllers/SurgeryCatalogController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/surgery-catalog';

type Procedure = {
    id: number;
    name: string;
    code: string | null;
    duration_minutes: number;
    size: string;
    active: boolean;
};

type Room = { id: number; name: string; active: boolean };

type UnitRooms = { id: number; name: string; rooms: Room[] };

type Material = {
    id: number;
    name: string;
    code: string | null;
    stock_unit: string;
    active: boolean;
};

/**
 * The network surgical procedures and the operating rooms of each unit (step 5b.1a, delivery 5b), and the network
 * materials (step 5b.6a), kept by the network admin. Deactivated, never deleted. No patient data.
 */
export default function SurgeryCatalogIndex({
    q,
    total,
    limit,
    procedures,
    sizes,
    units,
    m,
    materialTotal,
    materials,
    stockUnits,
}: {
    q: string;
    total: number;
    limit: number;
    procedures: Procedure[];
    sizes: Record<string, string>;
    units: UnitRooms[];
    m: string;
    materialTotal: number;
    materials: Material[];
    stockUnits: string[];
}) {
    // Each list keeps the other's search in the address.
    const searchBy =
        (field: 'q' | 'm') => (event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            const value = new FormData(event.currentTarget).get(field);
            const query = {
                q,
                m,
                [field]: typeof value === 'string' ? value : '',
            };
            router.get(
                index({
                    query: Object.fromEntries(
                        Object.entries(query).filter(([, text]) => text),
                    ),
                }).url,
                {},
                { preserveState: true, preserveScroll: true },
            );
        };

    return (
        <>
            <Head title="Procedimentos, salas e materiais" />
            <Page>
                <PageHeader
                    title="Procedimentos, salas e materiais"
                    description="Procedimentos cirúrgicos e materiais da rede e salas do centro cirúrgico de cada unidade. São desativados, nunca apagados."
                />

                <section className="mb-8 grid gap-3">
                    <h2 className="text-lg font-semibold">Salas cirúrgicas</h2>
                    {units.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Cadastre uma unidade antes das salas.
                        </p>
                    ) : (
                        <>
                            <RoomCreate units={units} />
                            <ul className="grid gap-3">
                                {units.map((unit) => (
                                    <li
                                        key={unit.id}
                                        className="rounded-xl border bg-card px-5 py-4"
                                    >
                                        <h3 className="font-medium">
                                            {unit.name}
                                        </h3>
                                        {unit.rooms.length === 0 ? (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Nenhuma sala.
                                            </p>
                                        ) : (
                                            <ul className="mt-2 grid gap-2">
                                                {unit.rooms.map((room) => (
                                                    <RoomItem
                                                        key={room.id}
                                                        room={room}
                                                    />
                                                ))}
                                            </ul>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </section>

                <section className="mb-8 grid gap-3">
                    <h2 className="text-lg font-semibold">Novo procedimento</h2>
                    <ProcedureForm sizes={sizes} />
                </section>

                <form
                    onSubmit={searchBy('q')}
                    role="search"
                    aria-label="Buscar procedimentos"
                    className="mb-4 flex flex-wrap items-end gap-3"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="q">Buscar procedimento pelo nome</Label>
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
                    {total} procedimento(s) no catálogo
                    {procedures.length >= limit &&
                        `; mostrando os primeiros ${limit}`}
                    .
                </p>

                {procedures.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Nenhum procedimento encontrado.
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {procedures.map((procedure) => (
                            <li
                                key={procedure.id}
                                className="rounded-xl border bg-card px-5 py-4"
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <h3 className="font-medium">
                                        {procedure.name}
                                    </h3>
                                    <span className="text-sm text-muted-foreground">
                                        Porte {sizes[procedure.size]} ·{' '}
                                        {procedure.duration_minutes} min
                                        {procedure.code &&
                                            ` · ${procedure.code}`}
                                        {!procedure.active && ' · inativo'}
                                    </span>
                                </div>
                                <details className="mt-2">
                                    <summary
                                        aria-label={`Alterar ${procedure.name}`}
                                        className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary"
                                    >
                                        Alterar
                                    </summary>
                                    <ProcedureForm
                                        sizes={sizes}
                                        procedure={procedure}
                                    />
                                </details>
                            </li>
                        ))}
                    </ul>
                )}

                <section className="mt-10 grid gap-3">
                    <h2 className="text-lg font-semibold">Materiais</h2>
                    <p className="text-sm text-muted-foreground">
                        O estoque e o consumo na cirurgia contam cada material
                        na sua unidade de estoque.
                    </p>
                    <MaterialForm stockUnits={stockUnits} />
                    <form
                        onSubmit={searchBy('m')}
                        role="search"
                        aria-label="Buscar materiais"
                        className="flex flex-wrap items-end gap-3"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="m">Buscar material pelo nome</Label>
                            <Input
                                id="m"
                                name="m"
                                defaultValue={m}
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
                    <p className="text-sm text-muted-foreground">
                        {materialTotal} material(is) no catálogo
                        {materials.length >= limit &&
                            `; mostrando os primeiros ${limit}`}
                        .
                    </p>
                    {materials.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                            Nenhum material encontrado.
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {materials.map((material) => (
                                <li
                                    key={material.id}
                                    className="rounded-xl border bg-card px-5 py-4"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <h3 className="font-medium">
                                            {material.name}
                                        </h3>
                                        <span className="text-sm text-muted-foreground">
                                            {material.stock_unit}
                                            {material.code &&
                                                ` · ${material.code}`}
                                            {!material.active && ' · inativo'}
                                        </span>
                                    </div>
                                    <details className="mt-2">
                                        <summary
                                            aria-label={`Alterar ${material.name}`}
                                            className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary"
                                        >
                                            Alterar
                                        </summary>
                                        <MaterialForm
                                            stockUnits={stockUnits}
                                            material={material}
                                        />
                                    </details>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}

function ProcedureForm({
    sizes,
    procedure,
}: {
    sizes: Record<string, string>;
    procedure?: Procedure;
}) {
    const prefix = procedure ? `procedure-${procedure.id}` : 'new-procedure';
    const action = procedure
        ? SurgeryCatalogController.updateProcedure.form(procedure.id)
        : SurgeryCatalogController.storeProcedure.form();

    return (
        <Form
            {...action}
            errorBag={procedure ? prefix : 'newProcedure'}
            options={{ preserveScroll: true }}
            resetOnSuccess={!procedure}
            className="mt-2 grid gap-4 rounded-xl border bg-card p-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor={`${prefix}-name`}>Nome</Label>
                            <Input
                                id={`${prefix}-name`}
                                name="name"
                                required
                                maxLength={255}
                                defaultValue={procedure?.name}
                                className="h-11"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${prefix}-duration`}>
                                Duração prevista (minutos)
                            </Label>
                            <Input
                                id={`${prefix}-duration`}
                                name="duration_minutes"
                                type="number"
                                min={5}
                                max={1440}
                                required
                                defaultValue={procedure?.duration_minutes}
                                className="h-11"
                            />
                            <InputError message={errors.duration_minutes} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${prefix}-size`}>Porte</Label>
                            <select
                                id={`${prefix}-size`}
                                name="size"
                                defaultValue={procedure?.size ?? 'medium'}
                                className={selectClassName}
                            >
                                {Object.entries(sizes).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.size} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${prefix}-code`}>
                                Código TUSS/SIGTAP (opcional)
                            </Label>
                            <Input
                                id={`${prefix}-code`}
                                name="code"
                                maxLength={20}
                                defaultValue={procedure?.code ?? ''}
                                className="h-11"
                            />
                            <InputError message={errors.code} />
                        </div>
                    </div>
                    {procedure && (
                        <ActiveToggle
                            active={procedure.active}
                            label="Ativo (procedimento inativo não pode ser pedido)"
                        />
                    )}
                    <div>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11"
                        >
                            {procedure
                                ? 'Salvar procedimento'
                                : 'Cadastrar procedimento'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function MaterialForm({
    stockUnits,
    material,
}: {
    stockUnits: string[];
    material?: Material;
}) {
    const prefix = material ? `material-${material.id}` : 'new-material';
    const action = material
        ? SurgeryCatalogController.updateMaterial.form(material.id)
        : SurgeryCatalogController.storeMaterial.form();

    return (
        <Form
            {...action}
            errorBag={material ? prefix : 'newMaterial'}
            options={{ preserveScroll: true }}
            resetOnSuccess={!material}
            className="mt-2 grid gap-4 rounded-xl border bg-card p-5"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor={`${prefix}-name`}>Nome</Label>
                            <Input
                                id={`${prefix}-name`}
                                name="name"
                                required
                                maxLength={255}
                                defaultValue={material?.name}
                                className="h-11"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${prefix}-unit`}>
                                Unidade de estoque
                            </Label>
                            <select
                                id={`${prefix}-unit`}
                                name="stock_unit"
                                defaultValue={material?.stock_unit ?? 'unidade'}
                                className={selectClassName}
                            >
                                {stockUnits.map((unit) => (
                                    <option key={unit} value={unit}>
                                        {unit}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.stock_unit} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`${prefix}-code`}>
                                Código (opcional)
                            </Label>
                            <Input
                                id={`${prefix}-code`}
                                name="code"
                                maxLength={30}
                                defaultValue={material?.code ?? ''}
                                className="h-11"
                            />
                            <InputError message={errors.code} />
                        </div>
                    </div>
                    {material && (
                        <ActiveToggle
                            active={material.active}
                            label="Ativo (material inativo não recebe entrada nem consumo)"
                        />
                    )}
                    <div>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11"
                        >
                            {material
                                ? 'Salvar material'
                                : 'Cadastrar material'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function RoomCreate({ units }: { units: UnitRooms[] }) {
    return (
        <Form
            {...SurgeryCatalogController.storeRoom.form()}
            errorBag="newRoom"
            options={{ preserveScroll: true }}
            resetOnSuccess={['name']}
            className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="new-room-unit">Unidade</Label>
                        <select
                            id="new-room-unit"
                            name="unit_id"
                            className={selectClassName}
                        >
                            {units.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.unit_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="new-room-name">Nome da sala</Label>
                        <Input
                            id="new-room-name"
                            name="name"
                            required
                            maxLength={100}
                            className="h-11"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <Button
                        type="submit"
                        disabled={processing}
                        className="min-h-11"
                    >
                        Cadastrar sala
                    </Button>
                </>
            )}
        </Form>
    );
}

function RoomItem({ room }: { room: Room }) {
    return (
        <li className="rounded-lg border px-4 py-2">
            <span className="text-sm">
                {room.name}
                {!room.active && (
                    <span className="text-muted-foreground"> · inativa</span>
                )}
            </span>
            <details>
                <summary
                    aria-label={`Alterar ${room.name}`}
                    className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary"
                >
                    Alterar
                </summary>
                <Form
                    {...SurgeryCatalogController.updateRoom.form(room.id)}
                    errorBag={`room-${room.id}`}
                    options={{ preserveScroll: true }}
                    className="grid gap-3 pb-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={`room-${room.id}-name`}>
                                    Nome da sala
                                </Label>
                                <Input
                                    id={`room-${room.id}-name`}
                                    name="name"
                                    required
                                    maxLength={100}
                                    defaultValue={room.name}
                                    className="h-11"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <ActiveToggle
                                active={room.active}
                                label="Ativa (sala inativa não recebe agendamento)"
                            />
                            <div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    Salvar sala
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </details>
        </li>
    );
}

function ActiveToggle({ active, label }: { active: boolean; label: string }) {
    return (
        <label className="flex min-h-11 items-center gap-3 text-sm">
            <input type="hidden" name="active" value="0" />
            <input
                type="checkbox"
                name="active"
                value="1"
                defaultChecked={active}
                className="size-5"
            />
            {label}
        </label>
    );
}
