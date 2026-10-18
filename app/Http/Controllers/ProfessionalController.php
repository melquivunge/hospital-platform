<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenancy\ProfessionalRequest;
use App\Models\AuditEvent;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Unit;
use App\Models\User;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * Professionals of the current tenant (step 3a). No delete: schedules and history will reference them.
 */
class ProfessionalController extends Controller
{
    private const FIELDS = ['id', 'name', 'base_unit_id', 'council', 'council_number', 'council_uf', 'external_id', 'email', 'phone'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Professional::class);

        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        $professionals = Professional::query()
            ->visibleTo($membership)
            ->with('baseUnit:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Professional $professional) => [
                ...$professional->only('id', 'name', 'council', 'council_number', 'council_uf'),
                'baseUnit' => $professional->baseUnit?->name,
            ]);

        return Inertia::render('professionals/index', [
            'professionals' => $professionals,
            'canCreate' => Gate::allows('create', Professional::class),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Professional::class);

        return Inertia::render('professionals/form', ['professional' => null, ...self::options($request)]);
    }

    public function store(ProfessionalRequest $request): RedirectResponse
    {
        $professional = self::saving(fn () => DB::transaction(function () use ($request) {
            $professional = Professional::create($request->safe()->except('base_unit_reason'));
            AuditEvent::record('professional.created', $professional->tenant_id, $professional, ['base_unit_id' => $professional->base_unit_id]);

            return $professional;
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profissional cadastrado.']);

        return to_route('professionals.show', $professional);
    }

    public function show(Professional $professional): Response
    {
        Gate::authorize('view', $professional);

        $unitNames = Unit::query()->pluck('name', 'id');
        $history = AuditEvent::query()
            ->where('tenant_id', $professional->tenant_id)
            ->where('subject_type', $professional->getMorphClass())
            ->where('subject_id', $professional->id)
            ->whereIn('action', ['professional.created', 'professional.base_unit_changed'])
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'from' => $unitNames->get($event->context['from'] ?? 0),
                'to' => $unitNames->get($event->context['to'] ?? $event->context['base_unit_id'] ?? 0),
                'reason' => $event->context['reason'] ?? null,
                'actor' => $event->actor?->name,
                'occurredAt' => $event->occurred_at->toIso8601String(),
            ]);

        $full = Gate::allows('viewFull', $professional);
        $membership = UnitPolicy::membershipFor(request()->user(), $professional->tenant_id);

        $qualifications = $professional->qualifications()
            ->when(! $full, fn ($query) => $query->whereIn('unit_id', $membership === null ? [] : Professional::managedUnitIds($membership)))
            ->with(['unit:id,name', 'jobFunction:id,name', 'sectors:id,name'])
            ->orderByRaw("status = 'revoked'")
            ->orderBy('id')
            ->get()
            ->map(fn (Qualification $qualification) => [
                'id' => $qualification->id,
                'unit' => $qualification->unit?->name,
                'function' => $qualification->jobFunction?->name,
                'sectors' => $qualification->sectors->pluck('name')->sort()->values(),
                'status' => $qualification->status->value,
                'validFrom' => $qualification->valid_from->toDateString(),
                'validUntil' => $qualification->valid_until?->toDateString(),
                'canDecide' => Gate::allows('decide', $qualification),
            ]);
        $canRequest = Gate::allows('request', [Qualification::class, $professional]);

        return Inertia::render('professionals/show', [
            'professional' => [
                ...$professional->only($full ? self::FIELDS : array_diff(self::FIELDS, ['email', 'phone'])),
                'baseUnit' => $professional->baseUnit?->name,
            ],
            'history' => $history,
            'canUpdate' => Gate::allows('update', $professional),
            'access' => [
                'linked' => $professional->membership_id !== null,
                'canInvite' => $professional->membership_id === null && Gate::allows('update', $professional),
                'hasEmail' => $professional->email !== null,
            ],
            'qualifications' => $qualifications,
            'canRequestQualification' => $canRequest,
            // Requesting is allowed for any unit of the network; approval happens at the destination.
            'qualificationOptions' => $canRequest ? [
                'units' => Unit::query()->with('sectors:id,unit_id,name')->orderBy('name')->get()
                    ->map(fn (Unit $unit) => ['id' => $unit->id, 'name' => $unit->name, 'sectors' => $unit->sectors->sortBy('name')->map->only('id', 'name')->values()]),
                'functions' => JobFunction::query()->orderBy('name')->get(['id', 'name']),
            ] : null,
        ]);
    }

    public function edit(Request $request, Professional $professional): Response
    {
        Gate::authorize('update', $professional);

        return Inertia::render('professionals/form', ['professional' => $professional->only(self::FIELDS), ...self::options($request)]);
    }

    public function update(ProfessionalRequest $request, Professional $professional): RedirectResponse
    {
        self::saving(fn () => DB::transaction(function () use ($request, $professional) {
            // Locked and re-checked: a concurrent base change may have moved the professional out of
            // the editor's units, or turned this save into a base change without a reason.
            $professional = Professional::query()->lockForUpdate()->findOrFail($professional->id);
            Gate::authorize('update', $professional);
            $from = $professional->base_unit_id;

            if ($request->integer('base_unit_id') !== $from && ! $request->filled('base_unit_reason')) {
                throw ValidationException::withMessages(['base_unit_reason' => 'Informe o motivo da troca de unidade-base.']);
            }

            $professional->fill($request->safe()->except('base_unit_reason'));
            $changed = array_keys($professional->getDirty());
            $professional->save();

            if (in_array('base_unit_id', $changed, true)) {
                AuditEvent::record('professional.base_unit_changed', $professional->tenant_id, $professional, [
                    'from' => $from,
                    'to' => $professional->base_unit_id,
                    'reason' => $request->string('base_unit_reason')->value(),
                ]);
            }

            $otherFields = array_values(array_diff($changed, ['base_unit_id', 'updated_at']));

            if ($otherFields !== []) {
                AuditEvent::record('professional.updated', $professional->tenant_id, $professional, ['fields' => $otherFields]);
            }
        }));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profissional atualizado.']);

        return to_route('professionals.show', $professional);
    }

    /**
     * Gives the professional access with the e-mail of the registration (user decision, 17/10/2026):
     * reuses an existing account, grants the Professional role at the base unit and links both.
     */
    public function invite(Professional $professional): RedirectResponse
    {
        Gate::authorize('update', $professional);

        if ($professional->membership_id !== null) {
            throw ValidationException::withMessages(['access' => 'Este profissional já tem acesso.']);
        }

        if ($professional->email === null) {
            throw ValidationException::withMessages(['access' => 'Cadastre o e-mail do profissional antes de convidar.']);
        }

        $email = $professional->email;

        try {
            $isNewUser = DB::transaction(function () use ($professional, $email) {
                Membership::lockTenant($professional->tenant_id);
                $professional = Professional::query()->lockForUpdate()->whereKey($professional->id)->firstOrFail();

                // Re-read under lock: a concurrent invite or e-mail edit must not relink silently.
                if ($professional->membership_id !== null || $professional->email !== $email) {
                    throw ValidationException::withMessages(['access' => 'O cadastro mudou enquanto você convidava. Recarregue a página.']);
                }

                $isAdmin = UnitPolicy::membershipFor(request()->user(), $professional->tenant_id)?->hasRole(Role::NetworkAdmin) ?? false;
                $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
                $isNewUser = $user === null;
                $user ??= User::create(['name' => $professional->name, 'email' => $email, 'password' => Str::password(64)]);
                $membership = Membership::query()->firstOrNew(['tenant_id' => $professional->tenant_id, 'user_id' => $user->id]);

                // Attaching an existing account without access here, or reactivating a revoked access, is a
                // network admin decision (same power as the people screen); unit managers are refused.
                if (! $isAdmin && ! $isNewUser && (! $membership->exists || $membership->revoked_at !== null)) {
                    throw ValidationException::withMessages(['access' => 'Este e-mail pertence a uma conta sem acesso ativo aqui. Peça a um administrador de rede.']);
                }

                if (! $membership->exists) {
                    $membership->save();
                } elseif ($membership->revoked_at !== null) {
                    // Same rule as the people screen: a returning access gets only what this invitation grants.
                    $membership->roleAssignments()->delete();
                    $membership->reactivate();
                }

                if (Professional::query()->where('membership_id', $membership->id)->exists()) {
                    throw ValidationException::withMessages(['access' => 'Esta conta já está ligada a outro profissional.']);
                }

                if (! $membership->roleAssignments()->where('role', Role::Professional)->where('unit_id', $professional->base_unit_id)->exists()) {
                    $membership->grant(Role::Professional, $professional->baseUnit()->firstOrFail());
                }

                $professional->forceFill(['membership_id' => $membership->id])->save();
                AuditEvent::record('professional.invited', $professional->tenant_id, $professional, ['membership_id' => $membership->id, 'new_identity' => $isNewUser]);

                return $isNewUser;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['access' => 'Outro convite foi registrado ao mesmo tempo. Recarregue a página.']);
        }

        // Same wording whether or not the e-mail already had an account: no cross-client account discovery.
        $sent = ! $isNewUser || Password::broker()->sendResetLink(['email' => $email]) === PasswordBroker::RESET_LINK_SENT;
        Inertia::flash('toast', $sent
            ? ['type' => 'success', 'message' => 'Acesso liberado. O profissional pode entrar com o e-mail do cadastro.']
            : ['type' => 'error', 'message' => 'Acesso registrado, mas o e-mail de definição de senha não pôde ser enviado agora. Peça que use "Esqueceu sua senha?".']);

        return to_route('professionals.show', $professional);
    }

    /**
     * @return array{units: list<array{id: int, name: string}>, ufs: list<string>}
     */
    private static function options(Request $request): array
    {
        $membership = UnitPolicy::currentMembership($request->user());
        abort_if($membership === null, 403);

        return [
            'units' => array_values(Professional::manageableUnits($membership)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Unit $unit) => ['id' => $unit->id, 'name' => $unit->name])
                ->all()),
            'ufs' => Unit::UFS,
        ];
    }

    /**
     * Two concurrent saves with the same identifiers: the unique index wins, the user sees a field error.
     *
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     */
    private static function saving(callable $save): mixed
    {
        try {
            return $save();
        } catch (UniqueConstraintViolationException $exception) {
            $field = str_contains($exception->getMessage(), 'external_id') ? 'external_id' : 'council_uf';

            throw ValidationException::withMessages([$field => 'Já existe um profissional com estes dados.']);
        }
    }
}
