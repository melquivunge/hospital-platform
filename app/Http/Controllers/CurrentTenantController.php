<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CurrentTenantController extends Controller
{
    /**
     * Select the tenant for this session. Only tenants with an active membership are accepted.
     */
    public function update(Request $request): RedirectResponse
    {
        $tenantId = (int) $request->validate(['tenant_id' => ['required', 'integer']])['tenant_id'];

        if (! $request->user()->memberships()->active()->where('tenant_id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['tenant_id' => 'Instituição indisponível para sua conta.']);
        }

        $request->session()->put('tenant_id', $tenantId);

        return redirect()->route('dashboard');
    }
}
