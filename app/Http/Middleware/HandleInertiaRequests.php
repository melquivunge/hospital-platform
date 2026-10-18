<?php

namespace App\Http\Middleware;

use App\Models\ImportBatch;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\PolicyVersion;
use App\Models\Professional;
use App\Models\Shift;
use App\Policies\UnitPolicy;
use App\Tenancy\CurrentTenant;
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
            'canViewMyShifts' => fn () => ($membership = $request->user() === null ? null : UnitPolicy::currentMembership($request->user())) !== null
                && Professional::query()->where('membership_id', $membership->id)->exists(),
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
