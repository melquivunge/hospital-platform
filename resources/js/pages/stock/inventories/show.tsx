import { Form, Head, Link } from '@inertiajs/react';
import StockInventoryController from '@/actions/App/Http/Controllers/StockInventoryController';
import InputError from '@/components/input-error';
import {
    StockItemSelect,
    type StockCatalogOption,
} from '@/components/stock-item-select';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/stock/inventories';

type Line = {
    id: number;
    medication: string;
    stockUnit: string;
    lot: string;
    expiresOn: string;
    found: boolean;
    counted: number | null;
    countedAt: string | null;
    expected: number | null;
    difference: number | null;
};

type Inventory = {
    id: number;
    unit: string | null;
    status: string;
    openedBy: string | null;
    openedAt: string;
    submittedBy: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    refusalReason: string | null;
};

/**
 * One inventory (step 4c.3b, ADR-0007): a blind count lot by lot for the pharmacy; the balance at the count and
 * the difference for the unit manager, who approves or refuses. No patient data.
 */
export default function InventoryShow({
    inventory,
    statuses,
    lines,
    canCount,
    canDecide,
    catalog,
}: {
    inventory: Inventory;
    statuses: Record<string, string>;
    lines: Line[];
    canCount: boolean;
    canDecide: boolean;
    catalog: StockCatalogOption[];
}) {
    const pending = lines.filter((line) => line.counted === null).length;

    return (
        <>
            <Head title={`Inventário ${inventory.id}`} />
            <Page>
                <PageHeader
                    eyebrow={inventory.unit ?? undefined}
                    title={`Inventário ${inventory.id}`}
                    description={`${statuses[inventory.status]} · aberto por ${inventory.openedBy} em ${inventory.openedAt}`}
                />
                <p className="mb-6 text-sm">
                    <Link
                        href={index()}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Voltar aos inventários
                    </Link>
                </p>
                {inventory.status === 'refused' && (
                    <p className="mb-6 rounded-xl border border-destructive/40 bg-card px-5 py-4 text-sm">
                        Recusado por {inventory.decidedBy} em{' '}
                        {inventory.decidedAt}: {inventory.refusalReason}
                    </p>
                )}
                {inventory.status === 'approved' && (
                    <p className="mb-6 rounded-xl border bg-card px-5 py-4 text-sm">
                        Aprovado por {inventory.decidedBy} em{' '}
                        {inventory.decidedAt}. Os lotes com diferença foram
                        ajustados.
                    </p>
                )}
                {canCount && (
                    <p className="mb-4 text-sm text-muted-foreground">
                        Conte o que está na prateleira, sem olhar o sistema.
                        Lote não encontrado conta 0. A dispensação continua
                        durante a contagem.
                    </p>
                )}

                <ul className="grid gap-3">
                    {lines.map((line) => (
                        <li
                            key={line.id}
                            className="rounded-xl border bg-card px-5 py-4"
                        >
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <h2 className="font-medium">
                                    {line.medication}
                                </h2>
                                {line.found && (
                                    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">
                                        Encontrado sem cadastro
                                    </span>
                                )}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Lote {line.lot} · validade {line.expiresOn}
                            </p>
                            {canCount ? (
                                <CountForm
                                    inventoryId={inventory.id}
                                    line={line}
                                />
                            ) : (
                                <p className="mt-2 text-sm">
                                    Contado:{' '}
                                    <span className="font-semibold">
                                        {line.counted ?? '—'}
                                    </span>{' '}
                                    {line.stockUnit}
                                    {line.expected !== null &&
                                        ` · sistema na contagem: ${line.expected}`}
                                    {line.difference !== null &&
                                        line.difference !== 0 && (
                                            <span
                                                className={`ml-2 inline-block rounded-full px-2 py-0.5 text-xs font-medium ${line.difference < 0 ? 'bg-destructive text-white' : 'bg-muted'}`}
                                            >
                                                {line.difference > 0
                                                    ? `+${line.difference}`
                                                    : `−${-line.difference}`}
                                            </span>
                                        )}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>

                {canCount && (
                    <div className="mt-8 grid gap-8 lg:grid-cols-2">
                        <FoundForm
                            inventoryId={inventory.id}
                            catalog={catalog}
                        />
                        <section className="grid content-start gap-3">
                            <h2 className="text-lg font-semibold">
                                Enviar para aprovação
                            </h2>
                            <Form
                                {...StockInventoryController.submit.form(
                                    inventory.id,
                                )}
                                errorBag="submit"
                                options={{ preserveScroll: true }}
                                className="grid gap-3 rounded-xl border bg-card p-5"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <p className="text-sm text-muted-foreground">
                                            {pending === 0
                                                ? 'Todos os lotes contados. O gestor da unidade, outra pessoa, aprova ou recusa o ajuste.'
                                                : `Faltam ${pending} lote(s) para contar.`}
                                        </p>
                                        <InputError
                                            message={
                                                errors.lines ?? errors.status
                                            }
                                        />
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={
                                                    processing || pending > 0
                                                }
                                                className="min-h-11"
                                            >
                                                Enviar para aprovação
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </section>
                    </div>
                )}

                {canDecide && <DecideForms inventoryId={inventory.id} />}
            </Page>
        </>
    );
}

function CountForm({ inventoryId, line }: { inventoryId: number; line: Line }) {
    return (
        <Form
            {...StockInventoryController.count.form({
                inventory: inventoryId,
                line: line.id,
            })}
            errorBag={`count${line.id}`}
            options={{ preserveScroll: true }}
            className="mt-3 flex flex-wrap items-end gap-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={`count-${line.id}`}>
                            Contado ({line.stockUnit})
                        </Label>
                        <Input
                            id={`count-${line.id}`}
                            name="counted"
                            type="number"
                            inputMode="numeric"
                            min={0}
                            max={100000}
                            required
                            defaultValue={line.counted ?? undefined}
                            className="h-11 w-36"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                        className="min-h-11"
                    >
                        {line.counted === null ? 'Salvar contagem' : 'Corrigir'}
                    </Button>
                    {line.countedAt && (
                        <span className="pb-3 text-sm text-muted-foreground">
                            Contado em {line.countedAt}
                        </span>
                    )}
                    <InputError
                        message={errors.counted ?? errors.status}
                        className="w-full"
                    />
                </>
            )}
        </Form>
    );
}

function FoundForm({
    inventoryId,
    catalog,
}: {
    inventoryId: number;
    catalog: StockCatalogOption[];
}) {
    return (
        <section className="grid content-start gap-3">
            <h2 className="text-lg font-semibold">
                Lote encontrado sem cadastro
            </h2>
            <Form
                {...StockInventoryController.found.form(inventoryId)}
                errorBag="found"
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="grid gap-4 rounded-xl border bg-card p-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="found-medication">
                                Medicamento ou material
                            </Label>
                            <StockItemSelect
                                id="found-medication"
                                catalog={catalog}
                            />
                            <InputError
                                message={
                                    errors.medication_id ??
                                    errors.material_id ??
                                    errors.status
                                }
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="found-lot">Lote</Label>
                                <Input
                                    id="found-lot"
                                    name="lot_code"
                                    required
                                    maxLength={50}
                                    autoCapitalize="characters"
                                    className="h-11"
                                />
                                <InputError message={errors.lot_code} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="found-expiry">Validade</Label>
                                <Input
                                    id="found-expiry"
                                    name="expires_on"
                                    type="date"
                                    required
                                    className="h-11"
                                />
                                <InputError message={errors.expires_on} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="found-counted">Contado</Label>
                                <Input
                                    id="found-counted"
                                    name="counted"
                                    type="number"
                                    inputMode="numeric"
                                    min={1}
                                    max={100000}
                                    required
                                    className="h-11"
                                />
                                <InputError message={errors.counted} />
                            </div>
                        </div>
                        <div>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Incluir lote
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </section>
    );
}

function DecideForms({ inventoryId }: { inventoryId: number }) {
    return (
        <section className="mt-8 grid gap-3">
            <h2 className="text-lg font-semibold">Decisão do gestor</h2>
            <div className="grid gap-4 rounded-xl border bg-card p-5">
                <p className="text-sm text-muted-foreground">
                    Aprovar ajusta cada lote pela diferença entre o contado e o
                    saldo do sistema no momento da contagem. O que saiu depois
                    não é ajustado de novo.
                </p>
                <Form
                    {...StockInventoryController.approve.form(inventoryId)}
                    errorBag="decide"
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <div className="grid gap-2">
                            <InputError message={errors.decision} />
                            <div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    Aprovar ajuste
                                </Button>
                            </div>
                        </div>
                    )}
                </Form>
                <Form
                    {...StockInventoryController.refuse.form(inventoryId)}
                    errorBag="decide"
                    options={{ preserveScroll: true }}
                    className="grid gap-2 border-t pt-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label htmlFor="refusal-reason">
                                Motivo da recusa
                            </Label>
                            <Input
                                id="refusal-reason"
                                name="refusal_reason"
                                required
                                maxLength={200}
                                className="h-11"
                            />
                            <InputError
                                message={
                                    errors.refusal_reason ?? errors.decision
                                }
                            />
                            <div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    Recusar
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </section>
    );
}
