import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Page primitives for the app shell (docs/design/direction.md). One h1 per page, the
 * signature rule before the eyebrow, consistent width and rhythm.
 */
export function Page({
    children,
    width = 'wide',
}: {
    children: ReactNode;
    width?: 'wide' | 'narrow';
}) {
    return (
        <div
            className={cn(
                'mx-auto w-full px-4 py-6 md:px-8 md:py-10',
                width === 'wide' ? 'max-w-6xl' : 'max-w-3xl',
            )}
        >
            {children}
        </div>
    );
}

export function PageHeader({
    eyebrow,
    title,
    description,
    actions,
}: {
    eyebrow?: string;
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <header className="mb-8 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
            <div className="min-w-0 space-y-2">
                {eyebrow && (
                    <p className="flex items-center gap-2 text-xs font-semibold tracking-[0.16em] text-primary uppercase">
                        <span
                            aria-hidden="true"
                            className="h-px w-6 bg-signature"
                        />
                        {eyebrow}
                    </p>
                )}
                <h1 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                    {title}
                </h1>
                {description && (
                    <p className="max-w-2xl text-sm leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && <div className="flex flex-wrap gap-3">{actions}</div>}
        </header>
    );
}

/** Status shown with text and icon, never color alone. */
export function WarningBadge({ children }: { children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-warning-border bg-warning-surface px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-warning">
            <TriangleAlert className="size-3.5" aria-hidden="true" />
            {children}
        </span>
    );
}
