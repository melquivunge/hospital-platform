import { Form, Head, usePage } from '@inertiajs/react';
import { ShieldCheck, ShieldOff } from 'lucide-react';
import PeopleController from '@/actions/App/Http/Controllers/PeopleController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { RoleFields } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { roleLabels } from '@/lib/roles';
import type { Person } from '@/pages/people/index';
import { index } from '@/routes/people';

export default function PersonShow({
    person,
    units,
    unitScopedRoles,
}: {
    person: Person;
    units: { id: number; name: string }[];
    unitScopedRoles: string[];
}) {
    const removeRoleErrors = (
        usePage().props.errors as
            | Record<string, Record<string, string>>
            | undefined
    )?.removeRole;

    return (
        <>
            <Head title={person.name} />
            <Page>
                <PageHeader
                    eyebrow="Pessoa"
                    title={person.name}
                    description={`${person.email} · ${person.active ? 'Acesso ativo' : 'Acesso revogado'}`}
                />

                <div className="grid gap-8 lg:grid-cols-3">
                    <section
                        aria-labelledby="roles-heading"
                        className="overflow-hidden rounded-lg border bg-card lg:col-span-2"
                    >
                        <h2
                            id="roles-heading"
                            className="border-b px-5 py-4 font-semibold tracking-tight"
                        >
                            Papéis
                        </h2>

                        {/* Errors from any "Retirar" form (e.g. last network admin) share one bag. */}
                        <InputError
                            message={removeRoleErrors?.role}
                            className="px-5 pt-4"
                        />

                        {person.roles.length === 0 ? (
                            <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                                Nenhum papel atribuído.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {person.roles.map((role) => (
                                    <li
                                        key={role.id}
                                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                                    >
                                        <div>
                                            <p className="font-medium">
                                                {roleLabels[role.role] ??
                                                    role.role}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {role.unit ??
                                                    'Toda a instituição'}
                                            </p>
                                        </div>
                                        <Form
                                            {...PeopleController.revokeRole.form(
                                                [person.id, role.id],
                                            )}
                                            errorBag="removeRole"
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    disabled={processing}
                                                    aria-label={`Retirar ${roleLabels[role.role] ?? role.role}${role.unit ? ` em ${role.unit}` : ''}`}
                                                    className="min-h-11 text-destructive hover:text-destructive"
                                                >
                                                    Retirar
                                                </Button>
                                            )}
                                        </Form>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {person.active && (
                            <Form
                                {...PeopleController.grantRole.form(person.id)}
                                errorBag="grantRole"
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-4 border-t bg-muted/40 px-5 py-4 sm:grid-cols-2"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <RoleFields
                                            idPrefix="grant"
                                            units={units}
                                            unitScopedRoles={unitScopedRoles}
                                            errors={errors}
                                        />
                                        <div className="sm:col-span-2">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="min-h-11"
                                            >
                                                {processing && <Spinner />}
                                                Conceder papel
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </section>

                    <aside
                        aria-labelledby="access-heading"
                        className="h-fit rounded-lg border bg-card p-5"
                    >
                        <h2
                            id="access-heading"
                            className="flex items-center gap-2 font-semibold tracking-tight"
                        >
                            {person.active ? (
                                <ShieldCheck
                                    className="size-4 text-primary"
                                    aria-hidden="true"
                                />
                            ) : (
                                <ShieldOff
                                    className="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            )}
                            {person.active ? 'Acesso ativo' : 'Acesso revogado'}
                        </h2>
                        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                            {person.active
                                ? 'Revogar remove o acesso a esta instituição no próximo clique da pessoa. O histórico é preservado.'
                                : 'Reativar devolve o acesso com os papéis que a pessoa já tinha.'}
                        </p>
                        <Form
                            {...(person.active
                                ? PeopleController.revoke.form(person.id)
                                : PeopleController.reactivate.form(person.id))}
                            options={{ preserveScroll: true }}
                            className="mt-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <Button
                                        type="submit"
                                        variant={
                                            person.active
                                                ? 'outline'
                                                : 'default'
                                        }
                                        disabled={processing || person.isSelf}
                                        className="min-h-11 w-full"
                                    >
                                        {processing && <Spinner />}
                                        {person.active
                                            ? 'Revogar acesso'
                                            : 'Reativar acesso'}
                                    </Button>
                                    {person.isSelf && (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            Você não pode revogar o próprio
                                            acesso.
                                        </p>
                                    )}
                                    <InputError
                                        message={errors.membership}
                                        className="mt-2"
                                    />
                                </>
                            )}
                        </Form>
                    </aside>
                </div>
            </Page>
        </>
    );
}

PersonShow.layout = {
    breadcrumbs: [{ title: 'Pessoas', href: index() }],
};
