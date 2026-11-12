import { Form, Head, Link } from '@inertiajs/react';
import PortalSignupController from '@/actions/App/Http/Controllers/Portal/PortalSignupController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function PortalSignupCode({
    network,
}: {
    network: { name: string; slug: string };
}) {
    return (
        <>
            <Head title="Código de primeiro acesso" />
            <div className="mx-auto max-w-sm">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Digite o código
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Se os dados conferirem com um cadastro que tenha e-mail,
                    enviamos um código de 6 dígitos para o e-mail do cadastro.
                    Ele vale por 10 minutos.
                </p>

                <Form
                    {...PortalSignupController.verify.form(network.slug)}
                    resetOnError
                    className="mt-8 grid gap-5"
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
                                    autoFocus
                                    aria-invalid={!!errors.code}
                                    aria-describedby={
                                        errors.code ? 'code-error' : undefined
                                    }
                                    className="h-11 tracking-[0.3em] tabular-nums"
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
                                Continuar
                            </Button>
                        </>
                    )}
                </Form>

                <p className="mt-6 text-sm">
                    <Link
                        href={PortalSignupController.show.url(network.slug)}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Pedir um novo código
                    </Link>
                </p>
            </div>
        </>
    );
}
