import { Form, Head } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import CurrentTenantController from '@/actions/App/Http/Controllers/CurrentTenantController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
            <div className="max-w-xl p-4 md:p-8">
                <Heading
                    level={1}
                    title="Escolher instituição"
                    description="Sua conta tem acesso a mais de uma instituição. Os dados exibidos dependem da escolhida."
                />

                <Form
                    {...CurrentTenantController.update.form()}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <ul className="space-y-3">
                                {tenants.map((tenant) => (
                                    <li key={tenant.id}>
                                        <Button
                                            type="submit"
                                            name="tenant_id"
                                            value={tenant.id}
                                            variant="outline"
                                            disabled={processing}
                                            className="min-h-14 w-full justify-start gap-3 text-base"
                                        >
                                            <Building2
                                                className="text-teal-700"
                                                aria-hidden="true"
                                            />
                                            {tenant.name}
                                            {tenant.id === currentTenantId && (
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-auto"
                                                >
                                                    Atual
                                                </Badge>
                                            )}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            <InputError
                                id="tenant-error"
                                message={errors.tenant_id}
                            />
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

SelectTenant.layout = {
    breadcrumbs: [{ title: 'Escolher instituição', href: edit() }],
};
