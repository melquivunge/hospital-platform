import { Form, Head } from '@inertiajs/react';
import { ArrowDown, ArrowDownUp, Inbox } from 'lucide-react';
import ShiftRequestController from '@/actions/App/Http/Controllers/ShiftRequestController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { ShiftBrief } from '@/components/shift-display';
import { ShiftCard } from '@/components/shift-display';
import { index } from '@/routes/requests';

type Item = {
    id: number;
    type: 'transfer' | 'swap';
    counterpart: ShiftBrief | null;
    approvals: { unit: string; approved: boolean }[];
    status: string;
    statusLabel: string;
    requestedAt: string;
    shift: ShiftBrief;
    requester: string;
    recipient: string;
    recipientRegistration: string | null;
    note: string | null;
    decisionReason: string | null;
    direction: 'sent' | 'received' | null;
    canRespond: boolean;
    canCancel: boolean;
    canDecide: boolean;
};

/** "Solicitações" (steps 7a/7b, reference IMG_0918): transfers and swaps sent, received and to approve. */
export default function Requests({
    mine,
    toDecide,
    isCoordinator,
}: {
    mine: Item[];
    toDecide: Item[];
    isCoordinator: boolean;
}) {
    return (
        <>
            <Head title="Solicitações" />
            <Page width="narrow">
                <PageHeader
                    title="Solicitações"
                    description="Transferências e trocas de plantão. A escala só muda depois do aceite do colega e da aprovação da coordenação."
                />

                {isCoordinator && (
                    <section aria-labelledby="to-decide" className="mb-8">
                        <h2
                            id="to-decide"
                            className="mb-3 text-base font-semibold"
                        >
                            Para aprovar
                        </h2>
                        <List
                            items={toDecide}
                            empty="Nenhuma solicitação aguardando a sua unidade."
                        />
                    </section>
                )}

                {(!isCoordinator || mine.length > 0) && (
                    <section aria-labelledby="mine">
                        <h2 id="mine" className="mb-3 text-base font-semibold">
                            Minhas solicitações
                        </h2>
                        <List items={mine} empty="Você não tem solicitações." />
                    </section>
                )}
            </Page>
        </>
    );
}

