import { Form, Head, Link } from '@inertiajs/react';
import PortalHomeController from '@/actions/App/Http/Controllers/Portal/PortalHomeController';
import PortalMyDataController from '@/actions/App/Http/Controllers/Portal/PortalMyDataController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Data = {
    name: string;
    socialName: string | null;
    birthDate: string | null;
    cpf: string | null;
    cns: string | null;
    motherName: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
};

function formatCpf(cpf: string) {
    return cpf.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
}

/**
 * Step 7a.3b: the patient's registration data, read only, and the request to correct it.
 */
export default function PortalMyData({
    network,
    data,
    requests,
    status,
}: {
    network: { name: string; slug: string };
    data: Data;
    requests: {
        id: number;
        message: string;
        createdAt: string;
        resolved: boolean;
    }[];
    status?: string;
}) {
    const rows: [string, string | null][] = [
        ['Nome', data.name],
        ['Nome social', data.socialName],
        [
            'Data de nascimento',
            data.birthDate &&
                new Intl.DateTimeFormat('pt-BR', {
                    dateStyle: 'long',
                    timeZone: 'UTC',
                }).format(new Date(data.birthDate)),
        ],
        ['CPF', data.cpf && formatCpf(data.cpf)],
        ['Cartão SUS', data.cns],
        ['Nome da mãe', data.motherName],
        ['Telefone', data.phone],
        ['E-mail', data.email],
        ['Endereço', data.address],
    ];

    return (
        <>
            <Head title="Meus dados" />
            <p className="text-sm">
                <Link
                    href={PortalHomeController.url(network.slug)}
                    className="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                >
                    Voltar para o início
                </Link>
            </p>
            <h1 className="mt-2 text-2xl font-semibold tracking-tight">
                Meus dados
            </h1>
            <p className="mt-2 text-sm text-muted-foreground">
                Como estão no cadastro da {network.name}.
            </p>

            <div role="status" aria-live="polite">
                {status && (
                    <p className="mt-6 rounded-md border bg-card px-4 py-3 text-sm">
                        {status}
                    </p>
                )}
            </div>

            <dl className="mt-6 grid gap-0 rounded-md border bg-card text-sm">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="grid gap-0.5 border-t px-4 py-3 first:border-t-0"
                    >
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="font-medium break-words">
                            {value ?? 'Não informado'}
                        </dd>
                    </div>
                ))}
            </dl>

            <section aria-labelledby="correction-title" className="mt-10">
                <h2 id="correction-title" className="text-lg font-medium">
                    Pedir uma correção
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    A recepção confere com um documento e corrige o cadastro.
                </p>
                <Form
                    {...PortalMyDataController.store.form(network.slug)}
                    resetOnSuccess
                    className="mt-4 grid gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="message">
                                    O que precisa ser corrigido
                                </Label>
                                <textarea
                                    id="message"
                                    name="message"
                                    required
                                    maxLength={1000}
                                    rows={4}
                                    aria-invalid={!!errors.message}
                                    aria-describedby={
                                        errors.message
                                            ? 'message-error'
                                            : undefined
                                    }
                                    className="rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none md:text-sm"
                                />
                                <InputError
                                    id="message-error"
                                    message={errors.message}
                                />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11 justify-self-start"
                            >
                                {processing && <Spinner />}
                                Enviar pedido
                            </Button>
                        </>
                    )}
                </Form>

                {requests.length > 0 && (
                    <>
                        <h3 className="mt-8 text-sm font-medium">
                            Seus pedidos
                        </h3>
                        <ul className="mt-2 grid gap-2 text-sm">
                            {requests.map((request) => (
                                <li
                                    key={request.id}
                                    className="rounded-md border bg-card px-4 py-3"
                                >
                                    <p className="whitespace-pre-line">
                                        {request.message}
                                    </p>
                                    <p className="mt-1 text-muted-foreground">
                                        {new Intl.DateTimeFormat('pt-BR', {
                                            dateStyle: 'short',
                                        }).format(
                                            new Date(request.createdAt),
                                        )}{' '}
                                        ·{' '}
                                        {request.resolved
                                            ? 'Resolvido pela recepção'
                                            : 'Aguardando a recepção'}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </section>
        </>
    );
}
