import { useState } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { roleDescriptions, roleLabels, roleOrder } from '@/lib/roles';

export const selectClassName =
    'h-11 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-invalid:border-destructive';

/**
 * Role and unit inputs. The unit appears only for unit-scoped roles; the server enforces the same rule.
 */
export function RoleFields({
    idPrefix,
    units,
    unitScopedRoles,
    errors,
}: {
    idPrefix: string;
    units: { id: number; name: string }[];
    unitScopedRoles: string[];
    errors: Partial<Record<string, string>>;
}) {
    const [role, setRole] = useState('');
    const needsUnit = unitScopedRoles.includes(role);
    const canHaveUnit = needsUnit || role === 'auditor';

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-role`}>Papel</Label>
                <select
                    id={`${idPrefix}-role`}
                    name="role"
                    required
                    value={role}
                    onChange={(event) => setRole(event.target.value)}
                    aria-invalid={!!errors.role}
                    aria-describedby={`${idPrefix}-role-help${errors.role ? ` ${idPrefix}-role-error` : ''}`}
                    className={selectClassName}
                >
                    <option value="" disabled>
                        Selecione
                    </option>
                    {roleOrder.map((value) => (
                        <option key={value} value={value}>
                            {roleLabels[value]}
                        </option>
                    ))}
                </select>
                <p
                    id={`${idPrefix}-role-help`}
                    className="text-xs text-muted-foreground"
                >
                    {role
                        ? roleDescriptions[role]
                        : 'O papel define o que a pessoa pode fazer.'}
                </p>
                <InputError
                    id={`${idPrefix}-role-error`}
                    message={errors.role}
                />
            </div>

            {canHaveUnit && (
                <div className="grid gap-2">
                    <Label htmlFor={`${idPrefix}-unit`}>
                        Unidade{needsUnit ? '' : ' (opcional)'}
                    </Label>
                    <select
                        id={`${idPrefix}-unit`}
                        name="unit_id"
                        required={needsUnit}
                        defaultValue=""
                        aria-invalid={!!errors.unit_id}
                        aria-describedby={
                            errors.unit_id
                                ? `${idPrefix}-unit-error`
                                : undefined
                        }
                        className={selectClassName}
                    >
                        <option value="">
                            {needsUnit ? 'Selecione' : 'Toda a instituição'}
                        </option>
                        {units.map((unit) => (
                            <option key={unit.id} value={unit.id}>
                                {unit.name}
                            </option>
                        ))}
                    </select>
                    <InputError
                        id={`${idPrefix}-unit-error`}
                        message={errors.unit_id}
                    />
                </div>
            )}
        </>
    );
}
