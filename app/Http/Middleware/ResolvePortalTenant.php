<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Portal\PortalAuthController;
use App\Models\PatientAccount;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 7a.1a (ADR-0012): the portal's network comes from the address (/portal/{portal}), never from a
 * staff membership. It runs before the patient guard reads the session, so the account is looked up
 * inside that network only; an account of another network, or idle for 30 minutes, is signed out.
 */
class ResolvePortalTenant
{
    /** Portals are often opened on a shared phone (contract, section 5). */
    public const IDLE_MINUTES = 30;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('portal');
        $tenant = is_string($slug) ? Tenant::query()->where('slug', $slug)->first() : null;
        abort_if($tenant === null, 404);

        app(CurrentTenant::class)->set($tenant);
        $guard = Auth::guard('patient');
        /** @var PatientAccount|null $account */
        $account = $guard->user();
        $lastSeen = $request->session()->get('portal.last_seen');
        $idle = is_int($lastSeen) && now()->getTimestamp() - $lastSeen > self::IDLE_MINUTES * 60;
        // A password set elsewhere (forgotten-password link) ends this session.
        $stale = $account !== null && $request->session()->get('portal.password') !== PortalAuthController::passwordFingerprint($account);

        if ($account !== null && ($account->tenant_id !== $tenant->id || $idle || $stale)) {
            $guard->logout();
            $request->session()->forget(['portal.last_seen', 'portal.password']);
            $request->session()->regenerateToken();

            // Only pages that need the account send back to the sign-in; a public one (the visit QR) opens as a guest.
            if (in_array('auth:patient', $request->route()?->gatherMiddleware() ?? [], true)) {
                return redirect()->route('portal.login', ['portal' => $tenant->slug])
                    ->with('status', 'Sua sessão terminou por falta de uso. Entre de novo.');
            }

            $account = null;
        }

        if ($account !== null) {
            $request->session()->put('portal.last_seen', now()->getTimestamp());
        }

        try {
            return $next($request);
        } finally {
            // auth:patient makes the patient guard the process default; a long-lived worker (or the next
            // request of a test) must never see a staff route default to the patient account.
            Auth::shouldUse('web');
        }
    }
}
