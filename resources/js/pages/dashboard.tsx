import { Head } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { dashboard } from '@/routes';

export default function Dashboard() {
    return (
        <>
            <Head title="Visão geral" />
            <main className="p-6 md:p-10">
                <p className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                    Seu espaço de trabalho
                </p>
                <h1 className="mt-3 text-3xl font-semibold tracking-tight">
                    Visão geral
                </h1>
                <section className="mt-10 max-w-xl rounded-xl border bg-card p-8">
                    <Building2 className="size-8 text-teal-700" />
                    <h2 className="mt-5 text-lg font-semibold">
                        Sua estrutura começa aqui
                    </h2>
                    <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                        O acesso à plataforma está configurado. Os cadastros de
                        instituições, permissões e escalas serão
                        disponibilizados nas próximas etapas.
                    </p>
                    <p className="mt-5 text-xs font-medium text-muted-foreground">
                        Ambiente inicial · Sem dados hospitalares
                    </p>
                </section>
            </main>
        </>
    );
}
Dashboard.layout = {
    breadcrumbs: [{ title: 'Visão geral', href: dashboard() }],
};
