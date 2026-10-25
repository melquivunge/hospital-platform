import { Form, Head, Link } from '@inertiajs/react';
import SurgeryRecordController from '@/actions/App/Http/Controllers/SurgeryRecordController';
import SurgeryScheduleController from '@/actions/App/Http/Controllers/SurgeryScheduleController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/surgery-schedule';

type Option = { id: number; name: string };

type Surgery = {
    id: number;
    patient: string | null;
    place: string | null;
    procedure: string;
    laterality: string;
    urgency: string;
    status: 'requested' | 'scheduled' | 'in_progress' | 'cancelled';
    startedAt: string | null;
    desiredDate: string;
    author: string | null;
    room: string | null;
    roomId: number | null;
    start: string | null;
    end: string | null;
    startInput: string | null;
    durationMinutes: number | null;
    scheduledBy: string | null;
    urgentReason: string | null;
    cancellationReason: string | null;
    cancelledBy: string | null;
    team: { id: number; name: string; council: string | null; role: string }[];
    reschedules: {
        id: number;
        room: string | null;
        start: string;
        reason: string;
        by: string | null;
        at: string;
    }[];
};

function Select({
    id,
    name,
    label,
    options,
    defaultValue,
    placeholder,
    error,
    required = true,
}: {
    id: string;
    name: string;
    label: string;
    options: [string | number, string][];
    defaultValue?: string | number | null;
    placeholder?: string;
    error?: string;
    required?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                name={name}
                required={required}
                defaultValue={defaultValue ?? ''}
                className={selectClassName}
            >
                {placeholder !== undefined && (
                    <option value="" disabled={required}>
                        {placeholder}
                    </option>
                )}
                {options.map(([value, text]) => (
                    <option key={value} value={value}>
                        {text}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}

/** Room, start and length of a booking, shared by schedule and reschedule. */
function BookingFields({
    prefix,
    surgery,
    rooms,
    now,
    errors,
}: {
    prefix: string;
    surgery: Surgery;
    rooms: Option[];
    now: string;
    errors: Record<string, string | undefined>;
}) {
    return (
        <>
            <Select
                id={`${prefix}-room`}
                name="operating_room_id"
                label="Sala"
                placeholder="Escolha a sala"
                options={rooms.map((room) => [room.id, room.name])}
                defaultValue={surgery.roomId}
                error={errors.operating_room_id}
            />
            <div className="grid gap-2">
                <Label htmlFor={`${prefix}-start`}>Início</Label>
                <Input
                    id={`${prefix}-start`}
                    name="start"
                    type="datetime-local"
                    required
                    min={
                        prefix === 'schedule' && surgery.urgency !== 'urgent'
                            ? now
                            : undefined
                    }
                    defaultValue={surgery.startInput ?? undefined}
                    className="h-11"
                />
                <InputError message={errors.start} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${prefix}-duration`}>Duração (minutos)</Label>
                <Input
                    id={`${prefix}-duration`}
                    name="duration_minutes"
                    type="number"
                    required
                    min={5}
                    max={1440}
                    defaultValue={surgery.durationMinutes ?? undefined}
                    className="h-11"
                />
                <InputError message={errors.duration_minutes} />
            </div>
        </>
    );
}

/**
 * One surgery on the agenda (step 5b.3a, ADR-0009): the surgical center books the room, the start, the length and
 * the surgeon; reschedules and cancels with a reason; adds and removes team members. No clinical indication here.
 */
export default function SurgeryScheduleShow({
    surgery,
    rooms,
    professionals,
    roles,
    lateralities,
    urgencies,
    now,
}: {
    surgery: Surgery;
    rooms: Option[];
    professionals: Option[];
    roles: Record<string, string>;
    lateralities: Record<string, string>;
    urgencies: Record<string, string>;
    now: string;
}) {
    const people = professionals.map(
        (professional) =>
            [professional.id, professional.name] as [number, string],
    );
    const statusText = {
        requested: 'Aguardando agendamento',
        scheduled: `Agendada: ${surgery.room}, ${surgery.start} – ${surgery.end}`,
        in_progress: `Em andamento desde ${surgery.startedAt}: ${surgery.room}, ${surgery.start} – ${surgery.end}`,
        cancelled: `Cancelada: ${surgery.cancellationReason}`,
    }[surgery.status];

    return (
        <>
            <Head title="Cirurgia" />
            <Page>
                <PageHeader
                    eyebrow={surgery.patient ?? undefined}
                    title={
                        surgery.laterality === 'none'
                            ? surgery.procedure
                            : `${surgery.procedure} · ${lateralities[surgery.laterality]}`
                    }
                    description={[
                        urgencies[surgery.urgency],
                        surgery.place,
                        `desejada para ${surgery.desiredDate}`,
                        `pedida por ${surgery.author}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {['requested', 'scheduled', 'in_progress'].includes(
                                surgery.status,
                            ) && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link
                                        href={SurgeryRecordController.show.url(
                                            surgery.id,
                                        )}
                                    >
                                        Abrir prontuário
                                    </Link>
                                </Button>
                            )}
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <Link href={index().url}>Voltar à agenda</Link>
                            </Button>
                        </div>
                    }
                />

                <p
                    role="status"
                    className={`mb-6 rounded-xl border px-5 py-4 font-medium ${surgery.status === 'cancelled' ? 'text-muted-foreground' : 'bg-card'}`}
                >
                    {statusText}
                    {surgery.urgentReason && (
                        <span className="block text-sm font-normal text-muted-foreground">
                            Motivo da urgência: {surgery.urgentReason}
                        </span>
                    )}
                </p>

                {surgery.status === 'requested' && (
                    <section className="mb-8 grid gap-3">
                        <h2 className="text-lg font-semibold">Agendar</h2>
                        <Form
                            {...SurgeryScheduleController.schedule.form(
                                surgery.id,
                            )}
                            errorBag="schedule"
                            options={{ preserveScroll: true }}
                            className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <BookingFields
                                        prefix="schedule"
                                        surgery={surgery}
                                        rooms={rooms}
                                        now={now}
                                        errors={errors}
                                    />
                                    <Select
                                        id="surgeon"
                                        name="surgeon_id"
                                        label="Cirurgião"
                                        placeholder="Escolha o cirurgião"
                                        options={people}
                                        error={errors.surgeon_id}
                                    />
                                    <Select
                                        id="anesthetist"
                                        name="anesthetist_id"
                                        label="Anestesista (opcional)"
                                        placeholder="Depois"
                                        required={false}
                                        options={people}
                                        error={errors.anesthetist_id}
                                    />
                                    {surgery.urgency === 'urgent' && (
                                        <div className="grid gap-2 sm:col-span-2">
                                            <Label htmlFor="urgent_reason">
                                                Motivo da urgência
                                            </Label>
                                            <Input
                                                id="urgent_reason"
                                                name="urgent_reason"
                                                required
                                                maxLength={500}
                                                className="h-11"
                                            />
                                            <InputError
                                                message={errors.urgent_reason}
                                            />
                                        </div>
                                    )}
                                    <div className="sm:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing || rooms.length === 0
                                            }
                                            className="min-h-11"
                                        >
                                            Agendar
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                {(surgery.status === 'scheduled' ||
                    surgery.status === 'in_progress') && (
                    <>
                        <section className="mb-8 grid gap-3">
                            <h2 className="text-lg font-semibold">Equipe</h2>
                            <ul className="divide-y rounded-xl border bg-card">
                                {surgery.team.map((member) => (
                                    <li
                                        key={member.id}
                                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                                    >
                                        <span className="grid gap-0.5">
                                            <span className="font-medium">
                                                {member.name}
                                            </span>
                                            <span className="text-sm text-muted-foreground">
                                                {roles[member.role]}
                                                {member.council &&
                                                    ` · ${member.council}`}
                                            </span>
                                        </span>
                                        <Form
                                            {...SurgeryScheduleController.removeMember.form(
                                                member.id,
                                            )}
                                            errorBag={`member${member.id}`}
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing, errors }) => (
                                                <div className="grid justify-items-end gap-1">
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        disabled={processing}
                                                        aria-label={`Retirar ${member.name} da equipe`}
                                                        className="min-h-11"
                                                    >
                                                        Retirar
                                                    </Button>
                                                    <InputError
                                                        message={errors.member}
                                                    />
                                                </div>
                                            )}
                                        </Form>
                                    </li>
                                ))}
                            </ul>
                            <Form
                                {...SurgeryScheduleController.addMember.form(
                                    surgery.id,
                                )}
                                errorBag="team"
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Select
                                            id="member"
                                            name="professional_id"
                                            label="Profissional"
                                            placeholder="Escolha"
                                            options={people}
                                            error={errors.professional_id}
                                        />
                                        <Select
                                            id="member-role"
                                            name="role"
                                            label="Função na equipe"
                                            placeholder="Escolha"
                                            options={Object.entries(roles)}
                                            error={errors.role}
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            className="min-h-11 sm:mt-7"
                                        >
                                            Incluir
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </section>

                        {surgery.status === 'scheduled' && (
                            <section className="mb-8 grid gap-3">
                                <h2 className="text-lg font-semibold">
                                    Reagendar
                                </h2>
                                <Form
                                    {...SurgeryScheduleController.reschedule.form(
                                        surgery.id,
                                    )}
                                    errorBag="reschedule"
                                    options={{ preserveScroll: true }}
                                    className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <BookingFields
                                                prefix="reschedule"
                                                surgery={surgery}
                                                rooms={rooms}
                                                now={now}
                                                errors={errors}
                                            />
                                            <div className="grid gap-2">
                                                <Label htmlFor="reschedule-reason">
                                                    Motivo
                                                </Label>
                                                <Input
                                                    id="reschedule-reason"
                                                    name="reason"
                                                    required
                                                    maxLength={500}
                                                    className="h-11"
                                                />
                                                <InputError
                                                    message={errors.reason}
                                                />
                                            </div>
                                            <div className="sm:col-span-2">
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                    className="min-h-11"
                                                >
                                                    Reagendar
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </section>
                        )}
                    </>
                )}

                {(surgery.status === 'requested' ||
                    surgery.status === 'scheduled') && (
                    <section className="mb-8 grid gap-3">
                        <h2 className="text-lg font-semibold">Cancelar</h2>
                        <Form
                            {...SurgeryScheduleController.cancel.form(
                                surgery.id,
                            )}
                            errorBag="cancel"
                            options={{ preserveScroll: true }}
                            className="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-[1fr_auto] sm:items-start"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="cancel-reason">
                                            Motivo do cancelamento
                                        </Label>
                                        <Input
                                            id="cancel-reason"
                                            name="reason"
                                            required
                                            maxLength={500}
                                            className="h-11"
                                        />
                                        <InputError message={errors.reason} />
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        className="min-h-11 sm:mt-7"
                                    >
                                        Cancelar cirurgia
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                {surgery.reschedules.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">
                            Agendamentos anteriores
                        </h2>
                        <ul className="divide-y rounded-xl border bg-card">
                            {surgery.reschedules.map((change) => (
                                <li
                                    key={change.id}
                                    className="px-5 py-3 text-sm"
                                >
                                    <span className="font-medium">
                                        {change.room}, {change.start}
                                    </span>
                                    <span className="block text-muted-foreground">
                                        Reagendada em {change.at} por{' '}
                                        {change.by}: {change.reason}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </Page>
        </>
    );
}