function List({ items, empty }: { items: Item[]; empty: string }) {
    if (items.length === 0) {
        return (
            <div className="rounded-lg border bg-card px-5 py-10 text-center">
                <Inbox
                    className="mx-auto mb-3 size-6 text-primary"
                    aria-hidden="true"
                />
                <p className="font-medium">{empty}</p>
            </div>
        );
    }

    return (
        <ul className="grid gap-4">
            {items.map((item) => (
                <li
                    key={item.id}
                    className="grid gap-3 rounded-lg border bg-card p-4 text-sm"
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <p className="font-semibold text-primary">
                            {item.statusLabel}
                        </p>
                        <p className="text-muted-foreground">
                            {item.type === 'swap' ? 'Troca' : 'Transferência'} ·{' '}
                            {item.direction === 'sent'
                                ? 'enviada'
                                : item.direction === 'received'
                                  ? 'recebida'
                                  : 'solicitada'}{' '}
                            em {item.requestedAt}
                        </p>
                    </div>
                    {item.counterpart ? (
                        <SwapShifts item={item} />
                    ) : (
                        <ShiftCard shift={item.shift} />
                    )}
                    {!item.counterpart && (
                        <div className="grid gap-1">
                            <p>
                                <span className="text-muted-foreground">
                                    De{' '}
                                </span>
                                <span className="font-medium">
                                    {item.requester}
                                </span>
                            </p>
                            <ArrowDown
                                className="size-4 text-primary"
                                aria-hidden="true"
                            />
                            <p>
                                <span className="text-muted-foreground">
                                    Para{' '}
                                </span>
                                <span className="font-medium">
                                    {item.recipient}
                                </span>
                                {item.recipientRegistration && (
                                    <span className="ml-2 text-muted-foreground">
                                        {item.recipientRegistration}
                                    </span>
                                )}
                            </p>
                        </div>
                    )}
                    {item.status === 'pending_coordinator' && (
                        <ul className="grid gap-1" aria-label="Aprovações">
                            {item.approvals.map((approval) => (
                                <li key={approval.unit}>
                                    <span className="text-muted-foreground">
                                        {approval.unit}:{' '}
                                    </span>
                                    <span className="font-medium">
                                        {approval.approved
                                            ? 'aprovado'
                                            : 'aguardando aprovação'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {item.note && <p>Mensagem: {item.note}</p>}
                    {item.decisionReason && (
                        <p>Motivo: {item.decisionReason}</p>
                    )}
                    <Actions item={item} />
                </li>
            ))}
        </ul>
    );
}

/** The two shifts of a swap, each with the person who gives it up. */
function SwapShifts({ item }: { item: Item }) {
    return (
        <div className="grid gap-2">
            <p>
                <span className="text-muted-foreground">Plantão de </span>
                <span className="font-medium">{item.requester}</span>
            </p>
            <ShiftCard shift={item.shift} />
            <ArrowDownUp
                className="size-4 justify-self-center text-primary"
                aria-label="troca com"
            />
            <p>
                <span className="text-muted-foreground">Plantão de </span>
                <span className="font-medium">{item.recipient}</span>
                {item.recipientRegistration && (
                    <span className="ml-2 text-muted-foreground">
                        {item.recipientRegistration}
                    </span>
                )}
            </p>
            {item.counterpart && <ShiftCard shift={item.counterpart} />}
        </div>
    );
}

function Actions({ item }: { item: Item }) {
    if (item.canRespond) {
        return (
            <div className="flex flex-wrap gap-2 border-t pt-3">
                <Decision
                    action={ShiftRequestController.respond.form(item.id)}
                    field="accept"
                    value="1"
                    label="Aceitar"
                />
                <Decision
                    action={ShiftRequestController.respond.form(item.id)}
                    field="accept"
                    value="0"
                    label="Recusar"
                    variant="outline"
                />
            </div>
        );
    }

    if (item.canCancel) {
        return (
            <div className="border-t pt-3">
                <Decision
                    action={ShiftRequestController.cancel.form(item.id)}
                    label="Cancelar solicitação"
                    variant="destructive"
                />
            </div>
        );
    }

    if (item.canDecide) {
        const field = `reason-${item.id}`;

        return (
            <div className="grid gap-3 border-t pt-3">
                <Decision
                    action={ShiftRequestController.decide.form(item.id)}
                    field="approve"
                    value="1"
                    label={
                        item.type === 'swap'
                            ? 'Aprovar troca'
                            : 'Aprovar transferência'
                    }
                />
                <Form
                    {...ShiftRequestController.decide.form(item.id)}
                    options={{ preserveScroll: true }}
                    className="grid gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="approve" value="0" />
                            <Label htmlFor={field}>Motivo da recusa</Label>
                            <Input
                                id={field}
                                name="reason"
                                required
                                maxLength={1000}
                                aria-invalid={!!errors.reason}
                                aria-describedby={
                                    errors.reason ? `${field}-error` : undefined
                                }
                                className="h-11"
                            />
                            <InputError
                                id={`${field}-error`}
                                message={errors.reason}
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                className="min-h-11 justify-self-start"
                            >
                                {processing && <Spinner />}
                                {item.type === 'swap'
                                    ? 'Recusar troca'
                                    : 'Recusar transferência'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        );
    }

    return null;
}

function Decision({
    action,
    field,
    value,
    label,
    variant = 'default',
}: {
    action: ReturnType<typeof ShiftRequestController.cancel.form>;
    field?: string;
    value?: string;
    label: string;
    variant?: 'default' | 'outline' | 'destructive';
}) {
    return (
        <Form {...action} options={{ preserveScroll: true }}>
            {({ processing, errors }) => (
                <>
                    {field && (
                        <input type="hidden" name={field} value={value} />
                    )}
                    <Button
                        type="submit"
                        variant={variant}
                        disabled={processing}
                        className="min-h-11"
                    >
                        {processing && <Spinner />}
                        {label}
                    </Button>
                    <InputError
                        message={
                            errors.request ??
                            errors.professional_id ??
                            errors.starts_at
                        }
                        className="mt-2"
                    />
                </>
            )}
        </Form>
    );
}

Requests.layout = {
    breadcrumbs: [{ title: 'Solicitações', href: index() }],
};
