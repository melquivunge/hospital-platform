import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

const messages: Record<number, { title: string; description: string }> = {
    403: {
        title: 'Acesso não permitido',
        description:
            'Sua conta não tem permissão para esta área. Se precisar de acesso, fale com o administrador da instituição.',
    },
    404: {
        title: 'Página não encontrada',
        description:
            'O endereço pode estar incorreto ou o conteúdo não estar disponível para a sua instituição.',
    },
    419: {
        title: 'Sessão expirada',
        description:
            'Por segurança, a página ficou inativa por muito tempo. Atualize e tente novamente.',
    },
    503: {
        title: 'Plataforma em manutenção',
        description: 'Voltaremos em instantes. Tente novamente mais tarde.',
    },
};

const fallback = {
    title: 'Algo não saiu como esperado',
    description:
        'Não foi possível concluir a solicitação. Tente novamente em instantes.',
};

export default function ErrorPage({ status }: { status: number }) {
    // Rendered by the exception handler, without shared props: "/" sends guests to login and members home.
    const { title, description } = messages[status] ?? fallback;

    return (
        <>
            <Head title={title} />
            <div className="flex min-h-svh items-center justify-center bg-background px-4 py-12">
                <main className="w-full max-w-md text-center">
                    <span className="mx-auto flex size-14 items-center justify-center rounded-xl bg-sidebar ring-1 ring-signature/30">
                        <AppLogoIcon className="size-7 text-signature" />
                    </span>
                    <p className="mt-8 text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                        Erro {status}
                    </p>
                    <h1 className="mt-3 text-2xl font-semibold tracking-tight text-balance">
                        {title}
                    </h1>
                    <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                    <Button asChild className="mt-8 min-h-11">
                        <Link href={home()}>Voltar ao início</Link>
                    </Button>
                </main>
            </div>
        </>
    );
}
