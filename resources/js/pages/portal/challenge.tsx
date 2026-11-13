import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import PortalAuthController from '@/actions/App/Http/Controllers/Portal/PortalAuthController';
import PortalTwoFactorController from '@/actions/App/Http/Controllers/Portal/PortalTwoFactorController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/**
 * Step 7a.1c: the second step of signing in, with the app's code or a recovery code.
 */
export default function PortalChallenge({
    network,
}: {
    network: { name: string; slug: string };
}) {
    const [recovery, setRecovery] = useState(false);
    const field = recovery ? 'recovery_code' : 'code';

    return (
        <>
            <Head title="Código de verificação" />
            <div className="mx-auto max-w-sm">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {recovery
                        ? 'Código de recuperação'
                        : 'Código do aplicativo'}
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    {recovery
                        ? 'Digite um dos códigos de recuperação que você guardou. Cada um vale uma vez.'
                        : 'Abra o aplicativo autenticador e digite o código de 6 dígitos da sua conta no portal.'}
                </p>

                <Form
                    {...PortalTwoFactorController.challenge.form(network.slug)}
                    resetOnError
                    className="mt-8 grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={field}>
                                    {recovery
                                        ? 'Código de recuperação'
                                        : 'Código'}
                                </Label>
                                <Input
                                    key={field}
                                    id={field}
                                    name={field}
                                    inputMode={recovery ? 'text' : 'numeric'}
                                    autoComplete="one-time-code"
                                    autoCapitalize="off"
                                    spellCheck={false}
                                    required
                                    autoFocus
                                    aria-invalid={!!errors.code}
                                    aria-describedby={
                                        errors.code ? 'code-error' : undefined
                                    }
                                    className="h-11 tracking-[0.2em] tabular-nums"
                                />
                                <InputError
                                    id="code-error"
                                    message={errors.code}
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
                    <button
                        type="button"
                        onClick={() => setRecovery(!recovery)}
                        className="min-h-11 font-medium text-primary underline-offset-4 hover:underline"
                    >
                        {recovery
                            ? 'Usar o código do aplicativo'
                            : 'Sem acesso ao aplicativo? Usar um código de recuperação'}
                    </button>
                </p>
                <p className="mt-2 text-sm">
                    <Link
                        href={PortalAuthController.showLogin.url(network.slug)}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Voltar para o início
                    </Link>
                </p>
            </div>
        </>
    );
}
