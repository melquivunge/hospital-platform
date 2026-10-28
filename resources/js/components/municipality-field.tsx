import { useEffect, useId, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { search } from '@/routes/municipalities';

type Option = { code: string; name: string };

/**
 * Step 6b.6b1: a municipality of the official IBGE table, typed by name and chosen among the suggestions; the form
 * sends its IBGE code (hidden field `name`).
 */
export function MunicipalityField({
    name,
    label,
    defaultCode,
    defaultLabel,
    error,
}: {
    name: string;
    label: string;
    defaultCode: string | null;
    defaultLabel: string | null;
    error: string | undefined;
}) {
    const id = useId();
    const [options, setOptions] = useState<Option[]>([]);
    const [code, setCode] = useState(defaultCode ?? '');
    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    // Only the latest request's answer is shown: a slower, older one is dropped.
    const latest = useRef(0);
    useEffect(() => () => clearTimeout(timer.current), []);
    const change = (term: string) => {
        // The code follows the text only when it is one of the suggestions (or the saved municipality retyped); every
        // keystroke drops the answers still on their way.
        const request = ++latest.current;
        setCode(
            options.find((option) => option.name === term)?.code ??
                (term === defaultLabel ? (defaultCode ?? '') : ''),
        );
        clearTimeout(timer.current);
        if (term.trim().length < 2) {
            setOptions([]);

            return;
        }
        timer.current = setTimeout(() => {
            fetch(search.url({ query: { q: term } }), {
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((found: Option[]) => {
                    if (request === latest.current) {
                        setOptions(found);
                        setCode(
                            (current) =>
                                found.find((option) => option.name === term)
                                    ?.code ?? current,
                        );
                    }
                })
                .catch(() => setOptions([]));
        }, 250);
    };

    return (
        <div className="grid gap-2">
            <Label htmlFor={`${id}-municipality`}>{label}</Label>
            <Input
                id={`${id}-municipality`}
                list={`${id}-municipalities`}
                defaultValue={defaultLabel ?? ''}
                autoComplete="off"
                placeholder="Nome do município"
                onChange={(event) => change(event.target.value)}
                aria-invalid={!!error}
                aria-describedby={
                    error ? `${id}-municipality-error` : undefined
                }
                className="h-11"
            />
            <input type="hidden" name={name} value={code} />
            <datalist id={`${id}-municipalities`}>
                {options.map((option) => (
                    <option key={option.code} value={option.name} />
                ))}
            </datalist>
            <InputError id={`${id}-municipality-error`} message={error} />
        </div>
    );
}
