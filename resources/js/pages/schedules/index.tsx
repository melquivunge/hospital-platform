import { Form, Head, router } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import type { ReactNode } from 'react';
import ScheduleController from '@/actions/App/Http/Controllers/ScheduleController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/schedules';

type Rule = {
    id: number;
    professional_id: number;
    professional: string | null;
    weekday: number;
    start_minute: number;
    end_minute: number;
    slot_minutes: number;
};

type Block = {
    id: number;
    professional: string | null;
    startsAt: string;
    endsAt: string;
    reason: string;
};

const weekdays = ['', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
const weekdayNames = [
    'Segunda',
    'Terça',
    'Quarta',
    'Quinta',
    'Sexta',
    'Sábado',
    'Domingo',
];

function time(minute: number): string {
    return `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
}

/** Outpatient schedule grid (step 3.4a): weekly windows and blocked periods per professional. */
export default function Schedules({
    units,
    unit,
    professionals,
    rules,
    blocks,
}: {
    units: { id: number; name: string }[];
    unit: { id: number; name: string; timezone: string | null };
    professionals: { id: number; name: string }[];
    rules: Rule[];
    blocks: Block[];
}) {
    const byProfessional = professionals
        .map((professional) => ({
            professional,
            rules: rules.filter(
                (rule) => rule.professional_id === professional.id,
            ),
        }))
        .filter((group) => group.rules.length > 0);

    return (
        <>
            <Head title="Agendas" />
            <Page width="narrow">
                <PageHeader
                    eyebrow={unit.name}
                    title="Agendas"
                    description={`Horários de consulta de cada profissional, no fuso da unidade (${unit.timezone ?? 'não definido'}). Para mudar um horário, remova-o e adicione outro.`}
                />

                {units.length > 1 && (
                    <div className="mb-6 grid max-w-sm gap-2">
                        <Label htmlFor="unit">Unidade</Label>
                        <select
                            id="unit"
                            value={unit.id}
                            onChange={(event) =>
                                router.get(
                                    index({
                                        query: { unit: event.target.value },
                                    }).url,
                                )
                            }
                            className={selectClassName}
                        >
                            {units.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                {professionals.length === 0 ? (
                    <div className="rounded-xl border bg-card px-5 py-12 text-center">
                        <CalendarClock
                            className="mx-auto mb-3 size-6 text-primary"
                            aria-hidden="true"
                        />
                        <p className="font-medium">
                            Nenhum profissional habilitado nesta unidade
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            A agenda usa os profissionais com habilitação
                            aprovada aqui.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-8">
                        <Card title="Grade semanal">
                            {byProfessional.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nenhum horário cadastrado.
                                </p>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {byProfessional.map((group) => (
                                        <li
                                            key={group.professional.id}
                                            className="px-4 py-3"
                                        >
                                            <p className="font-medium">
                                                {group.professional.name}
                                            </p>
                                            <ul className="mt-2 grid gap-1">
                                                {group.rules.map((rule) => (
                                                    <li
                                                        key={rule.id}
                                                        className="flex flex-wrap items-center justify-between gap-2 text-sm"
                                                    >
                                                        <span className="tabular-nums">
                                                            <span className="inline-block w-10 font-medium">
                                                                {
                                                                    weekdays[
                                                                        rule
                                                                            .weekday
                                                                    ]
                                                                }
                                                            </span>
                                                            {time(
                                                                rule.start_minute,
                                                            )}
                                                            –
                                                            {time(
                                                                rule.end_minute,
                                                            )}{' '}
                                                            ·{' '}
                                                            {rule.slot_minutes}{' '}
                                                            min por consulta
                                                        </span>
                                                        <Form
                                                            {...ScheduleController.deactivateRule.form(
                                                                [
                                                                    unit.id,
                                                                    rule.id,
                                                                ],
                                                            )}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            <Button
                                                                type="submit"
                                                                variant="ghost"
                                                                className="min-h-11 text-muted-foreground"
                                                            >
                                                                Remover
                                                            </Button>
                                                        </Form>
                                                    </li>
                                                ))}
                                            </ul>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <Form
                                {...ScheduleController.storeRule.form(unit.id)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-4 border-t pt-5"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <p className="text-sm font-medium">
                                            Adicionar horário
                                        </p>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Field
                                                id="professional_id"
                                                label="Profissional"
                                                error={errors.professional_id}
                                            >
                                                <ProfessionalSelect
                                                    id="professional_id"
                                                    professionals={
                                                        professionals
                                                    }
                                                />
                                            </Field>
                                            <Field
                                                id="weekday"
                                                label="Dia da semana"
                                                error={errors.weekday}
                                            >
                                                <select
                                                    id="weekday"
                                                    name="weekday"
                                                    required
                                                    defaultValue=""
                                                    className={selectClassName}
                                                >
                                                    <option value="" disabled>
                                                        Selecione
                                                    </option>
                                                    {weekdayNames.map(
                                                        (name, i) => (
                                                            <option
                                                                key={name}
                                                                value={i + 1}
                                                            >
                                                                {name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </Field>
                                        </div>
                                        <div className="grid gap-4 sm:grid-cols-3">
                                            <Field
                                                id="start"
                                                label="Início"
                                                error={errors.start}
                                            >
                                                <Input
                                                    id="start"
                                                    name="start"
                                                    type="time"
                                                    required
                                                    className="h-11"
                                                />
                                            </Field>
                                            <Field
                                                id="end"
                                                label="Fim"
                                                error={errors.end}
                                            >
                                                <Input
                                                    id="end"
                                                    name="end"
                                                    type="time"
                                                    required
                                                    className="h-11"
                                                />
                                            </Field>
                                            <Field
                                                id="slot_minutes"
                                                label="Consulta (min)"
                                                error={errors.slot_minutes}
                                            >
                                                <Input
                                                    id="slot_minutes"
                                                    name="slot_minutes"
                                                    type="number"
                                                    min={5}
                                                    max={240}
                                                    step={5}
                                                    defaultValue={20}
                                                    required
                                                    className="h-11"
                                                />
                                            </Field>
                                        </div>
                                        <div className="flex justify-end">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="min-h-11"
                                            >
                                                Adicionar horário
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </Card>

                        <Card title="Bloqueios">
                            {blocks.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nenhum bloqueio futuro.
                                </p>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {blocks.map((block) => (
                                        <li
                                            key={block.id}
                                            className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm"
                                        >
                                            <span className="grid gap-0.5">
                                                <span className="font-medium">
                                                    {block.professional}
                                                </span>
                                                <span className="text-muted-foreground tabular-nums">
                                                    {block.startsAt} –{' '}
                                                    {block.endsAt} ·{' '}
                                                    {block.reason}
                                                </span>
                                            </span>
                                            <Form
                                                {...ScheduleController.cancelBlock.form(
                                                    [unit.id, block.id],
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    className="min-h-11 text-muted-foreground"
                                                >
                                                    Cancelar
                                                </Button>
                                            </Form>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <Form
                                {...ScheduleController.storeBlock.form(unit.id)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-4 border-t pt-5"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <p className="text-sm font-medium">
                                            Bloquear período
                                        </p>
                                        <Field
                                            id="block_professional_id"
                                            label="Profissional"
                                            error={errors.professional_id}
                                        >
                                            <ProfessionalSelect
                                                id="block_professional_id"
                                                professionals={professionals}
                                            />
                                        </Field>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Field
                                                id="starts_at"
                                                label="De"
                                                error={errors.starts_at}
                                            >
                                                <Input
                                                    id="starts_at"
                                                    name="starts_at"
                                                    type="datetime-local"
                                                    required
                                                    className="h-11"
                                                />
                                            </Field>
                                            <Field
                                                id="ends_at"
                                                label="Até"
                                                error={errors.ends_at}
                                            >
                                                <Input
                                                    id="ends_at"
                                                    name="ends_at"
                                                    type="datetime-local"
                                                    required
                                                    className="h-11"
                                                />
                                            </Field>
                                        </div>
                                        <Field
                                            id="reason"
                                            label="Motivo"
                                            error={errors.reason}
                                        >
                                            <Input
                                                id="reason"
                                                name="reason"
                                                required
                                                maxLength={255}
                                                placeholder="Férias, congresso..."
                                                className="h-11"
                                            />
                                        </Field>
                                        <div className="flex justify-end">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                                className="min-h-11"
                                            >
                                                Bloquear
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </Card>
                    </div>
                )}
            </Page>
        </>
    );
}

function ProfessionalSelect({
    id,
    professionals,
}: {
    id: string;
    professionals: { id: number; name: string }[];
}) {
    return (
        <select
            id={id}
            name="professional_id"
            required
            defaultValue=""
            className={selectClassName}
        >
            <option value="" disabled>
                Selecione
            </option>
            {professionals.map((professional) => (
                <option key={professional.id} value={professional.id}>
                    {professional.name}
                </option>
            ))}
        </select>
    );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="grid gap-5 rounded-xl border bg-card p-5 sm:p-6">
            <h2 className="font-semibold">{title}</h2>
            {children}
        </section>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

Schedules.layout = {
    breadcrumbs: [{ title: 'Agendas', href: index() }],
};
