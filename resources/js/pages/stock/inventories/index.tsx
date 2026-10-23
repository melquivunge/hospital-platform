import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import StockInventoryController from '@/actions/App/Http/Controllers/StockInventoryController';
import InputError from '@/components/input-error';
import { Page, PageHeader } from '@/components/page';
import { selectClassName } from '@/components/role-fields';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { show } from '@/routes/stock/inventories';

type OpenUnit = {
    id: number;
    name: string;
    medications: { id: number; name: string }[];
};

/**
 * The inventories of the units of this person (step 4c.3b, ADR-0007): the pharmacy opens one per unit at a time;
 * the unit manager approves or refuses it. No patient data.
 */
export default function InventoriesIndex({
    inventories,
    pages,
    statuses,
    openUnits,
}: {
    inventories: {
        id: number;
        unit: string | null;
        status: string;
        openedAt: string;
    }[];
    pages: { newer: string | null; older: string | null };
    statuses: Record<string, string>;
    openUnits: OpenUnit[];
}) {
    return (
        <>
            <Head title="Inventários" />
            <Page>
                <PageHeader
                    title="Inventários"
                    description="A farmácia conta o estoque lote por lote; o gestor da unidade, outra pessoa, aprova o ajuste."
                />

                {openUnits.length > 0 && <OpenForm units={openUnits} />}

                {inventories.length === 0 ? (
                    <p className="rounded-xl border bg-card px-5 py-12 text-center text-muted-foreground">
                        Nenhum inventário ainda.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card">
                        {inventories.map((inventory) => (
                            <li key={inventory.id}>
                                <Link
                                    href={show(inventory.id)}
                                    className="flex min-h-11 flex-wrap items-center justify-between gap-2 px-5 py-3 hover:bg-muted/50"
                                >
                                    <span>
                                        Inventário {inventory.id} ·{' '}
                                        {inventory.unit} · aberto em{' '}
                                        {inventory.openedAt}
                                    </span>
                                    <span className="text-sm font-medium">
                                        {statuses[inventory.status]}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                {(pages.newer || pages.older) && (
                    <nav
                        aria-label="Páginas de inventários"
                        className="mt-3 flex flex-wrap gap-2"
                    >
                        {pages.newer && (
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <Link href={pages.newer}>Mais recentes</Link>
                            </Button>
                        )}
                        {pages.older && (
                            <Button
                                asChild
                                variant="outline"
                                className="min-h-11"
                            >
                                <Link href={pages.older}>Anteriores</Link>
                            </Button>
                        )}
                    </nav>
                )}
            </Page>
        </>
    );
}

function OpenForm({ units }: { units: OpenUnit[] }) {
    const [unitId, setUnitId] = useState(units[0].id);
    const unit = units.find((option) => option.id === unitId) ?? units[0];

    return (
        <section className="mb-8 grid gap-3">
            <h2 className="text-lg font-semibold">Abrir inventário</h2>
            <Form
                {...StockInventoryController.store.form()}
                errorBag="open"
                className="grid gap-4 rounded-xl border bg-card p-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid max-w-sm gap-2">
                            <Label htmlFor="open-unit">Unidade</Label>
                            <select
                                id="open-unit"
                                name="unit_id"
                                required
                                value={unitId}
                                onChange={(event) =>
                                    setUnitId(Number(event.target.value))
                                }
                                className={selectClassName}
                            >
                                {units.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.unit_id} />
                        </div>
                        {unit.medications.length > 0 && (
                            <fieldset key={unit.id} className="grid gap-1">
                                <legend className="mb-2 text-sm font-medium">
                                    Medicamentos (nenhum marcado conta todos)
                                </legend>
                                {unit.medications.map((medication) => (
                                    <label
                                        key={medication.id}
                                        className="flex min-h-11 items-center gap-3 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            name="medication_ids[]"
                                            value={medication.id}
                                            className="size-5 shrink-0"
                                        />
                                        {medication.name}
                                    </label>
                                ))}
                            </fieldset>
                        )}
                        <InputError message={errors.medication_ids} />
                        <div>
                            <Button
                                type="submit"
                                disabled={processing}
                                className="min-h-11"
                            >
                                Abrir inventário
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </section>
    );
}
