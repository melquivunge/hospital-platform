<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\RoleAssignment;
use App\Models\Sector;
use App\Models\Unit;
use App\Policies\UnitPolicy;
use App\Tenancy\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bed map (step 4.1). Unit managers and network admins set up the beds; bed management blocks, unblocks
 * and sends beds to cleaning; housekeeping (and bed management) marks them clean. Every state change is
 * a conditional update, so two people acting on the same bed at once produce one change and a clear
 * message for the other.
 */
class BedController extends Controller
{
    public function index(Request $request): Response
    {
        $membership = self::membership($request);
        $units = Unit::query()->whereKey(self::visibleUnitIds($membership))->orderBy('name')->get(['id', 'name']);
        abort_if($units->isEmpty(), 403);
        $unit = $units->firstWhere('id', $request->integer('unit')) ?? $units->first();
        $beds = Bed::query()->where('unit_id', $unit->id)->whereNull('deactivated_at')->orderBy('room')->orderBy('code')->get();
        $statusCounts = $beds->countBy('status');

        return Inertia::render('beds/index', [
            'units' => $units->map->only('id', 'name')->values(),
            'unit' => $unit->only('id', 'name'),
            'sectors' => Sector::query()->where('unit_id', $unit->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Sector $sector) => [
                    'id' => $sector->id,
                    'name' => $sector->name,
                    'beds' => $beds->where('sector_id', $sector->id)->values()
                        ->map(fn (Bed $bed) => $bed->only('id', 'room', 'code', 'type', 'sex_restriction', 'isolation', 'extra', 'status', 'block_reason')),
                ])->values(),
            'counts' => ['free' => $statusCounts->get('free', 0), 'reserved' => $statusCounts->get('reserved', 0), 'occupied' => $statusCounts->get('occupied', 0), 'cleaning' => $statusCounts->get('cleaning', 0), 'blocked' => $statusCounts->get('blocked', 0)],
            'can' => [
                'configure' => in_array($unit->id, self::unitsWith($membership, 'configure'), true),
                'manage' => in_array($unit->id, self::unitsWith($membership, 'manage'), true),
                'clean' => in_array($unit->id, self::unitsWith($membership, 'clean'), true),
            ],
        ]);
    }

    public function store(Request $request, Unit $unit): RedirectResponse
    {
        self::authorize($request, $unit->id, 'configure');
        $data = $request->validate([
            'sector_id' => ['required', 'integer', Rule::in(Sector::query()->where('unit_id', $unit->id)->pluck('id')->all())],
            'room' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:50'],
            'type' => ['required', Rule::in(Bed::TYPES)],
            'sex_restriction' => ['nullable', Rule::in(['female', 'male'])],
            'isolation' => ['boolean'],
            'extra' => ['boolean'],
        ], [], ['sector_id' => 'setor', 'room' => 'quarto', 'code' => 'leito', 'type' => 'tipo', 'sex_restriction' => 'restrição de sexo']);

        try {
            DB::transaction(function () use ($unit, $data) {
                $bed = Bed::query()->create([...$data, 'unit_id' => $unit->id, 'status_changed_at' => now()]);
                self::event($bed, null, 'free');
                AuditEvent::record('bed.created', $unit->tenant_id, $bed, ['unit_id' => $unit->id]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'Já existe um leito com este código neste setor.']);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Leito cadastrado.']);

        return to_route('beds.index', ['unit' => $unit->id]);
    }

    public function deactivate(Request $request, Bed $bed): RedirectResponse
    {
        self::authorize($request, $bed->unit_id, 'configure');
        DB::transaction(function () use ($bed) {
            $current = Bed::query()->whereKey($bed->id)->lockForUpdate()->first();
            $changed = Bed::query()->whereKey($bed->id)->whereNull('deactivated_at')->whereIn('status', ['free', 'blocked'])->update(['deactivated_at' => now(), 'updated_at' => now()]);
            if ($changed === 0) {
                throw ValidationException::withMessages(['bed' => 'Só um leito livre ou bloqueado pode ser desativado.']);
            }
            // The bed's timeline ends with its deactivation, in the same history as every other change.
            self::event($bed, $current?->status, (string) $current?->status, 'Leito desativado');
            AuditEvent::record('bed.deactivated', $bed->tenant_id, $bed, ['unit_id' => $bed->unit_id]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Leito desativado.']);

        return back();
    }

    public function block(Request $request, Bed $bed): RedirectResponse
    {
        self::authorize($request, $bed->unit_id, 'manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'motivo']);

        return self::transition($bed, 'free', 'blocked', $data['reason'], 'Leito bloqueado.');
    }

    public function unblock(Request $request, Bed $bed): RedirectResponse
    {
        self::authorize($request, $bed->unit_id, 'manage');

        return self::transition($bed, 'blocked', 'free', null, 'Leito liberado.');
    }

    public function sendToCleaning(Request $request, Bed $bed): RedirectResponse
    {
        self::authorize($request, $bed->unit_id, 'manage');

        return self::transition($bed, 'free', 'cleaning', null, 'Leito enviado para higienização.');
    }

    public function clean(Request $request, Bed $bed): RedirectResponse
    {
        self::authorize($request, $bed->unit_id, 'clean');

        return self::transition($bed, 'cleaning', 'free', null, 'Leito pronto.');
    }

    /**
     * Units whose bed map the member sees: those they configure, manage or clean.
     *
     * @return list<int>
     */
    public static function visibleUnitIds(?Membership $membership): array
    {
        return array_values(array_unique([...self::unitsWith($membership, 'configure'), ...self::unitsWith($membership, 'manage'), ...self::unitsWith($membership, 'clean')]));
    }

    private static function transition(Bed $bed, string $from, string $to, ?string $reason, string $message): RedirectResponse
    {
        DB::transaction(function () use ($bed, $from, $to, $reason) {
            $changed = Bed::query()->whereKey($bed->id)->whereNull('deactivated_at')->where('status', $from)
                ->update(['status' => $to, 'block_reason' => $to === 'blocked' ? $reason : null, 'status_changed_at' => now(), 'updated_at' => now()]);
            if ($changed === 0) {
                throw ValidationException::withMessages(['bed' => 'O leito mudou de estado nesse meio-tempo. Confira o mapa atualizado.']);
            }
            self::event($bed, $from, $to, $reason);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    private static function event(Bed $bed, ?string $from, string $to, ?string $reason = null): void
    {
        DB::table('bed_events')->insert([
            'tenant_id' => $bed->tenant_id, 'bed_id' => $bed->id, 'from_status' => $from, 'to_status' => $to,
            'reason' => $reason, 'user_id' => Auth::id(), 'occurred_at' => now(),
        ]);
    }

    private static function membership(Request $request): ?Membership
    {
        return UnitPolicy::currentMembership($request->user());
    }

    private static function authorize(Request $request, int $unitId, string $ability): void
    {
        abort_unless(in_array($unitId, self::unitsWith(self::membership($request), $ability), true), 403);
    }

    /**
     * @return list<int>
     */
    private static function unitsWith(?Membership $membership, string $ability): array
    {
        if ($membership === null || $membership->revoked_at !== null) {
            return [];
        }
        if ($ability === 'configure') {
            return array_values(array_map('intval', Professional::manageableUnits($membership)->pluck('id')->all()));
        }
        $roles = $ability === 'manage' ? [Role::BedManagement] : [Role::BedManagement, Role::Housekeeping];

        return array_values(array_unique($membership->roleAssignments
            ->filter(fn (RoleAssignment $assignment) => in_array($assignment->role, $roles, true))
            ->map(fn (RoleAssignment $assignment) => (int) $assignment->unit_id)->all()));
    }
}
