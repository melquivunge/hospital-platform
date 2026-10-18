import { Form, Head } from '@inertiajs/react';
import VacancyController from '@/actions/App/Http/Controllers/VacancyController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDay, PeriodLabel, timeRange } from '@/pages/shifts/index';
import type { Vacancy } from '@/pages/vacancies/index';
import { index } from '@/routes/vacancies';

/** Vacancy details with apply / withdraw (references IMG_0928 to 0932). */
export default function VacancyShow({
    vacancy,
    canApply,
    canWithdraw,
}: {
    vacancy: Vacancy;
    canApply: boolean;
    canWithdraw: boolean;
}) {
    return (
        <>
            <Head title="Detalhes da vaga" />
            <Page width="narrow">
                <PageHeader eyebrow={vacancy.unit} title="Detalhes da vaga" />

                <section
                    aria-labelledby="vacancy-when"
                    className="mb-4 rounded-lg border bg-card p-4 text-sm"
                >
                    <h2
                        id="vacancy-when"
                        className="mb-3 border-b pb-2 font-semibold"
                    >
                        Dia e horário
                    </h2>
                    <dl className="grid gap-3">
                        <Detail label="Data">
                            <span className="first-letter:uppercase">
                                {formatDay(vacancy.date)}
                            </span>
                        </Detail>
                        <Detail label="Horário">{timeRange(vacancy)}</Detail>
                        <Detail label="Turno">
                            <PeriodLabel period={vacancy.period} />
                        </Detail>
                    </dl>
                </section>

                <section
                    aria-labelledby="vacancy-where"
                    className="mb-6 rounded-lg border bg-card p-4 text-sm"
                >
                    <h2
                        id="vacancy-where"
                        className="mb-3 border-b pb-2 font-semibold"
                    >
                        Local e função
                    </h2>
                    <dl className="grid gap-3">
                        <Detail label="Unidade">{vacancy.unit}</Detail>
                        <Detail label="Setor">{vacancy.sector}</Detail>
                        <Detail label="Função">{vacancy.function}</Detail>
                        {vacancy.application && (
                            <Detail label="Sua candidatura">
                                {vacancy.application.label}
                            </Detail>
                        )}
                    </dl>
                </section>

                {canApply && (
                    <Form
                        {...VacancyController.apply.form(vacancy.id)}
                        options={{ preserveScroll: true }}
                        className="grid gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <InputError
                                    message={
                                        errors.professional_id ??
                                        errors.application ??
                                        errors.shift ??
                                        errors.starts_at
                                    }
                                />
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    className="min-h-11 w-full"
                                >
                                    {processing && <Spinner />}
                                    Candidate-se
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                {canWithdraw && (
                    <Form
                        {...VacancyController.withdraw.form(vacancy.id)}
                        options={{ preserveScroll: true }}
                        className="grid gap-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <InputError message={errors.application} />
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                    className="min-h-11 w-full"
                                >
                                    {processing && <Spinner />}
                                    Retirar-se
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </Page>
        </>
    );
}

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{children}</dd>
        </div>
    );
}

VacancyShow.layout = {
    breadcrumbs: [{ title: 'Vagas', href: index() }],
};
