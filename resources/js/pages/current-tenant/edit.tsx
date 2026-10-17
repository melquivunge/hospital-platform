import { Form, Head } from '@inertiajs/react';
import { Building2, ChevronRight } from 'lucide-react';
import CurrentTenantController from '@/actions/App/Http/Controllers/CurrentTenantController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { edit } from '@/routes/current-tenant';

type TenantOption = { id: number; name: string };

export default function SelectTenant({
    tenants,
    currentTenantId,
}: {
    tenants: TenantOption[];
    currentTenantId: number | null;
}) {
    return (
        <>
            <Head title="Escolher instituição" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Acesso"
                    title="Escolher instituição"
                    description="Sua conta tem acesso a mais de uma instituição. Os dados exibidos dependem da escolhida."
                />

                <Form {...CurrentTenantController.update.form()}>
                    {({ processing, errors }) => (
                        <>
                            <ul className="divide-y overflow-hidden rounded-lg border bg-card">
                                {tenants.map((tenant) => {
                                    const isCurrent =
                                        tenant.id === currentTenantId;

                                    return (
                                        <li key={tenant.id}>
                                            <button
                                                type="submit"
                                                name="tenant_id"
                                                value={tenant.id}
                                                disabled={processing}
                                                aria-describedby={
                                                    errors.tenant_id
                                                        ? 'tenant-error'
                                                        : undefined
                                                }
                                                className="group flex min-h-16 w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset disabled:opacity-60"
                                            >
                                                <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-accent text-primary">
                                                    <Building2
                                                        className="size-5"
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate font-medium">
                                                        {tenant.name}
                                                    </span>
                                                    {isCurrent && (
                                                        <span className="text-xs text-muted-foreground">
                                                            Instituição atual
                                                        </span>
                                                    )}
                                                </span>
                                                <ChevronRight
                                                    className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                            <InputError
                                id="tenant-error"
                                message={errors.tenant_id}
                                className="mt-3"
                            />
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

SelectTenant.layout = {
    breadcrumbs: [{ title: 'Escolher instituição', href: edit() }],
};
