import { useState } from 'react';
import { cn } from '@/lib/utils';

const months = [
    'janeiro',
    'fevereiro',
    'março',
    'abril',
    'maio',
    'junho',
    'julho',
    'agosto',
    'setembro',
    'outubro',
    'novembro',
    'dezembro',
];

const selectClass =
    'h-11 rounded-md border border-input bg-card px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

/**
 * A competence (YYYY-MM) as month and year in Portuguese: the browser's month input follows the device's
 * language ("November 2026"). Posts the same `name` as before; never after `max`.
 */
export function MonthField({
    id,
    name,
    defaultValue,
    max,
    invalid,
    describedBy,
}: {
    id: string;
    name: string;
    defaultValue: string;
    max: string;
    invalid?: boolean;
    describedBy?: string;
}) {
    const [value, setValue] = useState(defaultValue);
    const [year, month] = value.split('-');
    const maxYear = Number(max.slice(0, 4));
    const years = Array.from({ length: 5 }, (_, index) => maxYear - index);
    const set = (nextYear: string, nextMonth: string) => {
        const next = `${nextYear}-${nextMonth}`;
        setValue(next > max ? max : next);
    };

    return (
        <div className={cn('flex gap-2')}>
            <input type="hidden" name={name} value={value} />
            <select
                id={id}
                aria-label="Mês"
                aria-invalid={invalid}
                aria-describedby={describedBy}
                value={month}
                onChange={(event) => set(year, event.target.value)}
                className={selectClass}
            >
                {months.map((label, index) => (
                    <option
                        key={label}
                        value={String(index + 1).padStart(2, '0')}
                    >
                        {label}
                    </option>
                ))}
            </select>
            <select
                aria-label="Ano"
                value={year}
                onChange={(event) => set(event.target.value, month)}
                className={selectClass}
            >
                {years.map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </select>
        </div>
    );
}
