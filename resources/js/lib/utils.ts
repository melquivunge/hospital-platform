import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

/** Cents as reais: "R$ 1.234,56". */
export const money = (cents: number) =>
    (cents / 100).toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    });

/**
 * A unit's time zone in words ("Horário de Brasília"), not the IANA id; the id when the
 * browser has no name for it, and "não definido" when the unit has none.
 */
export function timezoneLabel(timezone: string | null | undefined): string {
    if (!timezone) {
        return 'não definido';
    }

    try {
        return (
            new Intl.DateTimeFormat('pt-BR', {
                timeZone: timezone,
                timeZoneName: 'longGeneric',
            })
                .formatToParts(new Date())
                .find((part) => part.type === 'timeZoneName')?.value ?? timezone
        );
    } catch {
        return timezone.replaceAll('_', ' ');
    }
}
