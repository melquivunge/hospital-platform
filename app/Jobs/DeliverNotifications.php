<?php

namespace App\Jobs;

use App\Tenancy\Role;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns audit events into inbox items (step 9a, V1-12): only people involved and the coordinators of the
 * unit, and only if their access is still active at delivery. Re-runnable: a cursor plus the unique
 * (user, event) index means a crash or a second run never duplicates nor loses a notification. Every
 * query filters by the event's tenant explicitly, so one worker serves all clients without a context.
 * Not unique on purpose: the cursor row lock serializes runs, so extra jobs only find nothing to do.
 */
class DeliverNotifications implements ShouldQueue
{
    use Queueable;

    private const BATCH = 500;

    private const LATE_COMMIT_MINUTES = 5;

    /** Actions that notify someone; the rest only advance the cursor. */
    private const ACTIONS = [
        'shift.published', 'shift.cancelled', 'shift.assigned', 'shift.application_created',
        'shift.transfer_requested', 'shift.transfer_accepted', 'shift.transferred', 'shift.transfer_refused',
        'shift.transfer_rejected', 'shift.transfer_cancelled', 'shift.transfer_expired',
        'shift.swap_requested', 'shift.swap_accepted', 'shift.swapped', 'shift.swap_refused',
        'shift.swap_rejected', 'shift.swap_cancelled', 'shift.swap_expired',
        'attendance.occurrence_reported', 'attendance.occurrence_granted', 'attendance.occurrence_denied',
    ];

