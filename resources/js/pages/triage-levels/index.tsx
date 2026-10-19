import { Form, Head } from '@inertiajs/react';
import TriageLevelController from '@/actions/App/Http/Controllers/TriageLevelController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Color } from '@/pages/emergency/index';
import { levelClasses } from '@/pages/emergency/index';
import { index } from '@/routes/triage-levels';

type Level = {
    id: number;
    level: number;
    name: string;
    color: Color;
    target_minutes: number;
};

/** The network's risk scale (step 4.2): the admin names each of the 5 levels and sets its target time. */
export default function TriageLevels({ levels }: { levels: Level[] }) {
    return (
        <>
            <Head title="Classificação de risco" />
            <Page width="narrow">
                <PageHeader
                    eyebrow="Rede"
                    title="Classificação de risco"
                    description="Escala de 5 níveis usada na urgência de todas as unidades. A ordem e as cores são fixas; o nome e o tempo-alvo de atendimento são da rede. Os valores iniciais são um ponto de partida e devem ser validados pela equipe clínica."
                />
                <ul className="grid gap-3">
                    {levels.map((level) => (
                        <li
                            key={level.id}
                            className={`rounded-xl border p-4 ${levelClasses[level.color]}`}
                        >
                            <Form
                                {...TriageLevelController.update.form(level.id)}
                                errorBag={`level-${level.level}`}
                                options={{ preserveScroll: true }}
                                className="grid items-end gap-3 sm:grid-cols-[3rem_1fr_10rem_auto]"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <span className="text-2xl font-semibold tabular-nums">
                                            {level.level}
                                        </span>
                                        <div className="grid gap-2">
                                            <Label htmlFor={`name-${level.id}`}>
                                                Nome do nível {level.level}
                                            </Label>
                                            <Input
                                                id={`name-${level.id}`}
                                                name="name"
                                                required
                                                maxLength={50}
                                                defaultValue={level.name}
                                                className="h-11 bg-background text-foreground"
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`target-${level.id}`}
                                            >
                                                Tempo-alvo (min)
                                            </Label>
                                            <Input
                                                id={`target-${level.id}`}
                                                name="target_minutes"
                                                type="number"
                                                required
                                                min={0}
                                                max={1440}
                                                defaultValue={
                                                    level.target_minutes
                                                }
                                                className="h-11 bg-background text-foreground"
                                            />
                                            <InputError
                                                message={errors.target_minutes}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            aria-label={`Salvar nível ${level.level}`}
                                            className="min-h-11"
                                        >
                                            Salvar
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </li>
                    ))}
                </ul>
            </Page>
        </>
    );
}

TriageLevels.layout = {
    breadcrumbs: [{ title: 'Classificação de risco', href: index() }],
};
