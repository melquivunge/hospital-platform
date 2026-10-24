<?php

namespace App\Http\Middleware;

use App\Clinical\ClinicalAccess;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\BedController;
use App\Http\Controllers\EmergencyController;
use App\Http\Controllers\PharmacyController;
use App\Http\Controllers\StockInventoryController;
use App\Models\Exam;
use App\Models\ImportBatch;
use App\Models\Insurer;
use App\Models\JobFunction;
use App\Models\Medication;
use App\Models\Membership;
use App\Models\Patient;
use App\Models\PatientMerge;
use App\Models\PolicyVersion;
use App\Models\Professional;
use App\Models\Shift;
use App\Models\UserNotification;
use App\Policies\ShiftPolicy;
use App\Policies\UnitPolicy;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Lazy: the tenant context is resolved by a route middleware that runs after this one.
            'currentTenant' => fn () => app(CurrentTenant::class)->get()?->only('id', 'name'),
            'canManagePeople' => fn () => $request->user() !== null
                && $request->user()->can('viewAny', Membership::class),
            'canViewProfessionals' => fn () => $request->user() !== null
                && $request->user()->can('viewAny', Professional::class),
            'canManageJobFunctions' => fn () => $request->user() !== null
                && $request->user()->can('manage', JobFunction::class),
            'canAttend' => fn () => $request->user() !== null && ClinicalAccess::professional($request->user()) !== null,
            'canViewClinicalAccess' => fn () => ($membership = $request->user() === null ? null : UnitPolicy::currentMembership($request->user())) !== null
                && ($membership->hasRole(Role::Auditor) || $membership->hasRole(Role::NetworkAdmin) || Professional::managedUnitIds($membership) !== []),
            'canViewEmergency' => fn () => $request->user() !== null
                && EmergencyController::visible($request, UnitPolicy::currentMembership($request->user())),
            'canManageTriageLevels' => fn () => $request->user() !== null
                && (UnitPolicy::currentMembership($request->user())?->hasRole(Role::NetworkAdmin) ?? false),
            'canManageAdmissions' => fn () => $request->user() !== null
                && BedController::managedUnitIds(UnitPolicy::currentMembership($request->user())) !== [],
            'canViewBeds' => fn () => $request->user() !== null
                && BedController::visibleUnitIds(UnitPolicy::currentMembership($request->user())) !== [],
            'canViewCensus' => fn () => $request->user() !== null
                && BedController::censusUnitIds(UnitPolicy::currentMembership($request->user())) !== [],
            'canUsePharmacy' => fn () => $request->user() !== null
                && PharmacyController::unitIds(UnitPolicy::currentMembership($request->user())) !== [],
            // Step 5.3 (ADR-0008): the Laboratory or the Imaging role in some unit.
            'canUseExamQueue' => fn () => $request->user() !== null
                && ClinicalAccess::examScopes(UnitPolicy::currentMembership($request->user())) !== [],
            'canViewExamOverdue' => fn () => $request->user() !== null
                && ((UnitPolicy::currentMembership($request->user())?->hasRole(Role::NetworkAdmin) ?? false) || StockInventoryController::managerUnitIds($request->user()) !== []),
            // Pharmacy or unit manager: stock (read-only for the manager) and inventories.
            'canUseInventory' => fn () => $request->user() !== null
                && (PharmacyController::unitIds(UnitPolicy::currentMembership($request->user())) !== [] || StockInventoryController::managerUnitIds($request->user()) !== []),
            'canViewStockIndicators' => function () use ($request): bool {
                if ($request->user() === null) {
                    return false;
                }
                $membership = UnitPolicy::currentMembership($request->user());

                return ($membership?->hasRole(Role::NetworkAdmin) ?? false) || PharmacyController::unitIds($membership) !== [] || StockInventoryController::managerUnitIds($request->user()) !== [];
            },
            'canManageExams' => fn () => $request->user() !== null
                && $request->user()->can('manage', Exam::class),
            'canManageMedications' => fn () => $request->user() !== null
                && $request->user()->can('manage', Medication::class),
            'canManageInsurers' => fn () => $request->user() !== null
                && $request->user()->can('manage', Insurer::class),
            'canViewMyShifts' => fn () => ($membership = $request->user() === null ? null : UnitPolicy::currentMembership($request->user())) !== null
                && Professional::query()->where('membership_id', $membership->id)->exists(),
            // Linked professionals see their requests; coordinators and network admins see approvals.
            'canViewRequests' => fn () => ($membership = $request->user() === null ? null : UnitPolicy::currentMembership($request->user())) !== null
                && (ShiftPolicy::managedUnitIds($membership) !== [] || Professional::query()->where('membership_id', $membership->id)->exists()),
            'unreadNotifications' => fn () => $request->user() === null || app(CurrentTenant::class)->id() === null ? 0
                : UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->count(),
            // Step 5.5: unread urgent ones (a critical result) are highlighted in the top bar.
            'urgentNotifications' => fn () => $request->user() === null || app(CurrentTenant::class)->id() === null ? 0
                : UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->where('urgent', true)->count(),
            'canViewPatients' => fn () => $request->user() !== null
                && $request->user()->can('viewAny', Patient::class),
            'canMergePatients' => fn () => $request->user() !== null
                && $request->user()->can('merge', PatientMerge::class),
            'canManageSchedules' => fn () => ($membership = $request->user() === null ? null : UnitPolicy::currentMembership($request->user())) !== null
                && Professional::manageableUnits($membership)->exists(),
            'canBookAppointments' => fn () => $request->user() !== null
                && AppointmentController::bookingUnitIds(UnitPolicy::currentMembership($request->user())) !== [],
            'canImport' => fn () => $request->user() !== null
                && $request->user()->can('create', ImportBatch::class),
            'canViewShifts' => fn () => $request->user() !== null
                && $request->user()->can('viewAny', Shift::class),
            'canViewPolicies' => fn () => $request->user() !== null
                && $request->user()->can('viewAny', PolicyVersion::class),
            'canSwitchTenant' => fn () => $request->user() !== null
                && $request->user()->memberships()->active()->count() > 1,
        ];
    }
}