    public function handle(): void
    {
        DB::transaction(function () {
            DB::table('notification_cursors')->insertOrIgnore(['name' => 'inbox', 'last_audit_event_id' => 0]);
            // ponytail: one cursor row serializes deliveries; partition by tenant if volume ever needs it.
            $cursor = (int) DB::table('notification_cursors')->where('name', 'inbox')->lockForUpdate()->value('last_audit_event_id');
            // Ids are taken at insert, not at commit: an event committed after a higher id was delivered sits
            // below the cursor. Recent events are read again (the unique index drops repeats).
            // ponytail: assumes no business transaction lasts longer than the window.
            $late = DB::table('audit_events')->where('id', '<=', $cursor)->whereIn('action', self::ACTIONS)->where('occurred_at', '>', now()->subMinutes(self::LATE_COMMIT_MINUTES))->get();
            $events = $late->concat(DB::table('audit_events')->where('id', '>', $cursor)->orderBy('id')->limit(self::BATCH)->get());

            foreach ($events as $event) {
                /** @var \stdClass $event */
                if ($event->tenant_id !== null && in_array($event->action, self::ACTIONS, true)) {
                    try {
                        // Savepoint per event: one broken event is reported and skipped, never blocks the cursor.
                        DB::transaction(fn () => $this->deliver($event));
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
                $cursor = max($cursor, $event->id);
            }

            DB::table('notification_cursors')->where('name', 'inbox')->update(['last_audit_event_id' => $cursor]);
        });

        if (DB::table('audit_events')->where('id', '>', (int) DB::table('notification_cursors')->where('name', 'inbox')->value('last_audit_event_id'))->exists()) {
            self::dispatch();
        }
    }

    private function deliver(\stdClass $event): void
    {
        foreach ($this->notifications($event) as [$userIds, $title, $url]) {
            foreach (array_unique($userIds) as $userId) {
                DB::table('user_notifications')->insertOrIgnore([
                    'tenant_id' => $event->tenant_id, 'user_id' => $userId, 'audit_event_id' => $event->id,
                    'title' => $title, 'url' => $url, 'created_at' => $event->occurred_at,
                ]);
            }
        }
    }

    /**
     * @return list<array{list<int>, string, string}>
     */
    private function notifications(\stdClass $event): array
    {
        $tenant = (int) $event->tenant_id;
        $context = $event->context === null ? [] : (array) json_decode($event->context, true);
        $shift = $event->subject_type === 'App\Models\Shift' ? DB::table('shifts')->where('tenant_id', $tenant)->where('id', $event->subject_id)->first() : null;

        if ($shift === null) {
            return [];
        }

        $shiftUrl = "/shifts/{$shift->id}";
        $holder = fn (?int $professionalId) => $this->userOf($tenant, $professionalId);
        $parties = function () use ($tenant, $context): array {
            $request = DB::table('shift_requests')->where('tenant_id', $tenant)->where('id', $context['request_id'] ?? 0)->first();

            return $request === null ? [] : [...$this->userOf($tenant, $request->requester_professional_id), ...$this->userOf($tenant, $request->recipient_professional_id)];
        };
        $kind = str_starts_with($event->action, 'shift.swap') ? 'troca' : 'transferência';

        return match (true) {
            // The holder at the event, not now: the shift may have changed hands before delivery.
            $event->action === 'shift.published' => [[$holder($context['professional_id'] ?? null), 'Novo plantão publicado para você', '/my-shifts']],
            $event->action === 'shift.cancelled' => [[$holder($shift->professional_id), 'Um plantão seu foi cancelado', '/my-shifts']],
            $event->action === 'shift.assigned' => [
                [$holder($context['professional_id'] ?? null), 'Você foi escolhido para um plantão', '/my-shifts'],
                [$holder($context['replaced_professional_id'] ?? null), 'Seu plantão anunciado foi assumido por um substituto', '/my-shifts'],
            ],
            $event->action === 'shift.application_created' => [[$this->coordinatorsOf($tenant, $shift->unit_id), 'Nova candidatura a uma vaga', $shiftUrl]],
            in_array($event->action, ['shift.transfer_requested', 'shift.swap_requested'], true) => [[$holder($context['to'] ?? null), "Você recebeu um pedido de {$kind}", '/requests']],
            in_array($event->action, ['shift.transfer_accepted', 'shift.swap_accepted'], true) => [[$this->coordinatorsOf($tenant, $shift->unit_id, $context['request_id'] ?? null), ucfirst($kind).' aguardando aprovação', '/requests']],
            in_array($event->action, ['shift.transferred', 'shift.swapped'], true) => [[$parties(), ucfirst($kind).' concluída', '/requests']],
            (bool) preg_match('/^shift\.(transfer|swap)_(refused|rejected|cancelled|expired)$/', $event->action) => [[$parties(), ucfirst($kind).' encerrada sem mudança na escala', '/requests']],
            $event->action === 'attendance.occurrence_reported' => [[$this->coordinatorsOf($tenant, $shift->unit_id), 'Nova ocorrência de presença', $shiftUrl]],
            in_array($event->action, ['attendance.occurrence_granted', 'attendance.occurrence_denied'], true) => [[$holder(DB::table('attendance_occurrences')->where('tenant_id', $tenant)->where('id', $context['occurrence_id'] ?? 0)->value('professional_id')), 'Sua ocorrência de presença foi decidida', '/my-shifts']],
            default => [],
        };
    }

    /**
     * @return list<int>
     */
    private function userOf(int $tenant, mixed $professionalId): array
    {
        return array_values(DB::table('professionals')->join('memberships', 'memberships.id', '=', 'professionals.membership_id')
            ->where('professionals.tenant_id', $tenant)->where('professionals.id', (int) $professionalId)
            ->whereNull('memberships.revoked_at')->pluck('memberships.user_id')->map(fn ($id) => (int) $id)->values()->all());
    }

    /**
     * Coordinators of the unit and, for a swap between units, of the other unit too. A unit without an
     * active coordinator falls back to the network admins, who can also decide: someone must hear of it.
     *
     * @return list<int>
     */
    private function coordinatorsOf(int $tenant, int $unitId, mixed $requestId = null): array
    {
        $units = [$unitId];
        $counterpart = $requestId === null ? null : DB::table('shift_requests')->where('tenant_id', $tenant)->where('id', $requestId)->value('counterpart_shift_id');
        if ($counterpart !== null) {
            $units[] = (int) DB::table('shifts')->where('tenant_id', $tenant)->where('id', $counterpart)->value('unit_id');
        }

        $users = [];
        foreach ($units as $unit) {
            $coordinators = $this->usersWithRole($tenant, Role::ShiftCoordinator, $unit);
            $users = [...$users, ...($coordinators !== [] ? $coordinators : $this->usersWithRole($tenant, Role::NetworkAdmin))];
        }

        return $users;
    }

    /**
     * @return list<int>
     */
    private function usersWithRole(int $tenant, Role $role, ?int $unitId = null): array
    {
        return array_values(DB::table('role_assignments')->join('memberships', 'memberships.id', '=', 'role_assignments.membership_id')
            ->where('role_assignments.tenant_id', $tenant)->where('role_assignments.role', $role->value)
            ->when($unitId !== null, fn ($query) => $query->where('role_assignments.unit_id', $unitId))
            ->whereNull('memberships.revoked_at')
            ->pluck('memberships.user_id')->map(fn ($id) => (int) $id)->values()->all());
    }
}
