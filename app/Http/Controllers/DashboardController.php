<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Policies\UnitPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Overview of what the member can see in the current tenant.
     */
    public function __invoke(Request $request): Response
    {
        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        $units = Unit::query()->visibleTo($membership)->withCount('sectors')->get();

        return Inertia::render('dashboard', [
            'stats' => [
                'units' => $units->count(),
                'sectors' => (int) $units->sum('sectors_count'),
                'incomplete' => $units->reject->isComplete()->count(),
            ],
            'canCreateUnit' => Gate::allows('create', Unit::class),
        ]);
    }
}
