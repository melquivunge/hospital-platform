import { Form, Head, Link } from '@inertiajs/react';
import PortalAuthController from '@/actions/App/Http/Controllers/Portal/PortalAuthController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function PortalSetup({
    network,
    token,
    valid,
}: {
    network: { name: string; slug: string };
    token: string;
    valid: boolean;
}) {
    return (
        <>
            <Head title="Criar senha do portal" />
            <div className="mx-auto max-w-sm">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Criar sua senha
                </h1>

                {!valid ? (
                    <div className="mt-6 grid gap-4 rounded-md border bg-card px-4 py-4 text-sm">
                        <p>Este link venceu ou já foi usado.</p>
                        <p className="text-muted-foreground">
                            Se você já criou a senha, use “Esqueci minha senha”.
                            Se ainda não, peça um novo convite na recepção da
                            unidade.
                        </p>
                        <Link
                            href={PortalAuthController.showForgot.url(
                                network.slug,
                            )}
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Esqueci minha senha
                        </Link>
                    </div>
                ) : (
                    <Form
                        {...PortalAuthController.setup.form({
                            portal: network.slug,
                            token,
                        })}
                        resetOnError
                        className="mt-8 grid gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Esta senha é só do portal do paciente da{' '}
                                    {network.name}.
                                </p>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Nova senha</Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        autoFocus
                                        autoComplete="new-password"
                                        aria-invalid={!!errors.password}
                                        aria-describedby={
                                            errors.password
                                                ? 'password-error'
                                                : undefined
                                        }
                                        className="h-11"
                                    />
                                    <InputError
                                        id="password-error"
                                        message={errors.password}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Repita a senha
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                        className="h-11"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11"
                                >
                                    {processing && <Spinner />}
                                    Criar senha e entrar
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}
