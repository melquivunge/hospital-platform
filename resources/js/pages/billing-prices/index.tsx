import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import BillingPriceController from '@/actions/App/Http/Controllers/BillingPriceController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/utils';
import { index } from '@/routes/billing-prices';

type Item = {
    id: number;
    kind: string;
    bed_type: string | null;
    name: string;
    code: string | null;
    sigtap_code: string | null;
    active: boolean;
};

type Table = { id: number; name: string; active: boolean; private: boolean };

type InsurerRow = {
    id: number;
    name: string;
    contract: {
        id: number;
        tableId: number;
        adjustmentBp: number;
        providerCode: string | null;
        authorizationItemIds: number[];
    } | null;
};

type Price = {
    id: number;
    itemId: number;
    cents: number;
    from: string;
    until: string | null;
};

type Option = { id: number; name: string };

/**
 * The network's billable items, price tables and prices (step 6.1a, ADR-0010), kept by the network admin. A new
 * price ends the one before it; prices are never changed. No patient data.
 */
export default function BillingPricesIndex({
    kinds,
    bedTypes,
    items,
    tables,
    tableId,
    prices,
    references,
    insurers,
}: {
    kinds: Record<string, string>;
    bedTypes: Record<string, string>;
    items: Item[];
    tables: Table[];
    tableId: number | null;
    prices: Price[];
    references: Record<string, Option[]>;
    insurers: InsurerRow[];
}) {
    const table = tables.find((option) => option.id === tableId);
    const itemName = (id: number) =>
        items.find((item) => item.id === id)?.name ?? '';

    return (
        <>
            <Head title="Preços" />
            <Page>
                <PageHeader
                    title="Preços"
                    description="Itens cobráveis da rede e tabelas de preço com vigência. Um preço novo encerra o anterior; preços nunca são alterados."
                />

                <section className="mb-10 grid gap-3">
                    <h2 className="text-lg font-semibold">Tabelas de preço</h2>
                    <Form
                        {...BillingPriceController.storeTable.form()}
                        errorBag="newTable"
                        resetOnSuccess
                        className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="new-table-name">
                                        Nova tabela
                                    </Label>
                                    <Input
                                        id="new-table-name"
                                        name="name"
                                        required
                                        maxLength={100}
                                        className="h-11 w-72 max-w-full"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    Criar tabela
                                </Button>
                            </>
                        )}
                    </Form>
                    {tables.length > 0 && (
                        <nav
                            aria-label="Tabelas de preço"
                            className="flex flex-wrap gap-2"
                        >
                            {tables.map((option) => (
                                <Button
                                    key={option.id}
                                    asChild
                                    variant={
                                        option.id === tableId
                                            ? 'default'
                                            : 'outline'
                                    }
                                    className="min-h-11"
                                >
                                    <Link
                                        href={
                                            index({
                                                query: { table: option.id },
                                            }).url
                                        }
                                        preserveScroll
                                        aria-current={
                                            option.id === tableId
                                                ? 'page'
                                                : undefined
                                        }
                                    >
                                        {option.name}
                                        {option.private && ' · particular'}
                                        {!option.active && ' (inativa)'}
                                    </Link>
                                </Button>
                            ))}
                        </nav>
                    )}
                </section>

                {table && (
                    <section className="mb-10 grid gap-3">
                        <h2 className="text-lg font-semibold">
                            Preços da tabela {table.name}
                        </h2>
                        <TableEdit table={table} />
                        {table.private ? (
                            <p className="text-sm text-muted-foreground">
                                Esta é a tabela particular: vale para os
                                atendimentos sem convênio.
                            </p>
                        ) : (
                            table.active && (
                                <Form
                                    {...BillingPriceController.setPrivate.form(
                                        table.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            className="min-h-11"
                                        >
                                            Tornar a tabela particular
                                        </Button>
                                    )}
                                </Form>
                            )
                        )}
                        {items.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Cadastre os itens cobráveis abaixo antes dos
                                preços.
                            </p>
                        ) : (
                            <PriceForm table={table} items={items} />
                        )}
                        {prices.length === 0 ? (
                            <p className="rounded-xl border bg-card px-5 py-8 text-center text-muted-foreground">
                                Nenhum preço nesta tabela.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-xl border bg-card px-5 text-sm">
                                {prices.map((price) => (
                                    <li
                                        key={price.id}
                                        className="flex flex-wrap items-baseline justify-between gap-2 py-3"
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {itemName(price.itemId)}
                                            </span>
                                            <span className="block text-muted-foreground">
                                                {price.until
                                                    ? `de ${price.from} a ${price.until}`
                                                    : `desde ${price.from}`}
                                            </span>
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {money(price.cents)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                <section className="mb-10 grid gap-3">
                    <h2 className="text-lg font-semibold">Convênios</h2>
                    {insurers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nenhum convênio ativo. Cadastre em Convênios.
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {insurers.map((insurer) => (
                                <InsurerContractItem
                                    key={insurer.id}
                                    insurer={insurer}
                                    tables={tables.filter(
                                        (option) => option.active,
                                    )}
                                    items={items.filter((item) => item.active)}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">Itens cobráveis</h2>
                    <ItemCreate
                        kinds={kinds}
                        bedTypes={bedTypes}
                        references={references}
                    />
                    {items.length > 0 && (
                        <ul className="grid gap-3">
                            {items.map((item) => (
                                <li
                                    key={item.id}
                                    className="rounded-xl border bg-card px-5 py-4"
                                >
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <h3 className="font-medium">
                                            {item.name}
                                        </h3>
                                        <span className="text-sm text-muted-foreground">
                                            {kinds[item.kind]}
                                            {item.bed_type &&
                                                ` · ${bedTypes[item.bed_type]}`}
                                            {item.code && ` · ${item.code}`}
                                            {item.sigtap_code &&
                                                ` · SIGTAP ${item.sigtap_code}`}
                                            {!item.active && ' · inativo'}
                                        </span>
                                    </div>
                                    <ItemEdit item={item} />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}

function TableEdit({ table }: { table: Table }) {
    return (
        <details>
            <summary
                aria-label={`Alterar a tabela ${table.name}`}
                className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary"
            >
                Alterar a tabela
            </summary>
            <Form
                {...BillingPriceController.updateTable.form(table.id)}
                errorBag={`table-${table.id}`}
                options={{ preserveScroll: true }}
                className="grid gap-3 pb-2"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid max-w-sm gap-2">
                            <Label htmlFor={`table-${table.id}-name`}>
                                Nome
                            </Label>
                            <Input
                                id={`table-${table.id}-name`}
                                name="name"
                                required
                                maxLength={100}
                                defaultValue={table.name}
                                className="h-11"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <ActiveToggle
                            active={table.active}
                            label="Ativa (tabela inativa não é usada em contratos novos)"
                        />
                        <div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Salvar tabela
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </details>
    );
}

function PriceForm({ table, items }: { table: Table; items: Item[] }) {
    return (
        <Form
            {...BillingPriceController.storePrice.form(table.id)}
            errorBag="price"
            options={{ preserveScroll: true }}
            resetOnSuccess={['price']}
            className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="price-item">Item</Label>
                        <select
                            id="price-item"
                            name="billing_item_id"
                            required
                            defaultValue=""
                            className={selectClassName}
                        >
                            <option value="" disabled>
                                Selecione
                            </option>
                            {items
                                .filter((item) => item.active)
                                .map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {item.name}
                                    </option>
                                ))}
                        </select>
                        <InputError message={errors.billing_item_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="price-value">Preço (R$)</Label>
                        <Input
                            id="price-value"
                            name="price"
                            required
                            inputMode="decimal"
                            placeholder="1.234,56"
                            className="h-11"
                        />
                        <InputError message={errors.price} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="price-from">A partir de</Label>
                        <Input
                            id="price-from"
                            name="valid_from"
                            type="date"
                            required
                            className="h-11"
                        />
                        <InputError message={errors.valid_from} />
                    </div>
                    <Button
                        type="submit"
                        disabled={processing}
                        className="min-h-11"
                    >
                        Registrar preço
                    </Button>
                </>
            )}
        </Form>
    );
}

function ItemCreate({
    kinds,
    bedTypes,
    references,
}: {
    kinds: Record<string, string>;
    bedTypes: Record<string, string>;
    references: Record<string, Option[]>;
}) {
    const [kind, setKind] = useState('consultation');
    const options = references[kind];

    return (
        <Form
            {...BillingPriceController.storeItem.form()}
            errorBag="newItem"
            options={{ preserveScroll: true }}
            resetOnSuccess={['name', 'code', 'sigtap_code']}
            className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="new-item-kind">Tipo</Label>
                        <select
                            id="new-item-kind"
                            name="kind"
                            value={kind}
                            onChange={(event) => setKind(event.target.value)}
                            className={selectClassName}
                        >
                            {Object.entries(kinds).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.kind} />
                    </div>
                    {kind === 'daily' && (
                        <div className="grid gap-2">
                            <Label htmlFor="new-item-bed">Tipo de leito</Label>
                            <select
                                id="new-item-bed"
                                name="bed_type"
                                className={selectClassName}
                            >
                                {Object.entries(bedTypes).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                            <InputError message={errors.bed_type} />
                        </div>
                    )}
                    {options && (
                        // A new kind starts the choice over: never a stale option of the previous list.
                        <div key={kind} className="grid gap-2">
                            <Label htmlFor="new-item-reference">
                                Item do catálogo
                            </Label>
                            <select
                                id="new-item-reference"
                                name="reference_id"
                                required
                                defaultValue=""
                                className={selectClassName}
                            >
                                <option value="" disabled>
                                    Selecione
                                </option>
                                {options.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.reference_id} />
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="new-item-name">Nome na conta</Label>
                        <Input
                            id="new-item-name"
                            name="name"
                            required
                            maxLength={255}
                            className="h-11"
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="new-item-code">
                            Código TUSS ou próprio (opcional)
                        </Label>
                        <Input
                            id="new-item-code"
                            name="code"
                            maxLength={20}
                            className="h-11"
                        />
                        <InputError message={errors.code} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="new-item-sigtap">
                            Código SIGTAP, para o SUS (opcional)
                        </Label>
                        <Input
                            id="new-item-sigtap"
                            name="sigtap_code"
                            inputMode="numeric"
                            maxLength={10}
                            className="h-11"
                        />
                        <InputError message={errors.sigtap_code} />
                    </div>
                    <div className="sm:col-span-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Cadastrar item
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function ItemEdit({ item }: { item: Item }) {
    return (
        <details className="mt-2">
            <summary
                aria-label={`Alterar ${item.name}`}
                className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary"
            >
                Alterar
            </summary>
            <Form
                {...BillingPriceController.updateItem.form(item.id)}
                errorBag={`item-${item.id}`}
                options={{ preserveScroll: true }}
                className="grid gap-3 pb-2 sm:grid-cols-2"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor={`item-${item.id}-name`}>
                                Nome na conta
                            </Label>
                            <Input
                                id={`item-${item.id}-name`}
                                name="name"
                                required
                                maxLength={255}
                                defaultValue={item.name}
                                className="h-11"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`item-${item.id}-code`}>
                                Código (opcional)
                            </Label>
                            <Input
                                id={`item-${item.id}-code`}
                                name="code"
                                maxLength={20}
                                defaultValue={item.code ?? ''}
                                className="h-11"
                            />
                            <InputError message={errors.code} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`item-${item.id}-sigtap`}>
                                Código SIGTAP (opcional)
                            </Label>
                            <Input
                                id={`item-${item.id}-sigtap`}
                                name="sigtap_code"
                                inputMode="numeric"
                                maxLength={10}
                                defaultValue={item.sigtap_code ?? ''}
                                className="h-11"
                            />
                            <InputError message={errors.sigtap_code} />
                        </div>
                        <ActiveToggle
                            active={item.active}
                            label="Ativo (item inativo não recebe preço novo)"
                        />
                        <div className="sm:col-span-2">
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Salvar item
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </details>
    );
}

function ActiveToggle({ active, label }: { active: boolean; label: string }) {
    return (
        <label className="flex min-h-11 items-center gap-3 text-sm sm:col-span-2">
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

function InsurerContractItem({
    insurer,
    tables,
    items,
}: {
    insurer: InsurerRow;
    tables: Table[];
    items: Item[];
}) {
    const contract = insurer.contract;
    const adjustment = contract
        ? (contract.adjustmentBp / 100).toLocaleString('pt-BR', {
              maximumFractionDigits: 2,
              useGrouping: false,
          })
        : '';

    return (
        <li className="grid gap-3 rounded-xl border bg-card px-5 py-4">
            <h3 className="font-medium">{insurer.name}</h3>
            {tables.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Crie uma tabela de preço antes do contrato.
                </p>
            ) : (
                <Form
                    {...BillingPriceController.saveContract.form(insurer.id)}
                    errorBag={`contract-${insurer.id}`}
                    options={{ preserveScroll: true }}
                    className="grid gap-3 sm:grid-cols-[2fr_1fr_1.5fr_auto] sm:items-end"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={`contract-${insurer.id}-table`}>
                                    Tabela de preço
                                </Label>
                                <select
                                    id={`contract-${insurer.id}-table`}
                                    name="price_table_id"
                                    required
                                    defaultValue={contract?.tableId ?? ''}
                                    className={selectClassName}
                                >
                                    <option value="" disabled>
                                        Selecione
                                    </option>
                                    {tables.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.price_table_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label
                                    htmlFor={`contract-${insurer.id}-adjustment`}
                                >
                                    Ajuste (%)
                                </Label>
                                <Input
                                    id={`contract-${insurer.id}-adjustment`}
                                    name="adjustment"
                                    inputMode="decimal"
                                    placeholder="0"
                                    defaultValue={adjustment}
                                    className="h-11"
                                />
                                <InputError message={errors.adjustment} />
                            </div>
                            <div className="grid gap-2">
                                <Label
                                    htmlFor={`contract-${insurer.id}-provider`}
                                >
                                    Código do prestador
                                </Label>
                                <Input
                                    id={`contract-${insurer.id}-provider`}
                                    name="provider_code"
                                    maxLength={14}
                                    defaultValue={contract?.providerCode ?? ''}
                                    aria-invalid={!!errors.provider_code}
                                    title="Código do hospital nesta operadora, vai nas guias TISS"
                                    className="h-11"
                                />
                                <InputError message={errors.provider_code} />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Salvar contrato
                            </Button>
                        </>
                    )}
                </Form>
            )}
            {contract && items.length > 0 && (
                <details>
                    <summary
                        aria-label={`Itens que exigem autorização de ${insurer.name}`}
                        className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary"
                    >
                        Itens que exigem autorização (
                        {contract.authorizationItemIds.length})
                    </summary>
                    <Form
                        {...BillingPriceController.saveAuthorizations.form(
                            contract.id,
                        )}
                        errorBag={`authorizations-${contract.id}`}
                        options={{ preserveScroll: true }}
                        className="grid gap-1 pb-2"
                    >
                        {({ processing }) => (
                            <>
                                {items.map((item) => (
                                    <label
                                        key={item.id}
                                        className="flex min-h-11 items-center gap-3 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="billing_item_ids[]"
                                            value={item.id}
                                            defaultChecked={contract.authorizationItemIds.includes(
                                                item.id,
                                            )}
                                            className="size-5 shrink-0"
                                        />
                                        {item.name}
                                    </label>
                                ))}
                                <div className="pt-2">
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        Salvar itens
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </details>
            )}
        </li>
    );
}
