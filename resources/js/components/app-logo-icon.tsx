import type { SVGAttributes } from 'react';

/**
 * Pulse mark shared with the login screen. Stroke-only: fill is forced off so callers'
 * `fill-current` classes do not paint the line as a shape.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
            style={{ ...props.style, fill: 'none' }}
        >
            <path d="M2 12h4l3-8 6 16 3-8h4" />
        </svg>
    );
}
