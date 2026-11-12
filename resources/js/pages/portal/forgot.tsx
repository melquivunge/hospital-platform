import { Form, Head, Link } from '@inertiajs/react';
import PortalAuthController from '@/actions/App/Http/Controllers/Portal/PortalAuthController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function PortalForgot({
    network,
    status,
}: {
    network: { name: string; slug: string };
    status?: string;
}) {
    return (
        <>
            <Head title="Nova senha do portal" />
            <div className="mx-auto max-w-sm">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Criar uma nova senha
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Informe o e-mail do seu cadastro. Se ele tiver acesso ao
                    portal, enviamos um link que vale por 72 horas.
                </p>

                {status && (
                    <p
                        role="status"
                        className="mt-6 rounded-md border bg-card px-4 py-3 text-sm"
                    >
                        {status}
                    </p>
                )}

                <Form
                    {...PortalAuthController.forgot.form(network.slug)}
                    className="mt-8 grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">E-mail</Label>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    aria-invalid={!!errors.email}
                                    className="h-11"
                                />
                                <InputError message={errors.email} />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                {processing && <Spinner />}
                                Enviar link
                            </Button>
                        </>
                    )}
                </Form>

                <p className="mt-6 text-sm">
                    <Link
                        href={PortalAuthController.showLogin.url(network.slug)}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Voltar para entrar
                    </Link>
                </p>
            </div>
        </>
    );
}
