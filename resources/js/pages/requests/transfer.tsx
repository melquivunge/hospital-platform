import { Form, Head } from '@inertiajs/react';
import ShiftRequestController from '@/actions/App/Http/Controllers/ShiftRequestController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { ShiftBrief } from '@/components/shift-display';
import { ShiftCard } from '@/components/shift-display';
import { mine } from '@/routes/shifts';

/** Direct transfer (step 7a): choose a colleague; they accept, then the coordinator approves. */
export default function TransferCreate({
    shift,
    colleagues,
}: {
    shift: ShiftBrief;
    colleagues: { id: number; name: string; registration: string | null }[];
}) {
    return (
        <>
            <Head title="Transferir plantão" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={shift.unit}
                    title="Transferir plantão"
                    description="O plantão continua com você até o colega aceitar e a coordenação aprovar."
                />

                <ShiftCard shift={shift} />

                <Form
                    {...ShiftRequestController.storeTransfer.form(shift.id)}
                    className="mt-6 grid gap-4 rounded-lg border bg-card p-5"
                >
                    {({ processing, errors }) => {
                        const error =
                            errors.recipient_id ??
                            errors.professional_id ??
                            errors.shift ??
                            errors.starts_at;

                        return (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="recipient_id">Colega</Label>
                                    <select
                                        id="recipient_id"
                                        name="recipient_id"
                                        required
                                        defaultValue=""
                                        aria-invalid={!!error}
                                        aria-describedby={
                                            error
                                                ? 'recipient_id-error'
                                                : undefined
                                        }
                                        className="h-11 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option value="" disabled>
                                            Escolha um colega habilitado
                                        </option>
                                        {colleagues.map((colleague) => (
                                            <option
                                                key={colleague.id}
                                                value={colleague.id}
                                            >
                                                {colleague.name}
                                                {colleague.registration &&
                                                    ` · ${colleague.registration}`}
                                            </option>
                                        ))}
                                    </select>
                                    {colleagues.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            Nenhum colega com acesso e
                                            habilitação aprovada para esta
                                            unidade e função.
                                        </p>
                                    )}
                                    <InputError
                                        id="recipient_id-error"
                                        message={error}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="note">
                                        Mensagem (opcional)
                                    </Label>
                                    <Input
                                        id="note"
                                        name="note"
                                        maxLength={500}
                                        className="h-11"
                                    />
                                    <InputError message={errors.note} />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || colleagues.length === 0
                                    }
                                    className="min-h-11 justify-self-start"
                                >
                                    {processing && <Spinner />}
                                    Solicitar transferência
                                </Button>
                            </>
                        );
                    }}
                </Form>
            </Page>
        </>
    );
}

TransferCreate.layout = {
    breadcrumbs: [{ title: 'Meus plantões', href: mine() }],
};
