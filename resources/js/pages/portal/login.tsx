import { Form, Head, Link } from '@inertiajs/react';
import PortalAuthController from '@/actions/App/Http/Controllers/Portal/PortalAuthController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function PortalLogin({
    network,
    status,
}: {
    network: { name: string; slug: string };
    status?: string;
}) {
    return (
        <>
            <Head title="Entrar no portal do paciente" />
            <div className="mx-auto max-w-sm">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Portal do paciente
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Seus resultados e consultas na {network.name}.
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
                    {...PortalAuthController.login.form(network.slug)}
                    resetOnError={['password']}
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
                                    aria-describedby={
                                        errors.email ? 'email-error' : undefined
                                    }
                                    className="h-11"
                                />
                                <InputError
                                    id="email-error"
                                    message={errors.email}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">Senha</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                    className="h-11"
                                />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                {processing && <Spinner />}
                                Entrar
                            </Button>
                        </>
                    )}
                </Form>

                <p className="mt-6 text-sm">
                    <Link
                        href={PortalAuthController.showForgot.url(network.slug)}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Esqueci minha senha
                    </Link>
                </p>
                <p className="mt-4 text-sm text-muted-foreground">
                    Primeiro acesso? Peça o convite na recepção da unidade: ele
                    chega no e-mail do seu cadastro.
                </p>
            </div>
        </>
    );
}
