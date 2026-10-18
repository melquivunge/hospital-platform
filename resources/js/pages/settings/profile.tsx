import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import { useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import ProfilePhotoController from '@/actions/App/Http/Controllers/Settings/ProfilePhotoController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import { edit } from '@/routes/profile';
import type { Auth } from '@/types';
import { send } from '@/routes/verification';

type PageProps = {
    auth: Auth;
};

const PHOTO_SIZE = 512;

/**
 * Center-crops to a square and re-encodes as JPEG: a light upload, and EXIF data (GPS included)
 * never leaves the device.
 */
async function squarePhoto(file: File): Promise<File> {
    const bitmap = await createImageBitmap(file, {
        imageOrientation: 'from-image',
    });
    const side = Math.min(bitmap.width, bitmap.height);
    const size = Math.min(PHOTO_SIZE, side);
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    canvas
        .getContext('2d')
        ?.drawImage(
            bitmap,
            (bitmap.width - side) / 2,
            (bitmap.height - side) / 2,
            side,
            side,
            0,
            0,
            size,
            size,
        );
    bitmap.close();
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/jpeg', 0.9),
    );
    if (blob === null) {
        throw new Error('encode');
    }

    return new File([blob], 'foto.jpg', { type: 'image/jpeg' });
}

function ProfilePhoto({ user }: { user: Auth['user'] }) {
    const input = useRef<HTMLInputElement>(null);
    const [error, setError] = useState<string>();
    const [busy, setBusy] = useState(false);
    const getInitials = useInitials();

    const choose = async (file: File | undefined) => {
        if (!file) {
            return;
        }
        setError(undefined);
        setBusy(true);
        let photo: File;
        try {
            photo = await squarePhoto(file);
        } catch {
            setBusy(false);
            setError('Não foi possível ler esta imagem. Use JPG, PNG ou WebP.');

            return;
        }
        router.post(
            ProfilePhotoController.update.url(),
            { photo },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) => setError(errors.photo),
                onFinish: () => {
                    setBusy(false);
                    if (input.current) {
                        input.current.value = '';
                    }
                },
            },
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-5">
            <button
                type="button"
                onClick={() => input.current?.click()}
                disabled={busy}
                aria-label="Alterar foto de perfil"
                className="group relative size-24 shrink-0 overflow-hidden rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <Avatar className="size-24 rounded-full">
                    <AvatarImage src={user.avatar ?? undefined} alt="" />
                    <AvatarFallback className="rounded-full bg-primary/10 text-2xl font-medium text-primary">
                        {getInitials(user.name)}
                    </AvatarFallback>
                </Avatar>
                <span className="absolute inset-0 flex items-center justify-center bg-black/40 text-white opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100">
                    <Camera className="size-6" aria-hidden="true" />
                </span>
            </button>
            <div className="grid gap-2">
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        disabled={busy}
                        onClick={() => input.current?.click()}
                    >
                        {busy
                            ? 'Enviando…'
                            : user.avatar
                              ? 'Trocar foto'
                              : 'Escolher foto'}
                    </Button>
                    {user.avatar && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="min-h-11 text-muted-foreground"
                            disabled={busy}
                            onClick={() =>
                                router.delete(
                                    ProfilePhotoController.destroy.url(),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Remover
                        </Button>
                    )}
                </div>
                <p className="text-sm text-muted-foreground">
                    JPG, PNG ou WebP. A foto é recortada em formato quadrado.
                </p>
                <InputError message={error} />
            </div>
            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="sr-only"
                tabIndex={-1}
                aria-hidden="true"
                onChange={(event) => choose(event.target.files?.[0])}
            />
        </div>
    );
}

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Meu perfil" />

            <h1 className="sr-only">Meu perfil</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Foto de perfil"
                    description="Aparece na barra superior para identificar sua conta."
                />
                <ProfilePhoto user={auth.user} />
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Dados pessoais"
                    description="Seu nome e o e-mail usado para entrar."
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nome</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder="Nome completo"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">E-mail</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder="nome@exemplo.com"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            Seu e-mail ainda não foi confirmado.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Reenviar o e-mail de
                                                confirmação.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div
                                                role="status"
                                                className="mt-2 text-sm font-medium text-teal-800 dark:text-teal-300"
                                            >
                                                Enviamos um novo link de
                                                confirmação para o seu e-mail.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Salvar
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Meu perfil',
            href: edit(),
        },
    ],
};
