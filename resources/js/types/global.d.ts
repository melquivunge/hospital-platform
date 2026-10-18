import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            currentTenant: { id: number; name: string } | null;
            canSwitchTenant: boolean;
            canManagePeople: boolean;
            canViewPolicies: boolean;
            canViewProfessionals: boolean;
            canManageJobFunctions: boolean;
            canViewShifts: boolean;
            canImport: boolean;
            canViewMyShifts: boolean;
            canViewRequests: boolean;
            unreadNotifications: number;
            [key: string]: unknown;
        };
    }
}
