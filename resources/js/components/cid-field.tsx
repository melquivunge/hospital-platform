import { useEffect, useId, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { search } from '@/routes/cid10';

type Option = { code: string; name: string };

/**
 * Step 6b.2 (ADR-0011): the CID-10 of the care at the close or the discharge: the principal code, suggested by the
 * official table as one types a code or words, and up to five secondary codes. Required in the SUS.
 */
export function CidField({
    required,
    errors,
}: {
    required: boolean;
    errors: Record<string, string | undefined>;
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
            fetch(search.url({ query: { q: term } }), {
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
        <div className="grid gap-4 sm:grid-cols-[14rem_1fr]">
            <div className="grid content-start gap-2">
                <Label htmlFor={`${id}-principal`}>
                    {required
                        ? 'CID-10 principal'
                        : 'CID-10 principal (opcional)'}
                </Label>
                <Input
                    id={`${id}-principal`}
                    name="cid_principal"
                    list={`${id}-options`}
                    required={required}
                    maxLength={10}
                    autoComplete="off"
                    placeholder="Código ou nome"
                    onChange={(event) => suggest(event.target.value)}
                    aria-invalid={!!errors.cid_principal}
                    aria-describedby={
                        errors.cid_principal
                            ? `${id}-principal-error`
                            : undefined
                    }
                    className="h-11"
                />
                <datalist id={`${id}-options`}>
                    {options.map((option) => (
                        <option key={option.code} value={option.code}>
                            {option.name}
                        </option>
                    ))}
                </datalist>
                <InputError
                    id={`${id}-principal-error`}
                    message={errors.cid_principal}
                />
            </div>
            <div className="grid content-start gap-2">
                <Label htmlFor={`${id}-secondary`}>
                    CIDs secundários (opcional)
                </Label>
                <Input
                    id={`${id}-secondary`}
                    name="cid_secondary"
                    maxLength={100}
                    autoComplete="off"
                    placeholder="Ex.: I10, E11.9"
                    aria-invalid={!!errors.cid_secondary}
                    aria-describedby={
                        errors.cid_secondary
                            ? `${id}-secondary-error`
                            : undefined
                    }
                    className="h-11"
                />
                <InputError
                    id={`${id}-secondary-error`}
                    message={errors.cid_secondary}
                />
            </div>
            {required && (
                <p className="text-sm text-muted-foreground sm:col-span-2">
                    Atendimento SUS: o CID vai no BPA ou na AIH. O faturamento
                    vê só o código.
                </p>
            )}
        </div>
    );
}
