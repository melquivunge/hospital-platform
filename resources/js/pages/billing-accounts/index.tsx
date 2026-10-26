import { Head, Link, router } from '@inertiajs/react';
import BillingAccountController from '@/actions/App/Http/Controllers/BillingAccountController';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Label } from '@/components/ui/label';
import { cn, money } from '@/lib/utils';
import { index } from '@/routes/billing-accounts';

export type AccountSummary = {
    id: number;
    patient: string | null;
    stay: string;
    modality: string | null;
    insurer: string | null;
    status: string;
    openedAt: string;
    items: number;
    pending: number;
    totalCents: number;
};

/**
 * The unit's open accounts for billing (step 6.2c, ADR-0010): identification, modality, totals and pending items
 * only. Opening an account is logged in the access trail.
 */
export default function BillingAccountsIndex({
    units,
    unitId,
    pendingOnly,
    accounts,
}: {
    units: { id: number; name: string }[];
    unitId: number;
    pendingOnly: boolean;
    accounts: AccountSummary[];
}) {
    const unit = units.find((option) => option.id === unitId);
    const filters = [
        { label: 'Abertas', pending: false },
        { label: 'Com pendências', pending: true },
    ];

    return (
        <>
            <Head title="Contas" />
            <Page>
                <PageHeader
                    eyebrow={unit?.name}
                    title="Contas"
                    description="Contas abertas da unidade, com os itens gerados do atendimento. Abrir uma conta fica registrado na trilha de acesso."
                />
                <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <nav
                        aria-label="Filtro das contas"
                        className="inline-flex rounded-lg bg-muted p-1"
                    >
                        {filters.map((filter) => (
                            <Link
                                key={filter.label}
                                href={
                                    index({
                                        query: {
                                            unit: unitId,
                                            ...(filter.pending
                                                ? { pending: 1 }
                                                : {}),
                                        },
                                    }).url
                                }
                                aria-current={
                                    filter.pending === pendingOnly
                                        ? 'page'
                                        : undefined
                                }
                                className={cn(
                                    'flex min-h-11 items-center rounded-md px-4 text-sm font-medium text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    filter.pending === pendingOnly &&
                                        'bg-background text-foreground shadow-sm',
                                )}
                            >
                                {filter.label}
                            </Link>
                        ))}
                    </nav>
                    {units.length > 1 && (
                        <div className="grid w-full max-w-sm gap-2">
                            <Label htmlFor="unit">Unidade</Label>
                            <select
                                id="unit"
                                value={unitId}
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
                </div>
                {accounts.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        {pendingOnly
                            ? 'Nenhuma conta com pendências nesta unidade.'
                            : 'Nenhuma conta aberta nesta unidade.'}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {accounts.map((account) => (
                            <li key={account.id}>
                                <Link
                                    href={BillingAccountController.show.url(
                                        account.id,
                                    )}
                                    className="flex min-h-11 flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="font-medium">
                                            {account.patient ?? 'Paciente'}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {account.stay} ·{' '}
                                            {stayModality(account)} · aberta em{' '}
                                            {account.openedAt}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-3">
                                        {account.pending > 0 && (
                                            <span className="rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                                {account.pending}{' '}
                                                {account.pending === 1
                                                    ? 'pendência'
                                                    : 'pendências'}
                                            </span>
                                        )}
                                        <span className="grid text-right">
                                            <span className="font-medium tabular-nums">
                                                {money(account.totalCents)}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {account.items}{' '}
                                                {account.items === 1
                                                    ? 'item'
                                                    : 'itens'}
                                            </span>
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

export function stayModality(account: AccountSummary): string {
    if (account.modality === null) {
        return 'Modalidade não registrada';
    }

    return account.insurer
        ? `${account.modality} (${account.insurer})`
        : account.modality;
}

BillingAccountsIndex.layout = {
    breadcrumbs: [{ title: 'Contas', href: index() }],
};
