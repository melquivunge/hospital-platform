import { Form, Head, Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef } from 'react';
import PortalHomeController from '@/actions/App/Http/Controllers/Portal/PortalHomeController';
import PortalTwoFactorController from '@/actions/App/Http/Controllers/Portal/PortalTwoFactorController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    network: { name: string; slug: string };
    enabled: boolean;
    setup: { qr: string; secret: string } | null;
    recoveryCodes: string[] | null;
    status?: string;
};

/**
 * A form that only asks for the password, for turning the second factor on or off and for new recovery codes.
 */
function PasswordAction({
    id,
    action,
    label,
    variant = 'default',
}: {
    id: string;
    action: { action: string; method: 'post' | 'delete' };
    label: string;
    variant?: 'default' | 'outline' | 'destructive';
}) {
    return (
        <Form {...action} resetOnSuccess resetOnError className="grid gap-3">
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor={id}>Sua senha</Label>
                        <PasswordInput
                            id={id}
                            name="password"
                            required
                            autoComplete="current-password"
                            aria-invalid={!!errors.password}
                            aria-describedby={
                                errors.password ? `${id}-error` : undefined
                            }
                            className="h-11"
                        />
                        <InputError
                            id={`${id}-error`}
                            message={errors.password}
                        />
                    </div>
                    <Button
                        type="submit"
                        variant={variant}
                        disabled={processing}
                        className="min-h-11 justify-self-start"
                    >
                        {processing && <Spinner />}
                        {label}
                    </Button>
                </>
            )}
        </Form>
    );
}

/**
 * Step 7a.1c: the optional second factor of the patient's account.
 */
export default function PortalSecurity({
    network,
    enabled,
    setup,
    recoveryCodes,
    status,
}: Props) {
    const slug = network.slug;
    const codesTitle = useRef<HTMLHeadingElement>(null);

    // The codes appear after a redirect: take the reader to them.
    useEffect(() => {
        if (recoveryCodes) {
            codesTitle.current?.focus();
        }
    }, [recoveryCodes]);

    return (
        <>
            <Head title="Segurança da conta" />
            <p className="text-sm">
                <Link
                    href={PortalHomeController.url(slug)}
                    className="inline-flex min-h-11 items-center font-medium text-primary underline-offset-4 hover:underline"
                >
                    Voltar para o início
                </Link>
            </p>
            <h1 className="mt-2 text-2xl font-semibold tracking-tight">
                Segurança da conta
            </h1>

            <div role="status" aria-live="polite">
                {status && (
                    <p className="mt-6 rounded-md border bg-card px-4 py-3 text-sm">
                        {status}
                    </p>
                )}
            </div>

            {recoveryCodes && (
                <section
                    aria-labelledby="codes-title"
                    className="mt-6 rounded-md border bg-card p-4"
                >
                    <h2
                        id="codes-title"
                        ref={codesTitle}
                        tabIndex={-1}
                        className="font-medium focus:outline-none"
                    >
                        Códigos de recuperação
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Anote ou imprima agora: eles não aparecem de novo. Cada
                        código entra uma vez, se você perder o celular.
                    </p>
                    <ul className="mt-3 grid grid-cols-1 gap-1 font-mono text-sm sm:grid-cols-2">
                        {recoveryCodes.map((code) => (
                            <li key={code}>{code}</li>
                        ))}
                    </ul>
                </section>
            )}

            <section aria-labelledby="two-factor-title" className="mt-8">
                <h2
                    id="two-factor-title"
                    className="flex items-center gap-2 text-lg font-medium"
                >
                    <ShieldCheck
                        className="size-5 text-primary"
                        aria-hidden="true"
                    />
                    Verificação em duas etapas
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {enabled
                        ? 'Ligada. Ao entrar, além da senha, pedimos o código do aplicativo autenticador.'
                        : 'Opcional. Além da senha, pede um código do aplicativo autenticador do seu celular (por exemplo, Google Authenticator ou Microsoft Authenticator).'}
                </p>

                <div className="mt-6 grid gap-8">
                    {enabled && (
                        <>
                            <div>
                                <h3 className="text-sm font-medium">
                                    Novos códigos de recuperação
                                </h3>
                                <p className="mt-1 mb-3 text-sm text-muted-foreground">
                                    Os códigos anteriores deixam de valer.
                                </p>
                                <PasswordAction
                                    id="password-codes"
                                    action={PortalTwoFactorController.regenerate.form(
                                        slug,
                                    )}
                                    label="Criar novos códigos"
                                    variant="outline"
                                />
                            </div>
                            <div>
                                <h3 className="text-sm font-medium">
                                    Desligar
                                </h3>
                                <p className="mt-1 mb-3 text-sm text-muted-foreground">
                                    Depois disso, a senha basta para entrar.
                                </p>
                                <PasswordAction
                                    id="password-off"
                                    action={PortalTwoFactorController.disable.form(
                                        slug,
                                    )}
                                    label="Desligar a verificação"
                                    variant="destructive"
                                />
                            </div>
                        </>
                    )}

                    {!enabled && setup && (
                        <div className="grid gap-4">
                            <ol className="grid list-decimal gap-2 pl-5 text-sm">
                                <li>
                                    No aplicativo autenticador, leia o código QR
                                    abaixo.
                                </li>
                                <li>
                                    Digite o código de 6 dígitos que o
                                    aplicativo mostrar.
                                </li>
                            </ol>
                            <div
                                className="w-fit rounded-md border bg-white p-3"
                                role="img"
                                aria-label="Código QR para o aplicativo autenticador"
                                dangerouslySetInnerHTML={{ __html: setup.qr }}
                            />
                            <p className="text-sm">
                                Não consegue ler? Digite esta chave no
                                aplicativo:{' '}
                                <code className="font-mono break-all">
                                    {setup.secret}
                                </code>
                            </p>
                            <Form
                                {...PortalTwoFactorController.confirm.form(
                                    slug,
                                )}
                                resetOnError
                                className="grid gap-3"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="code">Código</Label>
                                            <Input
                                                id="code"
                                                name="code"
                                                inputMode="numeric"
                                                autoComplete="one-time-code"
                                                required
                                                aria-invalid={!!errors.code}
                                                aria-describedby={
                                                    errors.code
                                                        ? 'code-error'
                                                        : undefined
                                                }
                                                className="h-11 max-w-48 tracking-[0.3em] tabular-nums"
                                            />
                                            <InputError
                                                id="code-error"
                                                message={errors.code}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="min-h-11 justify-self-start"
                                        >
                                            {processing && <Spinner />}
                                            Ligar a verificação
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </div>
                    )}

                    {!enabled && !setup && (
                        <PasswordAction
                            id="password-on"
                            action={PortalTwoFactorController.enable.form(slug)}
                            label="Começar"
                        />
                    )}
                </div>
            </section>
        </>
    );
}
