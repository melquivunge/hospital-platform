<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenancy\GrantRoleRequest;
use App\Http\Requests\Tenancy\InvitePersonRequest;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\RoleAssignment;
use App\Models\Unit;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * People of the current tenant: invitations, roles and access (network admins only).
 */
class PeopleController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Membership::class);

        $people = Membership::query()
            ->where('tenant_id', app(CurrentTenant::class)->require()->id)
            ->with(['user', 'roleAssignments.unit'])
            ->get()
            ->sortBy(fn (Membership $membership) => [$membership->revoked_at !== null, Str::lower($membership->user->name)])
            ->values()
            ->map(fn (Membership $membership) => $this->summary($membership, $request));

        return Inertia::render('people/index', [
            'people' => $people,
            ...$this->options(),
        ]);
    }

    public function store(InvitePersonRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $tenantId = app(CurrentTenant::class)->require()->id;

        [$membership, $isNewUser] = DB::transaction(function () use ($data, $tenantId) {
            $user = User::where('email', $data['email'])->first();
            $isNewUser = $user === null;
            $user ??= User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::password(64)]);

            $membership = Membership::firstOrNew(['tenant_id' => $tenantId, 'user_id' => $user->id]);

            if ($membership->exists && $membership->revoked_at === null) {
                throw ValidationException::withMessages(['email' => 'Esta pessoa já tem acesso à instituição.']);
            }

            $membership->exists ? $membership->reactivate() : $membership->save();
            $this->grantIfMissing($membership, Role::from($data['role']), $data['unit_id'] ?? null);

            AuditEvent::record('member.invited', $tenantId, $membership, ['user_id' => $user->id, 'new_identity' => $isNewUser]);

            return [$membership, $isNewUser];
        });

        // After commit: a new person sets the password (and confirms the email) through this link.
        if ($isNewUser) {
            Password::broker()->sendResetLink(['email' => $data['email']]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $isNewUser
            ? 'Convite enviado. A pessoa receberá um e-mail para definir a senha.'
            : 'Acesso concedido. A pessoa já pode entrar com a conta existente.']);

        return to_route('people.show', $membership);
    }

    public function show(Request $request, Membership $membership): Response
    {
        Gate::authorize('update', $membership);

        $membership->load(['user', 'roleAssignments.unit']);

        return Inertia::render('people/show', [
            'person' => $this->summary($membership, $request),
            ...$this->options(),
        ]);
    }

    public function grantRole(GrantRoleRequest $request, Membership $membership): RedirectResponse
    {
        if ($membership->revoked_at !== null) {
            throw ValidationException::withMessages(['role' => 'Reative o acesso antes de conceder papéis.']);
        }

        $data = $request->validated();
        $this->grantIfMissing($membership, Role::from($data['role']), $data['unit_id'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Papel concedido.']);

        return to_route('people.show', $membership);
    }

    public function revokeRole(Membership $membership, RoleAssignment $roleAssignment): RedirectResponse
    {
        Gate::authorize('update', $membership);

        $membership->revokeRole($roleAssignment);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Papel retirado.']);

        return to_route('people.show', $membership);
    }

    public function revoke(Request $request, Membership $membership): RedirectResponse
    {
        Gate::authorize('update', $membership);

        if ($membership->user_id === $request->user()->id) {
            throw ValidationException::withMessages(['membership' => 'Você não pode revogar o próprio acesso.']);
        }

        DB::transaction(function () use ($membership) {
            Membership::lockTenant($membership->tenant_id);

            if ($membership->hasRole(Role::NetworkAdmin) && Membership::activeNetworkAdminCount($membership->tenant_id) <= 1) {
                throw ValidationException::withMessages(['membership' => 'A instituição precisa de ao menos um administrador de rede ativo.']);
            }

            $membership->revoke();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Acesso revogado.']);

        return to_route('people.show', $membership);
    }

    public function reactivate(Membership $membership): RedirectResponse
    {
        Gate::authorize('update', $membership);

        $membership->reactivate();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Acesso reativado.']);

        return to_route('people.show', $membership);
    }

    private function grantIfMissing(Membership $membership, Role $role, ?int $unitId): void
    {
        $exists = $membership->roleAssignments()->where('role', $role)->where('unit_id', $unitId)->exists();

        if (! $exists) {
            $membership->grant($role, $unitId === null ? null : Unit::findOrFail($unitId));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Membership $membership, Request $request): array
    {
        return [
            'id' => $membership->id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'active' => $membership->revoked_at === null,
            'isSelf' => $membership->user_id === $request->user()->id,
            'roles' => $membership->roleAssignments->map(fn (RoleAssignment $assignment) => [
                'id' => $assignment->id,
                'role' => $assignment->role->value,
                'unit' => $assignment->unit?->name,
            ])->values(),
        ];
    }

    /**
     * @return array{units: mixed, unitScopedRoles: list<string>}
     */
    private function options(): array
    {
        return [
            'units' => Unit::query()->orderBy('name')->get(['id', 'name']),
            'unitScopedRoles' => array_values(array_map(
                fn (Role $role) => $role->value,
                array_filter(Role::cases(), fn (Role $role) => $role->requiresUnit()),
            )),
        ];
    }
}
