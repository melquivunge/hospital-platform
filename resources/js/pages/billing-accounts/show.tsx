import { Head } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/page';
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
    | 'dispensing_return';

type Item = {
    id: number;
    day: string;
    origin: Origin;
    code: string | null;
    name: string;
    quantity: number;
    unitPriceCents: number | null;
    needsAuthorization: boolean;
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
};

/**
 * One account (step 6.2c, ADR-0010): the patient, the stay, the modality and card, and the items with their day,
 * code, quantity, frozen price and origin, with what is pending (no price; an authorization the contract asks for).
 */
export default function BillingAccountShow({
    account,
    items,
}: {
    account: AccountSummary & {
        card: string | null;
        cardValidUntil: string | null;
    };
    items: Item[];
}) {
    const withoutPrice = items.filter(
        (item) => item.unitPriceCents === null,
    ).length;
    const authorizations = items.filter(
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
                    <h2 id="items-heading" className="text-lg font-semibold">
                        Itens
                    </h2>
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
                                        {(item.unitPriceCents === null ||
                                            item.needsAuthorization) && (
                                            <span className="flex flex-wrap gap-2 pt-1">
                                                {item.unitPriceCents ===
                                                    null && (
                                                    <Pending>Sem preço</Pending>
                                                )}
                                                {item.needsAuthorization && (
                                                    <Pending>
                                                        Exige autorização
                                                    </Pending>
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
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
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
