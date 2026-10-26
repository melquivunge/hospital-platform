import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { selectClassName } from '@/components/role-fields';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type Modality = 'private' | 'insurance' | 'sus';

export const modalityLabels: Record<Modality, string> = {
    private: 'Particular',
    insurance: 'Convênio',
    sus: 'SUS',
};

/**
 * The modality the reception records on arrival: private, insurer (with the card) or SUS. Used by the
 * consultation's arrival (step 3.5) and the emergency's (step 6.2a0).
 */
export function ModalityFields({
    idPrefix,
    insurers,
    errors,
}: {
    idPrefix: string;
    insurers: { id: number; name: string }[];
    errors: Record<string, string | undefined>;
}) {
    const [modality, setModality] = useState<Modality | ''>('');
    const id = (field: string) => `${idPrefix}-${field}`;

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    id={id('modality')}
                    label="Modalidade"
                    error={errors.modality}
                >
                    <select
                        id={id('modality')}
                        name="modality"
                        required
                        value={modality}
                        onChange={(event) =>
                            setModality(event.target.value as Modality | '')
                        }
                        className={selectClassName}
                    >
                        <option value="" disabled>
                            Selecione
                        </option>
                        {Object.entries(modalityLabels).map(
                            ([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ),
                        )}
                    </select>
                </Field>
                {modality === 'insurance' && (
                    <Field
                        id={id('insurer_id')}
                        label="Convênio"
                        error={errors.insurer_id}
                    >
                        <select
                            id={id('insurer_id')}
                            name="insurer_id"
                            required
                            defaultValue=""
                            className={selectClassName}
                        >
                            <option value="" disabled>
                                {insurers.length === 0
                                    ? 'Nenhum convênio ativo'
                                    : 'Selecione'}
                            </option>
                            {insurers.map((insurer) => (
                                <option key={insurer.id} value={insurer.id}>
                                    {insurer.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
            </div>
            {modality === 'insurance' && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        id={id('card')}
                        label="Número da carteira"
                        error={errors.insurance_card_number}
                    >
                        <Input
                            id={id('card')}
                            name="insurance_card_number"
                            required
                            maxLength={40}
                            autoComplete="off"
                            className="h-11"
                        />
                    </Field>
                    <Field
                        id={id('valid_until')}
                        label="Validade da carteira"
                        error={errors.insurance_card_valid_until}
                    >
                        <Input
                            id={id('valid_until')}
                            name="insurance_card_valid_until"
                            type="date"
                            required
                            className="h-11"
                        />
                    </Field>
                </div>
            )}
        </>
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
