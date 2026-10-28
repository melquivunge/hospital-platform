import { useEffect, useId, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { procedures } from '@/routes/sigtap';

type Option = { code: string; name: string };

/**
 * Steps 6b.5a and 6b.5b2: a SIGTAP procedure that may be an AIH's principal, suggested by code or words as one types
 * (field "procedure_code").
 */
export function SigtapProcedureField({
    label,
    error,
}: {
    label: string;
    error: string | undefined;
}) {
    const id = useId();
    const [options, setOptions] = useState<Option[]>([]);
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    // Only the latest request's answer is shown: a slower, older one is dropped.
    const latest = useRef(0);
    useEffect(() => () => clearTimeout(timer.current), []);
    const suggest = (term: string) => {
        clearTimeout(timer.current);
        if (term.trim().length < 2) {
            setOptions([]);

            return;
        }
        timer.current = setTimeout(() => {
            const request = ++latest.current;
            fetch(procedures.url({ query: { q: term } }), {
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((found: Option[]) => {
                    if (request === latest.current) {
                        setOptions(found);
                    }
                })
                .catch(() => setOptions([]));
        }, 250);
    };

    return (
        <div className="grid gap-2">
            <Label htmlFor={`${id}-procedure`}>{label}</Label>
            <Input
                id={`${id}-procedure`}
                name="procedure_code"
                list={`${id}-procedures`}
                required
                maxLength={20}
                autoComplete="off"
                placeholder="Código ou nome"
                onChange={(event) => suggest(event.target.value)}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-procedure-error` : undefined}
                className="h-11"
            />
            <datalist id={`${id}-procedures`}>
                {options.map((option) => (
                    <option key={option.code} value={option.code}>
                        {option.name}
                    </option>
                ))}
            </datalist>
            <InputError id={`${id}-procedure-error`} message={error} />
        </div>
    );
}
