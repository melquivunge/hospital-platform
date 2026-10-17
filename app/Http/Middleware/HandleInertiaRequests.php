<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\PolicyVersion;
use App\Models\Professional;
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
            'canViewPolicies' => fn () => $request->user() !== null
                && $request->user()->can('viewAny', PolicyVersion::class),
            'canSwitchTenant' => fn () => $request->user() !== null
                && $request->user()->memberships()->active()->count() > 1,
        ];
    }
}
