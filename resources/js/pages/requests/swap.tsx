import { Form, Head } from '@inertiajs/react';
import ShiftRequestController from '@/actions/App/Http/Controllers/ShiftRequestController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import type { ShiftBrief } from '@/components/shift-display';
import {
    formatDay,
    PeriodLabel,
    ShiftCard,
    timeRange,
} from '@/components/shift-display';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { mine } from '@/routes/shifts';

type Option = ShiftBrief & { colleague: string | null };

/**
 * Swap (step 7b): offer this shift for a colleague's shift. The colleague accepts, then the coordination
 * of each unit involved approves; both shifts change hands together.
 */
export default function SwapCreate({
    shift,
    options,
}: {
    shift: ShiftBrief;
    options: Option[];
}) {
    return (
        <>
            <Head title="Trocar plantão" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={shift.unit}
                    title="Trocar plantão"
                    description="Você oferece este plantão em troca do plantão de um colega. Nada muda até o colega aceitar e a coordenação aprovar."
                />

                <ShiftCard shift={shift} />

                <Form
                    {...ShiftRequestController.storeSwap.form(shift.id)}
                    className="mt-6 grid gap-4"
                >
                    {({ processing, errors }) => {
                        const error =
                            errors.counterpart_shift_id ??
                            errors.professional_id ??
                            errors.shift ??
                            errors.starts_at;

                        return (
                            <>
                                <fieldset
                                    className="grid gap-3"
                                    aria-describedby={
                                        error ? 'counterpart-error' : undefined
                                    }
                                >
                                    <legend className="mb-2 text-base font-semibold">
                                        Plantão do colega
                                    </legend>
                                    {options.length === 0 && (
                                        <p className="rounded-lg border bg-card px-4 py-6 text-center text-sm">
                                            Nenhum plantão de colega disponível
                                            para troca com as suas habilitações.
                                        </p>
                                    )}
                                    {options.map((option) => (
                                        <label
                                            key={option.id}
                                            className="flex cursor-pointer gap-3 rounded-lg border bg-card p-4 text-sm has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary/30"
                                        >
                                            <input
                                                type="radio"
                                                name="counterpart_shift_id"
                                                value={option.id}
                                                required
                                                className="mt-1 size-4 accent-primary"
                                            />
                                            <span className="grid flex-1 gap-1">
                                                <span className="font-medium first-letter:uppercase">
                                                    {formatDay(option.date)}
                                                </span>
                                                <span>
                                                    {option.unit} ·{' '}
                                                    {option.sector}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {option.function} ·{' '}
                                                    {option.colleague}
                                                </span>
                                                <span className="flex flex-wrap items-center justify-between gap-2 border-t pt-2">
                                                    <PeriodLabel
                                                        period={option.period}
                                                    />
                                                    <span className="tabular-nums">
                                                        {timeRange(option)}
                                                    </span>
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </fieldset>
                                <InputError
                                    id="counterpart-error"
                                    message={error}
                                />
                                <div className="grid gap-2 rounded-lg border bg-card p-5">
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
                                        processing || options.length === 0
                                    }
                                    className="min-h-11 justify-self-start"
                                >
                                    {processing && <Spinner />}
                                    Solicitar troca
                                </Button>
                            </>
                        );
                    }}
                </Form>
            </Page>
        </>
    );
}

SwapCreate.layout = {
    breadcrumbs: [{ title: 'Meus plantões', href: mine() }],
};
