<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

abstract class Controller
{
    /**
     * A network-wide screen opened by the network admin of a network without units yet: to the units, with why.
     * Anyone else without a unit of that screen has no access (403).
     */
    protected static function withoutUnits(User $user): RedirectResponse
    {
        abort_unless(UnitPolicy::currentMembership($user)?->hasRole(Role::NetworkAdmin) ?? false, 403);
        Inertia::flash('toast', ['type' => 'info', 'message' => 'Cadastre uma unidade da rede para usar esta tela.']);

        return to_route('units.index');
    }
}
