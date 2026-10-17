import { Form, Head } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import PolicyController from '@/actions/App/Http/Controllers/PolicyController';
import InputError from '@/components/input-error';
import { Page, PageHeader, WarningBadge } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/policies';

type NetworkVersion = {
    version: number;
    value: number;
    delegable: boolean;
    minValue: number | null;
    maxValue: number | null;
    reason: string;
    effectiveFrom: string;
    author: string | null;
};

type UnitPolicy = {
    id: number;
    name: string;
    value: number | null;
    origin: 'network' | 'unit' | null;
    localValue: number | null;
    localIgnored: boolean;
    localVersion: number;
    canPublish: boolean;
};

type Policy = {
    key: string;
    label: string;
    description: string;
    unit: string;
    bounds: { min: number; max: number };
    network: NetworkVersion | null;
    units: UnitPolicy[];
};

export default function PoliciesIndex({
    policies,
    canPublishNetwork,
}: {
    policies: Policy[];
    canPublishNetwork: boolean;
}) {
    return (
        <>
            <Head title="Regras" />
            <Page>
                <PageHeader
                    eyebrow="Governança"
                    title="Regras"
                    description="A rede define o valor e os limites; cada hospital ajusta somente o que a rede permitir. Cada publicação cria uma nova versão, com autor, motivo e vigência."
                />

                <div className="grid gap-8">
                    {policies.map((policy) => (
                        <PolicyCard
                            key={policy.key}
                            policy={policy}
                            canPublishNetwork={canPublishNetwork}
                        />
                    ))}
                </div>
            </Page>
        </>
    );
}

function formatValue(value: number | null, unit: string) {
    return value === null ? '—' : `${value} ${unit}`;
}

function formatLimits(network: NetworkVersion, unit: string) {
    if (network.minValue === null && network.maxValue === null) {
        return 'Sem limites';
    }

    return `${network.minValue ?? '—'} a ${network.maxValue ?? '—'} ${unit}`;
}

