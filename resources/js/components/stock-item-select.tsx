import { useEffect, useRef, useState } from 'react';
import { selectClassName } from '@/components/role-fields';

export type StockCatalogOption = {
    key: string;
    name: string;
    stockUnit: string;
};

/**
 * A medication or a material of the catalog (step 5b.6b): the option is "medication:5" or "material:3", and the
 * form sends it as medication_id or material_id, exactly one of them.
 */
export function StockItemSelect({
    id,
    catalog,
}: {
    id: string;
    catalog: StockCatalogOption[];
}) {
    const [chosen, setChosen] = useState('');
    const [kind, itemId] = chosen.split(':');
    const select = useRef<HTMLSelectElement>(null);

    // The form's reset after a save clears the choice, so no hidden id outlives it.
    useEffect(() => {
        const form = select.current?.form;
        const clear = () => setChosen('');
        form?.addEventListener('reset', clear);

        return () => form?.removeEventListener('reset', clear);
    }, []);

    return (
        <>
            <select
                ref={select}
                id={id}
                required
                value={chosen}
                onChange={(event) => setChosen(event.target.value)}
                className={selectClassName}
            >
                <option value="" disabled>
                    Selecione
                </option>
                {catalog.map((option) => (
                    <option key={option.key} value={option.key}>
                        {option.name} — {option.stockUnit}
                    </option>
                ))}
            </select>
            {itemId && (
                <input
                    type="hidden"
                    name={kind === 'material' ? 'material_id' : 'medication_id'}
                    value={itemId}
                />
            )}
        </>
    );
}
