import { Form, Head, Link } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import PeopleController from '@/actions/App/Http/Controllers/PeopleController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { RoleFields } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { roleLabels } from '@/lib/roles';
import { index, show } from '@/routes/people';

export type Person = {
    id: number;
    name: string;
    email: string;
    active: boolean;
    isSelf: boolean;
    roles: { id: number; role: string; unit: string | null }[];
};

type Options = {
    units: { id: number; name: string }[];
    unitScopedRoles: string[];
};

export function RoleList({ roles }: { roles: Person['roles'] }) {
    if (roles.length === 0) {
        return <span className="text-muted-foreground">Sem papel</span>;
    }

    return (
        <ul className="flex flex-wrap gap-1.5">
            {roles.map((role) => (
                <li
                    key={role.id}
                    className="rounded-full border bg-accent px-2.5 py-0.5 text-xs font-medium text-accent-foreground"
                >
                    {roleLabels[role.role] ?? role.role}
                    {role.unit && (
                        <span className="text-muted-foreground">
                            {' '}
                            · {role.unit}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

export default function PeopleIndex({
    people,
    units,
    unitScopedRoles,
}: { people: Person[] } & Options) {
    return (
        <>
            <Head title="Pessoas" />
            <Page>
                <PageHeader
                    eyebrow="Acesso"
                    title="Pessoas"
                    description="Quem tem acesso à instituição e com quais papéis."
                />

                <div className="grid gap-8 lg:grid-cols-3">
                    <section
                        aria-labelledby="people-heading"
                        className="lg:col-span-2"
                    >
                        <h2 id="people-heading" className="sr-only">
                            Pessoas com acesso
                        </h2>
                        <div className="overflow-hidden rounded-lg border bg-card">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    Pessoas da instituição
                                </caption>
                                <thead className="border-b bg-muted/60 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    <tr>
                                        <th scope="col" className="px-5 py-3">
                                            Pessoa
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden px-5 py-3 md:table-cell"
                                        >
                                            Papéis
                                        </th>
                                        <th
                                            scope="col"
                                            className="w-px px-5 py-3 whitespace-nowrap"
                                        >
                                            Acesso
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {people.map((person) => (
                                        <tr
                                            key={person.id}
                                            className="relative transition-colors hover:bg-muted/50"
                                        >
                                            <td className="px-5 py-4">
                                                <Link
                                                    href={show(person.id)}
                                                    className="font-medium after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:rounded-sm focus-visible:after:ring-2 focus-visible:after:ring-ring focus-visible:after:ring-inset"
                                                >
                                                    {person.name}
                                                    {person.isSelf && (
                                                        <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                                                            (você)
                                                        </span>
                                                    )}
                                                </Link>
                                                <p className="mt-0.5 text-xs break-all text-muted-foreground">
                                                    {person.email}
                                                </p>
                                                <div className="mt-2 md:hidden">
                                                    <RoleList
                                                        roles={person.roles}
                                                    />
                                                </div>
                                            </td>
                                            <td className="hidden px-5 py-4 md:table-cell">
                                                <RoleList
                                                    roles={person.roles}
                                                />
                                            </td>
                                            <td className="px-5 py-4 whitespace-nowrap">
                                                {person.active ? (
                                                    'Ativo'
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Revogado
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section
                        aria-labelledby="invite-heading"
                        className="h-fit overflow-hidden rounded-lg border bg-card"
                    >
                        <h2
                            id="invite-heading"
                            className="flex items-center gap-2 border-b px-5 py-4 font-semibold tracking-tight"
                        >
                            <UserPlus
                                className="size-4 text-primary"
                                aria-hidden="true"
                            />
                            Convidar pessoa
                        </h2>
                        <Form
                            {...PeopleController.store.form()}
                            resetOnSuccess
                            className="grid gap-5 p-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="invite-name">
                                            Nome
                                        </Label>
                                        <Input
                                            id="invite-name"
                                            name="name"
                                            required
                                            maxLength={255}
                                            autoComplete="off"
                                            aria-invalid={!!errors.name}
                                            aria-describedby={
                                                errors.name
                                                    ? 'invite-name-error'
                                                    : undefined
                                            }
                                            className="h-11"
                                        />
                                        <InputError
                                            id="invite-name-error"
                                            message={errors.name}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="invite-email">
                                            E-mail
                                        </Label>
                                        <Input
                                            id="invite-email"
                                            name="email"
                                            type="email"
                                            required
                                            maxLength={255}
                                            autoComplete="off"
                                            aria-invalid={!!errors.email}
                                            aria-describedby={
                                                errors.email
                                                    ? 'invite-email-error'
                                                    : 'invite-email-help'
                                            }
                                            className="h-11"
                                        />
                                        <p
                                            id="invite-email-help"
                                            className="text-xs text-muted-foreground"
                                        >
                                            Quem ainda não tem conta recebe um
                                            link para definir a senha.
                                        </p>
                                        <InputError
                                            id="invite-email-error"
                                            message={errors.email}
                                        />
                                    </div>
                                    <RoleFields
                                        idPrefix="invite"
                                        units={units}
                                        unitScopedRoles={unitScopedRoles}
                                        errors={errors}
                                    />
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="min-h-11"
                                    >
                                        {processing && <Spinner />}
                                        Enviar convite
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                </div>
            </Page>
        </>
    );
}

PeopleIndex.layout = {
    breadcrumbs: [{ title: 'Pessoas', href: index() }],
};
