import { Form, Head } from '@inertiajs/react';
import PrescriptionSignatureController from '@/actions/App/Http/Controllers/PrescriptionSignatureController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/certificate';

type Props = {
    provider: { label: string; qualified: boolean } | null;
    professional: { name: string; hasCpf: boolean } | null;
    linkedAt: string | null;
};

/** Step 4b.5 (ADR-0006): the doctor links the certificate signer once, to sign prescriptions with it. */
export default function Certificate({
    provider,
    professional,
    linkedAt,
}: Props) {
    return (
        <>
            <Head title="Certificado digital" />

            <h1 className="sr-only">Certificado digital</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Certificado digital"
                    description="Assine suas prescrições com o certificado em nuvem. Sem ele, a prescrição é assinada com a sua senha (assinatura eletrônica simples)."
                />

                {provider === null ? (
                    <p className="text-sm text-muted-foreground">
                        A assinatura com certificado ainda não está disponível
                        nesta rede.
                    </p>
                ) : professional === null ? (
                    <p className="text-sm text-muted-foreground">
                        Só profissionais cadastrados nesta rede vinculam
                        certificado.
                    </p>
                ) : !professional.hasCpf ? (
                    <p className="text-sm text-muted-foreground">
                        Seu cadastro de profissional não tem CPF. Peça à
                        administração para incluí-lo: o titular do certificado
                        precisa ter o mesmo CPF.
                    </p>
                ) : (
                    <div className="grid gap-4">
                        <p className="text-sm">
                            Assinador: {provider.label}.
                            {linkedAt
                                ? ` Vinculado em ${linkedAt}.`
                                : ' Ainda não vinculado.'}
                        </p>
                        {!provider.qualified && (
                            <p className="text-sm text-muted-foreground">
                                Ambiente de teste: as assinaturas não têm
                                validade jurídica.
                            </p>
                        )}
                        <Form
                            {...(linkedAt
                                ? PrescriptionSignatureController.unlink.form()
                                : PrescriptionSignatureController.link.form())}
                            options={{ preserveScroll: true }}
                            className="grid justify-items-start gap-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Button
                                        type="submit"
                                        variant={
                                            linkedAt ? 'outline' : 'default'
                                        }
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        {linkedAt
                                            ? 'Desvincular certificado'
                                            : 'Vincular certificado'}
                                    </Button>
                                    <InputError message={errors.certificate} />
                                </>
                            )}
                        </Form>
                    </div>
                )}
            </div>
        </>
    );
}

Certificate.layout = {
    breadcrumbs: [
        {
            title: 'Certificado digital',
            href: edit(),
        },
    ],
};
