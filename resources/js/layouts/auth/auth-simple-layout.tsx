import { Link } from '@inertiajs/react';
import { Activity, ShieldCheck } from 'lucide-react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="min-h-svh bg-[#f5f6f4] text-[#172d36] lg:grid lg:grid-cols-[0.9fr_1.1fr]">
            <aside className="hidden flex-col justify-between bg-[#132f39] p-12 text-white lg:flex xl:p-16">
                <div className="flex items-center gap-3 text-sm font-semibold tracking-wide">
                    <Activity className="size-7 text-[#8ed0c1]" /> PLATAFORMA
                    HOSPITALAR
                </div>
                <div className="max-w-md">
                    <div className="mb-8 h-px w-14 bg-[#8ed0c1]" />
                    <p className="text-4xl leading-tight font-medium xl:text-5xl">
                        Mais clareza para
                        <br />
                        cada dia de cuidado.
                    </p>
                    <p className="mt-6 max-w-sm text-base leading-relaxed text-[#c2d2d7]">
                        Seu espaço de trabalho. A organização que sua equipe
                        precisa, em um só lugar.
                    </p>
                </div>
                <p className="text-xs text-[#c2d2d7]">
                    Gestão hospitalar · Ambiente de desenvolvimento
                </p>
            </aside>
            <main className="flex min-h-svh flex-col items-center justify-center px-6 py-12 sm:px-12">
                <div className="w-full max-w-sm">
                    <Link
                        href={home()}
                        className="mb-12 inline-flex items-center gap-3 rounded-sm text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700"
                    >
                        <Activity className="size-6 text-teal-700" /> Plataforma
                        Hospitalar
                    </Link>
                    <div className="mb-8">
                        <p className="mb-3 text-xs font-semibold tracking-[0.16em] text-teal-800 uppercase">
                            Acesso à plataforma
                        </p>
                        <h1 className="text-3xl font-semibold tracking-tight">
                            {title}
                        </h1>
                        <p className="mt-3 text-sm leading-relaxed text-[#54666d]">
                            {description}
                        </p>
                    </div>
                    {children}
                    <div className="mt-9 border-t border-[#dce3e1] pt-6">
                        <p className="flex items-center gap-2 text-xs text-[#54666d]">
                            <ShieldCheck className="size-4" /> Acesso exclusivo
                            para usuários autorizados.
                        </p>
                        <p className="mt-3 text-xs leading-relaxed text-[#54666d]">
                            Precisa de acesso? Entre em contato com o
                            administrador da sua instituição.
                        </p>
                    </div>
                </div>
            </main>
        </div>
    );
}
