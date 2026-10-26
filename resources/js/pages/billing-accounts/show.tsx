import { Form, Head } from '@inertiajs/react';
import BillingAccountController from '@/actions/App/Http/Controllers/BillingAccountController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/utils';
import { index } from '@/routes/billing-accounts';
import type { AccountSummary } from './index';
import { stayModality } from './index';

type Origin =
    | 'visit'
    | 'exam_order_item'
    | 'surgery'
    | 'admission_day'
    | 'stock_movement'
    | 'dispensing'
    | 'dispensing_return'
    | 'manual'
    | 'compensation';

type Item = {
    id: number;
    day: string;
    origin: Origin;
    code: string | null;
    name: string;
    quantity: number;
    unitPriceCents: number | null;
    needsAuthorization: boolean;
    reason: string | null;
    author: string | null;
    compensated: boolean;
};

/** Where each item came from, by its type only (ADR-0010: never the record's content). */
const originLabels: Record<Origin, string> = {
    visit: 'Consulta',
    exam_order_item: 'Exame liberado',
    surgery: 'Cirurgia concluída',
    admission_day: 'Diária',
    stock_movement: 'Material da cirurgia',
    dispensing: 'Medicamento dispensado',
    dispensing_return: 'Devolução de medicamento',
    manual: 'Item manual',
    compensation: 'Estorno',
};

/**
 * One account (step 6.2c, ADR-0010): the patient, the stay, the modality and card, and the items with their day,
 * code, quantity, frozen price and origin, with what is pending (no price; an authorization the contract asks for).
 */
