import { Form } from '@inertiajs/react';
import AppointmentController from '@/actions/App/Http/Controllers/AppointmentController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type AppointmentStatus =
    | 'scheduled'
    | 'arrived'
    | 'in_progress'
    | 'completed'
    | 'cancelled'
    | 'no_show';

export const appointmentStatusLabels: Record<AppointmentStatus, string> = {
    scheduled: 'Agendado',
    arrived: 'Chegou',
    in_progress: 'Em atendimento',
    completed: 'Atendido',
    cancelled: 'Cancelado',
    no_show: 'Faltou',
};

export function AppointmentStatusBadge({
    status,
}: {
    status: AppointmentStatus;
}) {
    const open = status !== 'cancelled' && status !== 'no_show';

    return (
        <span
            className={
                open
                    ? 'rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary'
                    : 'rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground'
            }
        >
            {appointmentStatusLabels[status]}
        </span>
    );
}

/** `context` (patient and time) gives repeated row buttons a distinct accessible name. */
export function NoShowButton({
    appointmentId,
    context,
}: {
    appointmentId: number;
    context: string;
}) {
    return (
        <Form
            {...AppointmentController.noShow.form(appointmentId)}
            options={{ preserveScroll: true }}
        >
            <Button
                type="submit"
                variant="outline"
                aria-label={`Faltou: ${context}`}
                className="min-h-11"
            >
                Faltou
            </Button>
        </Form>
    );
}

/** Cancelling always asks for the reason (step 3.4b). */
export function CancelAppointment({
    appointmentId,
    context,
}: {
    appointmentId: number;
    context: string;
}) {
    const id = `reason-${appointmentId}`;

    return (
        <details>
            <summary
                aria-label={`Cancelar: ${context}`}
                className="inline-flex min-h-11 cursor-pointer items-center rounded-md px-3 text-muted-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                Cancelar
            </summary>
            <Form
                {...AppointmentController.cancel.form(appointmentId)}
                options={{ preserveScroll: true }}
                className="mt-2 flex flex-wrap items-end gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid content-start gap-2">
                            <Label htmlFor={id}>Motivo do cancelamento</Label>
                            <Input
                                id={id}
                                name="reason"
                                required
                                maxLength={255}
                                className="h-11"
                            />
                            <InputError message={errors.reason} />
                        </div>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                            aria-label={`Confirmar cancelamento: ${context}`}
                            className="min-h-11"
                        >
                            Confirmar cancelamento
                        </Button>
                    </>
                )}
            </Form>
        </details>
    );
}
