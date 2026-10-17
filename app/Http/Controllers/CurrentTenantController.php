<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CurrentTenantController extends Controller
{
    /**
     * Institutions the user can work in: only active memberships.
     */
    public function edit(Request $request): Response
    {
        $tenants = $request->user()->memberships()->active()->with('tenant')->orderBy('tenant_id')->get()
            ->map(fn (Membership $membership) => ['id' => $membership->tenant_id, 'name' => $membership->tenant->name]);

        return Inertia::render('current-tenant/edit', [
            'tenants' => $tenants,
            'currentTenantId' => $request->session()->get('tenant_id'),
        ]);
    }

    /**
     * Select the tenant for this session. Only tenants with an active membership are accepted.
     */
    public function update(Request $request): RedirectResponse
    {
        $tenantId = (int) $request->validate(['tenant_id' => ['required', 'integer']])['tenant_id'];

        if (! $request->user()->memberships()->active()->where('tenant_id', $tenantId)->exists()) {
            // Recorded without tenant: the requested one is not the user's to see.
            AuditEvent::record('tenant.selection_denied', null, null, ['requested_tenant_id' => $tenantId]);

            throw ValidationException::withMessages(['tenant_id' => 'Instituição indisponível para sua conta.']);
        }

        if ($request->session()->get('tenant_id') !== $tenantId) {
            $request->session()->put('tenant_id', $tenantId);
            AuditEvent::record('tenant.selected', $tenantId);
        }

        return redirect()->intended(route('dashboard'));
    }
}
