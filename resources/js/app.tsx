import { createInertiaApp, router } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Plataforma Hospitalar';

// Each tab keeps the network it shows: the session holds the last one chosen in any tab, so every visit says which
// network this tab is on (the server accepts only an active membership). Not on the switch itself.
let tenantId: number | null = null;
router.on('navigate', (event) => {
    tenantId =
        (event.detail.page.props.currentTenant as { id: number } | null)?.id ??
        null;
});
router.on('before', (event) => {
    const visit = event.detail.visit;
    if (
        tenantId !== null &&
        !visit.url.pathname.startsWith('/current-tenant')
    ) {
        visit.headers['X-Tenant'] = String(tenantId);
    }
});

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name === 'error':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#126b61',
    },
});

// This will set light / dark mode on load...
initializeTheme();
