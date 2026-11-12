import { Form, Head, Link } from '@inertiajs/react';
import PortalAuthController from '@/actions/App/Http/Controllers/Portal/PortalAuthController';
import PortalSignupController from '@/actions/App/Http/Controllers/Portal/PortalSignupController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export default function PortalSignup({
    network,
}: {
    network: { name: string; slug: string };
}) {
    return (
        <>
            <Head title="Primeiro acesso ao portal" />
            <div className="mx-auto max-w-sm">
                <h1 className="text-2xl font-semibold tracking-tight">
                    Primeiro acesso
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Informe o CPF e a data de nascimento do seu cadastro na{' '}
                    {network.name}. Se os dados conferirem, enviamos um código
                    para o e-mail do cadastro.
                </p>

                <Form
                    {...PortalSignupController.start.form(network.slug)}
                    className="mt-8 grid gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="cpf">CPF</Label>
                                <Input
                                    id="cpf"
                                    name="cpf"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    required
                                    autoFocus
                                    placeholder="000.000.000-00"
                                    aria-invalid={!!errors.cpf}
                                    aria-describedby={
                                        errors.cpf ? 'cpf-error' : undefined
                                    }
                                    className="h-11"
                                />
                                <InputError
                                    id="cpf-error"
                                    message={errors.cpf}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="birth_date">
                                    Data de nascimento
                                </Label>
                                <Input
                                    id="birth_date"
                                    name="birth_date"
                                    type="date"
                                    required
                                    autoComplete="bday"
                                    aria-invalid={!!errors.birth_date}
                                    className="h-11"
                                />
                                <InputError message={errors.birth_date} />
                            </div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                {processing && <Spinner />}
                                Enviar código
                            </Button>
                        </>
                    )}
                </Form>

                <p className="mt-6 text-sm text-muted-foreground">
                    Sem e-mail no cadastro ou menor de idade? Procure a recepção
                    da unidade.
                </p>
                <p className="mt-4 text-sm">
                    <Link
                        href={PortalAuthController.showLogin.url(network.slug)}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Já tenho senha
                    </Link>
                </p>
            </div>
        </>
    );
}
