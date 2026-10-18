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
    /**
     * `tenant:optional` (account settings) loads the client when it can be resolved and lets the
     * request through otherwise, so menus keep their items without blocking users with no access.
     */
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $memberships = $request->user()->memberships()->active()->with('tenant')->get();

        $selected = $memberships->firstWhere('tenant_id', $request->session()->get('tenant_id'))
            ?? ($memberships->count() === 1 ? $memberships->first() : null);

        if ($selected === null && $mode === 'optional') {
            return $next($request);
        }

        abort_if($memberships->isEmpty(), 403, 'Sua conta não tem acesso ativo a nenhuma instituição.');

        if ($selected === null) {
            $request->session()->forget('tenant_id');

            return redirect()->guest(route('current-tenant.edit'));
        }

        $request->session()->put('tenant_id', $selected->tenant_id);
        app(CurrentTenant::class)->set($selected->tenant);

        return $next($request);
    }
}
