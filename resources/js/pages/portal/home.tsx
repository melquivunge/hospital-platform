import { Head } from '@inertiajs/react';

export default function PortalHome({ patient }: { patient: { name: string } }) {
    return (
        <>
            <Head title="Portal do paciente" />
            <h1 className="text-2xl font-semibold tracking-tight">
                Olá, {patient.name.split(' ')[0]}
            </h1>
            <p className="mt-2 text-sm text-muted-foreground">
                Seu acesso ao portal está ativo. Em breve você verá aqui seus
                resultados de exame, suas próximas consultas e os documentos
                liberados pela equipe.
            </p>
        </>
    );
}
