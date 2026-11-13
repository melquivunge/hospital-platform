import { Form } from '@inertiajs/react';
import { QrCode } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';

/** Step 7b.1: makes the arrival's QR (a new one replaces the one before). */
export default function VisitPassButton({
    action,
    label,
}: {
    action: { action: string; method: 'post' };
    label: string;
}) {
    return (
        <Form {...action}>
            {({ processing, errors }) => (
                <>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={processing}
                        className="min-h-11"
                        aria-label={`QR da visita: ${label}`}
                    >
                        <QrCode aria-hidden="true" />
                        QR da visita
                    </Button>
                    <InputError message={errors.pass} />
                </>
            )}
        </Form>
    );
}
