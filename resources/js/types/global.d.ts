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
            urgentNotifications: number;
            canViewPatients: boolean;
            canMergePatients: boolean;
            canManageSchedules: boolean;
            canBookAppointments: boolean;
            canManageInsurers: boolean;
            canAttend: boolean;
            canViewClinicalAccess: boolean;
            canViewBeds: boolean;
            canViewCensus: boolean;
            canUsePharmacy: boolean;
            canUseBilling: boolean;
            canUseExamQueue: boolean;
            canUseSurgerySchedule: boolean;
            canUseMySurgeries: boolean;
            canViewExamOverdue: boolean;
            canUseInventory: boolean;
            canViewStockIndicators: boolean;
            canManageMedications: boolean;
            canManageExams: boolean;
            canManageSurgeryCatalog: boolean;
            canManageAdmissions: boolean;
            canViewEmergency: boolean;
            canManageTriageLevels: boolean;
            [key: string]: unknown;
        };
    }
}
