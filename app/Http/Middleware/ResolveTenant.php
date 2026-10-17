<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Tenant comes from the session, validated against the user's active memberships on every request.
     * A revoked membership therefore stops working on the next request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $memberships = $request->user()->memberships()->active()->with('tenant')->get();

        $selected = $memberships->firstWhere('tenant_id', $request->session()->get('tenant_id'))
            ?? ($memberships->count() === 1 ? $memberships->first() : null);

        // ponytail: several memberships without a selection get 403 until the selector screen (step 2b).
        abort_if($selected === null, 403, $memberships->isEmpty()
            ? 'Sua conta não tem acesso ativo a nenhuma instituição.'
            : 'Selecione a instituição para continuar.');

        $request->session()->put('tenant_id', $selected->tenant_id);
        app(CurrentTenant::class)->set($selected->tenant);

        return $next($request);
    }
}