export default function BillingAccountShow({
    account,
    items,
    canChange,
    billableItems,
    today,
}: {
    account: AccountSummary & {
        card: string | null;
        cardValidUntil: string | null;
    };
    items: Item[];
    canChange: boolean;
    billableItems: { id: number; name: string; code: string | null }[];
    today: string;
}) {
    // A compensation, and the item it compensates, no longer count as pending.
    const counts = items.filter(
        (item) => item.origin !== 'compensation' && !item.compensated,
    );
    const withoutPrice = counts.filter(
        (item) => item.unitPriceCents === null,
    ).length;
    const authorizations = counts.filter(
        (item) => item.needsAuthorization,
    ).length;

    return (
        <>
            <Head title="Conta" />
            <Page>
                <PageHeader
                    eyebrow={`Conta ${account.id} · aberta em ${account.openedAt}`}
                    title={account.patient ?? 'Paciente'}
                    description={`${account.stay} · ${stayModality(account)}`}
                />
                <dl className="mb-8 grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-3">
                    <div className="grid gap-1">
                        <dt className="text-sm text-muted-foreground">Total</dt>
                        <dd className="text-2xl font-semibold tabular-nums">
                            {money(account.totalCents)}
                        </dd>
                    </div>
                    <div className="grid gap-1">
                        <dt className="text-sm text-muted-foreground">
                            Pendências
                        </dt>
                        <dd>
                            {withoutPrice === 0 && authorizations === 0 ? (
                                'Nenhuma'
                            ) : (
                                <ul className="grid gap-0.5">
                                    {withoutPrice > 0 && (
                                        <li>
                                            {withoutPrice}{' '}
                                            {withoutPrice === 1
                                                ? 'item sem preço'
                                                : 'itens sem preço'}
                                        </li>
                                    )}
                                    {authorizations > 0 && (
                                        <li>
                                            {authorizations}{' '}
                                            {authorizations === 1
                                                ? 'item exige autorização'
                                                : 'itens exigem autorização'}
                                        </li>
                                    )}
                                </ul>
                            )}
                        </dd>
                    </div>
                    {account.card !== null && (
                        <div className="grid gap-1">
                            <dt className="text-sm text-muted-foreground">
                                Carteira
                            </dt>
                            <dd className="tabular-nums">
                                {account.card}
                                {account.cardValidUntil &&
                                    ` · válida até ${account.cardValidUntil}`}
                            </dd>
                        </div>
                    )}
                </dl>

                <section aria-labelledby="items-heading" className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2
                            id="items-heading"
                            className="text-lg font-semibold"
                        >
                            Itens
                        </h2>
                    </div>
                    {canChange && (
                        <AddItem
                            accountId={account.id}
                            billableItems={billableItems}
                            today={today}
                        />
                    )}
                    {items.length === 0 ? (
                        <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                            Nenhum item ainda. Os itens entram quando o
                            atendimento registra consulta, exame, cirurgia,
                            diária, material ou medicamento.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border bg-card">
                            {items.map((item) => (
                                <li
                                    key={item.id}
                                    className="grid gap-2 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {item.name}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {item.day} ·{' '}
                                            {originLabels[item.origin]}
                                            {item.code && ` · ${item.code}`}
                                        </span>
                                        {item.reason !== null && (
                                            <span className="text-sm text-muted-foreground">
                                                Motivo: {item.reason}
                                                {item.author &&
                                                    ` · por ${item.author}`}
                                            </span>
                                        )}
                                        {(isPending(item) ||
                                            item.compensated) && (
                                            <span className="flex flex-wrap gap-2 pt-1">
                                                {isPending(item) &&
                                                    item.unitPriceCents ===
                                                        null && (
                                                        <Pending>
                                                            Sem preço
                                                        </Pending>
                                                    )}
                                                {isPending(item) &&
                                                    item.needsAuthorization && (
                                                        <Pending>
                                                            Exige autorização
                                                        </Pending>
                                                    )}
                                                {item.compensated && (
                                                    <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                                        Estornado
                                                    </span>
                                                )}
                                            </span>
                                        )}
                                    </span>
                                    <span className="grid text-sm tabular-nums sm:text-right">
                                        <span className="font-medium">
                                            {item.unitPriceCents === null
                                                ? '—'
                                                : money(
                                                      item.quantity *
                                                          item.unitPriceCents,
                                                  )}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {item.quantity} ×{' '}
                                            {item.unitPriceCents === null
                                                ? 'sem preço'
                                                : money(item.unitPriceCents)}
                                        </span>
                                    </span>
                                    {canChange &&
                                        item.origin !== 'compensation' &&
                                        !item.compensated && (
                                            <Compensate item={item} />
                                        )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}

/** Step 6.3a: a manual item from the network's billable items, with the day, quantity and reason. */
function AddItem({
    accountId,
    billableItems,
    today,
}: {
    accountId: number;
    billableItems: { id: number; name: string; code: string | null }[];
    today: string;
}) {
    return (
        <details className="rounded-xl border bg-card px-5 py-2">
            <summary className="flex min-h-11 cursor-pointer items-center text-sm font-medium">
                Incluir item manual
            </summary>
            <Form
                {...BillingAccountController.storeItem.form(accountId)}
                errorBag="item"
                resetOnSuccess
                options={{ preserveScroll: true }}
                className="grid gap-4 py-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-4 sm:grid-cols-[1fr_auto_auto]">
                            <Field
                                id="billing_item_id"
                                label="Item"
                                error={errors.billing_item_id}
                            >
                                <select
                                    id="billing_item_id"
                                    name="billing_item_id"
                                    required
                                    defaultValue=""
                                    className={selectClassName}
                                >
                                    <option value="" disabled>
                                        {billableItems.length === 0
                                            ? 'Nenhum item cobrável ativo'
                                            : 'Selecione'}
                                    </option>
                                    {billableItems.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name}
                                            {option.code && ` (${option.code})`}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                id="performed_on"
                                label="Data"
                                error={errors.performed_on}
                            >
                                <Input
                                    id="performed_on"
                                    name="performed_on"
                                    type="date"
                                    required
                                    max={today}
                                    defaultValue={today}
                                    className="h-11"
                                />
                            </Field>
                            <Field
                                id="quantity"
                                label="Quantidade"
                                error={errors.quantity}
                            >
                                <Input
                                    id="quantity"
                                    name="quantity"
                                    type="number"
                                    required
                                    min={1}
                                    max={1000}
                                    defaultValue={1}
                                    inputMode="numeric"
                                    className="h-11 sm:w-28"
                                />
                            </Field>
                        </div>
                        <Field id="reason" label="Motivo" error={errors.reason}>
                            <Input
                                id="reason"
                                name="reason"
                                required
                                maxLength={500}
                                className="h-11"
                            />
                        </Field>
                        <p className="text-sm text-muted-foreground">
                            O preço vem da tabela do dia para a modalidade da
                            conta. O item fica com o motivo e o seu nome.
                        </p>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="min-h-11 justify-self-start"
                        >
                            Incluir item
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}

/** Step 6.3a: an item is never deleted; it is compensated once, with a reason. */
function Compensate({ item }: { item: Item }) {
    const id = `compensate-${item.id}`;

    return (
        <details className="sm:col-span-2">
            <summary className="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium text-primary">
                Estornar
            </summary>
            <Form
                {...BillingAccountController.compensate.form(item.id)}
                errorBag={`compensate${item.id}`}
                options={{ preserveScroll: true }}
                aria-label={`Estornar: ${item.name}, ${item.day}`}
                className="grid gap-3 pt-2 sm:grid-cols-[1fr_auto] sm:items-end"
            >
                {({ processing, errors }) => (
                    <>
                        <Field
                            id={id}
                            label="Motivo do estorno"
                            error={errors.reason}
                        >
                            <Input
                                id={id}
                                name="reason"
                                required
                                maxLength={500}
                                className="h-11"
                            />
                        </Field>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                            className="min-h-11"
                        >
                            Estornar item
                        </Button>
                    </>
                )}
            </Form>
        </details>
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
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

/** What still holds the account: not a compensation, nor an item already compensated. */
function isPending(item: Item): boolean {
    return (
        item.origin !== 'compensation' &&
        !item.compensated &&
        (item.unitPriceCents === null || item.needsAuthorization)
    );
}

function Pending({ children }: { children: string }) {
    return (
        <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">
            {children}
        </span>
    );
}

BillingAccountShow.layout = {
    breadcrumbs: [
        { title: 'Contas', href: index() },
        { title: 'Conta', href: '#' },
    ],
};
