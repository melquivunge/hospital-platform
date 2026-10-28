import { Form, Head, Link } from '@inertiajs/react';
import ProfessionalController from '@/actions/App/Http/Controllers/ProfessionalController';
import InputError from '@/components/input-error';
import { History, Pencil } from 'lucide-react';
import { Page, PageHeader } from '@/components/page';
import { QualificationsSection } from '@/components/qualifications-section';
import type {
    Qualification,
    QualificationOptions,
} from '@/components/qualifications-section';
import { Button } from '@/components/ui/button';
import { councilLabel } from '@/pages/professionals/index';
import { edit, index } from '@/routes/professionals';

type Professional = {
    id: number;
    name: string;
    baseUnit: string | null;
    council: string | null;
    council_number: string | null;
    council_uf: string | null;
    external_id: string | null;
    email?: string | null;
    phone?: string | null;
    cpf?: string | null;
    cns?: string | null;
};

type HistoryEntry = {
    id: number;
    action: 'professional.created' | 'professional.base_unit_changed';
    from: string | null;
    to: string | null;
    reason: string | null;
    actor: string | null;
    occurredAt: string;
};

export default function ProfessionalShow({
    professional,
    history,
    canUpdate,
    access,
    qualifications,
    qualificationOptions,
}: {
    professional: Professional;
    history: HistoryEntry[];
    canUpdate: boolean;
    access: { linked: boolean; canInvite: boolean; hasEmail: boolean };
    qualifications: Qualification[];
    qualificationOptions: QualificationOptions | null;
}) {
    return (
        <>
            <Head title={professional.name} />
            <Page>
                <PageHeader
                    eyebrow="Profissional"
                    title={professional.name}
                    actions={
                        canUpdate && (
                            <Button
                                variant="outline"
                                asChild
                                className="min-h-11"
                            >
                                <Link href={edit(professional.id)}>
                                    <Pencil aria-hidden="true" />
                                    Editar profissional
                                </Link>
                            </Button>
                        )
                    }
                />

                <section
                    aria-labelledby="access-heading"
                    className="mb-8 rounded-lg border bg-card p-5 text-sm"
                >
                    <h2
                        id="access-heading"
                        className="mb-2 font-semibold tracking-tight"
                    >
                        Acesso ao sistema
                    </h2>
                    {access.linked ? (
                        <p>
                            Com acesso: o profissional vê os próprios plantões
                            publicados em "Meus plantões".
                        </p>
                    ) : access.canInvite ? (
                        <Form
                            {...ProfessionalController.invite.form(
                                professional.id,
                            )}
                            options={{ preserveScroll: true }}
                            className="grid gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <p className="text-muted-foreground">
                                        {access.hasEmail
                                            ? 'O convite usa o e-mail do cadastro. A pessoa define a senha pelo link enviado.'
                                            : 'Cadastre o e-mail do profissional para poder convidar.'}
                                    </p>
                                    <InputError message={errors.access} />
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing || !access.hasEmail
                                        }
                                        className="min-h-11 justify-self-start"
                                    >
                                        Convidar para acessar
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-muted-foreground">Sem acesso.</p>
                    )}
                </section>

                <div className="mb-8">
                    <QualificationsSection
                        professionalId={professional.id}
                        qualifications={qualifications}
                        options={qualificationOptions}
                    />
                </div>

                <div className="grid gap-8 lg:grid-cols-3">
                    <section
                        aria-labelledby="history-heading"
                        className="overflow-hidden rounded-lg border bg-card lg:col-span-2"
                    >
                        <h2
                            id="history-heading"
                            className="flex items-center gap-2 border-b px-5 py-4 font-semibold tracking-tight"
                        >
                            <History
                                className="size-4 text-primary"
                                aria-hidden="true"
                            />
                            Histórico da unidade-base
                        </h2>
                        <ol className="divide-y">
                            {history.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="px-5 py-4 text-sm"
                                >
                                    <p className="font-medium">
                                        {entry.action === 'professional.created'
                                            ? `Cadastrado com base em ${entry.to ?? 'unidade não encontrada'}`
                                            : `${entry.from ?? '—'} → ${entry.to ?? '—'}`}
                                    </p>
                                    {entry.reason && (
                                        <p className="mt-1">
                                            Motivo: {entry.reason}
                                        </p>
                                    )}
                                    <p className="mt-1 text-muted-foreground">
                                        {new Date(
                                            entry.occurredAt,
                                        ).toLocaleString('pt-BR')}
                                        {entry.actor && ` · ${entry.actor}`}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <aside aria-labelledby="details-heading">
                        <h2
                            id="details-heading"
                            className="mb-3 text-sm font-semibold tracking-tight"
                        >
                            Informações
                        </h2>
                        <dl className="divide-y rounded-lg border bg-card text-sm">
                            <Detail
                                label="Unidade-base"
                                value={professional.baseUnit}
                            />
                            <Detail
                                label="Registro"
                                value={councilLabel(professional)}
                            />
                            <Detail
                                label="Identificador externo"
                                value={professional.external_id}
                            />
                            {/* Contact is omitted for destination-only managers. */}
                            {professional.email !== undefined && (
                                <Detail
                                    label="E-mail"
                                    value={professional.email}
                                />
                            )}
                            {professional.phone !== undefined && (
                                <Detail
                                    label="Telefone"
                                    value={professional.phone}
                                />
                            )}
                            {professional.cpf !== undefined && (
                                <Detail label="CPF" value={professional.cpf} />
                            )}
                            {professional.cns !== undefined && (
                                <Detail
                                    label="Cartão Nacional de Saúde"
                                    value={professional.cns}
                                />
                            )}
                        </dl>
                    </aside>
                </div>
            </Page>
        </>
    );
}

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex items-start justify-between gap-3 px-4 py-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium break-all">
                {value ?? 'Não informado'}
            </dd>
        </div>
    );
}

ProfessionalShow.layout = {
    breadcrumbs: [{ title: 'Profissionais', href: index() }],
};