function PolicyCard({
    policy,
    canPublishNetwork,
}: {
    policy: Policy;
    canPublishNetwork: boolean;
}) {
    const { network } = policy;
    const headingId = `policy-${policy.key}`;

    return (
        <section
            aria-labelledby={headingId}
            className="overflow-hidden rounded-lg border bg-card"
        >
            <header className="border-b px-5 py-4">
                <h2
                    id={headingId}
                    className="flex items-center gap-2 font-semibold tracking-tight"
                >
                    <SlidersHorizontal
                        className="size-4 text-primary"
                        aria-hidden="true"
                    />
                    {policy.label}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {policy.description}
                </p>
            </header>

            <div className="grid gap-6 px-5 py-5 lg:grid-cols-2">
                <div>
                    <h3 className="mb-3 text-sm font-semibold tracking-tight">
                        Regra da rede
                    </h3>
                    {network === null ? (
                        <div className="space-y-2 text-sm">
                            <WarningBadge>Não configurada</WarningBadge>
                            <p className="text-muted-foreground">
                                A rede ainda não publicou um valor. Recursos que
                                dependem desta regra não podem ser ativados.
                            </p>
                        </div>
                    ) : (
                        <dl className="divide-y rounded-lg border text-sm">
                            <Detail
                                label="Valor"
                                value={formatValue(network.value, policy.unit)}
                            />
                            <Detail
                                label="Hospital pode alterar"
                                value={network.delegable ? 'Sim' : 'Não'}
                            />
                            {network.delegable && (
                                <Detail
                                    label="Limites para o hospital"
                                    value={formatLimits(network, policy.unit)}
                                />
                            )}
                            <Detail
                                label="Versão"
                                value={`${network.version}, desde ${new Date(network.effectiveFrom).toLocaleString('pt-BR')}`}
                            />
                            <Detail
                                label="Publicada por"
                                value={network.author ?? 'Não informado'}
                            />
                            <Detail label="Motivo" value={network.reason} />
                        </dl>
                    )}
                </div>

                {canPublishNetwork && <NetworkForm policy={policy} />}
            </div>

            <div className="border-t">
                <h3 className="px-5 pt-4 text-sm font-semibold tracking-tight">
                    Valor em vigor por unidade
                </h3>
                {policy.units.length === 0 ? (
                    <p className="px-5 py-6 text-sm text-muted-foreground">
                        Nenhuma unidade para exibir.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {policy.units.map((unit) => (
                            <li
                                key={unit.id}
                                className="grid gap-3 px-5 py-4 md:grid-cols-[1fr_auto] md:items-start"
                            >
                                <div className="space-y-1 text-sm">
                                    <p className="font-medium">{unit.name}</p>
                                    <p className="text-muted-foreground">
                                        {unit.value === null
                                            ? 'Sem valor em vigor'
                                            : `${formatValue(unit.value, policy.unit)} · ${unit.origin === 'unit' ? 'definido pela unidade' : 'herdado da rede'}`}
                                    </p>
                                    {unit.localIgnored && (
                                        <WarningBadge>
                                            Valor local ({unit.localValue}{' '}
                                            {policy.unit}) fora do permitido;
                                            vale o da rede
                                        </WarningBadge>
                                    )}
                                </div>
                                {unit.canPublish && network?.delegable && (
                                    <LocalForm policy={policy} unit={unit} />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

function NetworkForm({ policy }: { policy: Policy }) {
    const { network } = policy;
    const id = `network-${policy.key}`;

    return (
        <Form
            {...PolicyController.publishNetwork.form(policy.key)}
            errorBag={id}
            options={{ preserveScroll: true }}
            resetOnSuccess={['reason']}
            className="grid gap-4 rounded-lg border bg-muted/40 p-4"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="text-sm font-semibold tracking-tight">
                        Publicar nova versão da rede
                    </h3>
                    <input
                        type="hidden"
                        name="expected_version"
                        value={network?.version ?? 0}
                    />
                    <NumberField
                        id={`${id}-value`}
                        name="value"
                        label={`Valor (${policy.unit})`}
                        policy={policy}
                        defaultValue={network?.value}
                        required
                        error={errors.value}
                    />
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id={`${id}-delegable`}
                            name="delegable"
                            value="1"
                            defaultChecked={network?.delegable}
                        />
                        <Label htmlFor={`${id}-delegable`}>
                            Hospital pode alterar dentro dos limites
                        </Label>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <NumberField
                            id={`${id}-min`}
                            name="min_value"
                            label={`Limite mínimo (${policy.unit})`}
                            policy={policy}
                            defaultValue={network?.minValue}
                            error={errors.min_value}
                        />
                        <NumberField
                            id={`${id}-max`}
                            name="max_value"
                            label={`Limite máximo (${policy.unit})`}
                            policy={policy}
                            defaultValue={network?.maxValue}
                            error={errors.max_value}
                        />
                    </div>
                    <ReasonField id={`${id}-reason`} error={errors.reason} />
                    {errors.confirm_incompatible && (
                        <div className="grid gap-2 rounded-lg border border-warning-border bg-warning-surface p-3 text-sm text-warning">
                            <p role="alert">{errors.confirm_incompatible}</p>
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id={`${id}-confirm`}
                                    name="confirm_incompatible"
                                    value="1"
                                />
                                <Label htmlFor={`${id}-confirm`}>
                                    Confirmo a publicação
                                </Label>
                            </div>
                        </div>
                    )}
                    <InputError message={errors.expected_version} />
                    <Button
                        type="submit"
                        disabled={processing}
                        className="min-h-11 justify-self-start"
                    >
                        {processing && <Spinner />}
                        Publicar
                    </Button>
                </>
            )}
        </Form>
    );
}

function LocalForm({ policy, unit }: { policy: Policy; unit: UnitPolicy }) {
    const id = `unit-${unit.id}-${policy.key}`;

    return (
        <Form
            {...PolicyController.publishLocal.form([unit.id, policy.key])}
            errorBag={id}
            options={{ preserveScroll: true }}
            resetOnSuccess={['reason']}
            className="grid gap-3 md:w-80"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="expected_version"
                        value={unit.localVersion}
                    />
                    <NumberField
                        id={`${id}-value`}
                        name="value"
                        label={`Valor da unidade (${policy.unit})`}
                        hint="Deixe vazio para usar o valor da rede."
                        policy={policy}
                        defaultValue={unit.localValue}
                        error={errors.value}
                    />
                    <ReasonField id={`${id}-reason`} error={errors.reason} />
                    <InputError message={errors.expected_version} />
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                        aria-label={`Publicar valor de ${unit.name}`}
                        className="min-h-11 justify-self-start"
                    >
                        {processing && <Spinner />}
                        Publicar
                    </Button>
                </>
            )}
        </Form>
    );
}

function NumberField({
    id,
    name,
    label,
    hint,
    policy,
    defaultValue,
    required,
    error,
}: {
    id: string;
    name: string;
    label: string;
    hint?: string;
    policy: Policy;
    defaultValue?: number | null;
    required?: boolean;
    error?: string;
}) {
    const describedBy =
        [hint && `${id}-hint`, error && `${id}-error`]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={name}
                type="number"
                inputMode="numeric"
                min={policy.bounds.min}
                max={policy.bounds.max}
                step={1}
                required={required}
                defaultValue={defaultValue ?? ''}
                aria-invalid={!!error}
                aria-describedby={describedBy}
                className="h-11 bg-card"
            />
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

function ReasonField({ id, error }: { id: string; error?: string }) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>Motivo</Label>
            <Input
                id={id}
                name="reason"
                required
                maxLength={1000}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className="h-11 bg-card"
            />
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-start justify-between gap-3 px-4 py-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium break-words">{value}</dd>
        </div>
    );
}

PoliciesIndex.layout = {
    breadcrumbs: [{ title: 'Regras', href: index() }],
};
