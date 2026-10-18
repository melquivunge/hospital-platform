<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotifications;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Shift;
use App\Models\ShiftRequest;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{admin: Membership, coordinator: Membership, ana: Membership, bruno: Membership, anaPro: Professional, brunoPro: Professional, unit: Unit, sector: Sector, function: JobFunction}
     */
    private function network(): array
    {
        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $admin->tenant;
        $unit = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $sector = Sector::factory()->for($unit)->create();
        $function = JobFunction::factory()->for($tenant)->create();
        $coordinator = Membership::factory()->for($tenant)->create();
        $coordinator->grant(Role::ShiftCoordinator, $unit);
        app(CurrentTenant::class)->set($tenant);
        $people = [];
        foreach (['ana', 'bruno'] as $name) {
            $access = Membership::factory()->for($tenant)->create();
            $access->grant(Role::Professional, $unit);
            $professional = Professional::factory()->create(['base_unit_id' => $unit->id, 'membership_id' => $access->id]);
            Qualification::request($professional, $unit, ['job_function_id' => $function->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$sector->id])
                ->transitionTo(QualificationStatus::Approved, null);
            $people[$name] = [$access, $professional];
        }
        app(CurrentTenant::class)->set(null);

        return ['admin' => $admin, 'coordinator' => $coordinator, 'ana' => $people['ana'][0], 'bruno' => $people['bruno'][0], 'anaPro' => $people['ana'][1], 'brunoPro' => $people['bruno'][1], 'unit' => $unit, 'sector' => $sector, 'function' => $function];
    }

    private function publishFor(array $n, Professional $professional): Shift
    {
        $this->actingAs($n['coordinator']->user)->post(route('units.shifts.store', $n['unit']), [
            'sector_id' => $n['sector']->id, 'job_function_id' => $n['function']->id, 'professional_id' => $professional->id,
            'starts_at' => '2026-11-12T07:00', 'ends_at' => '2026-11-12T19:00',
        ])->assertSessionHasNoErrors();
        $shift = Shift::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('shifts.publish', $shift))->assertSessionHasNoErrors();

        return $shift;
    }

    /**
     * @return list<string>
     */
    private function titlesOf(Membership $access): array
    {
        return DB::table('user_notifications')->where('user_id', $access->user_id)->orderBy('id')->pluck('title')->all();
    }

    public function test_only_people_involved_and_the_units_coordinators_are_notified_once(): void
    {
        Queue::fake();
        $n = $this->network();
        $shift = $this->publishFor($n, $n['anaPro']);
        $this->actingAs($n['ana']->user)->post(route('shifts.transfer.store', $shift), ['recipient_id' => $n['brunoPro']->id])->assertSessionHasNoErrors();
        $request = ShiftRequest::withoutTenantScope()->sole();
        $this->actingAs($n['bruno']->user)->post(route('requests.respond', $request), ['accept' => true]);
        $this->actingAs($n['coordinator']->user)->post(route('requests.decide', $request), ['approve' => true])->assertSessionHasNoErrors();
        Queue::assertPushed(DeliverNotifications::class);

        (new DeliverNotifications)->handle();
        (new DeliverNotifications)->handle();

        $this->assertSame(['Novo plantão publicado para você', 'Transferência concluída'], $this->titlesOf($n['ana']));
        $this->assertSame(['Você recebeu um pedido de transferência', 'Transferência concluída'], $this->titlesOf($n['bruno']));
        $this->assertSame(['Transferência aguardando aprovação'], $this->titlesOf($n['coordinator']));
    }

    public function test_revoked_access_receives_nothing_and_clients_never_mix(): void
    {
        Queue::fake();
        $first = $this->network();
        $second = $this->network();
        $this->publishFor($first, $first['anaPro']);
        $first['bruno']->revoke();
        $shift = $this->publishFor($second, $second['brunoPro']);

        (new DeliverNotifications)->handle();

        $this->assertSame(['Novo plantão publicado para você'], $this->titlesOf($first['ana']));
        $this->assertSame(['Novo plantão publicado para você'], $this->titlesOf($second['bruno']));
        $this->assertSame([], $this->titlesOf($second['ana']));
        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $second['bruno']->user_id)->where('tenant_id', $shift->tenant_id)->count());
        $this->assertSame((int) DB::table('audit_events')->max('id'), (int) DB::table('notification_cursors')->value('last_audit_event_id'));
    }

    public function test_without_an_active_coordinator_the_network_admins_are_told(): void
    {
        Queue::fake();
        $n = $this->network();
        $shift = $this->publishFor($n, $n['anaPro']);
        $this->actingAs($n['ana']->user)->post(route('shifts.transfer.store', $shift), ['recipient_id' => $n['brunoPro']->id])->assertSessionHasNoErrors();
        $n['coordinator']->revoke();
        $this->actingAs($n['bruno']->user)->post(route('requests.respond', ShiftRequest::withoutTenantScope()->sole()), ['accept' => true]);

        (new DeliverNotifications)->handle();

        $this->assertSame(['Transferência aguardando aprovação'], $this->titlesOf($n['admin']));
        $this->assertSame([], $this->titlesOf($n['coordinator']));
    }

    public function test_an_event_committed_below_the_cursor_is_still_delivered(): void
    {
        Queue::fake();
        $n = $this->network();
        $this->publishFor($n, $n['anaPro']);
        // Another transaction already delivered a higher id before this one committed.
        DB::table('notification_cursors')->where('name', 'inbox')->update(['last_audit_event_id' => (int) DB::table('audit_events')->max('id') + 10]);

        (new DeliverNotifications)->handle();
        (new DeliverNotifications)->handle();

        $this->assertSame(['Novo plantão publicado para você'], $this->titlesOf($n['ana']));
    }

    public function test_inbox_shows_only_own_notifications_and_marks_them_read(): void
    {
        Queue::fake();
        $n = $this->network();
        $this->publishFor($n, $n['anaPro']);
        (new DeliverNotifications)->handle();

        $this->actingAs($n['ana']->user)->get(route('notifications.index'))
            ->assertInertia(fn (Assert $page) => $page->has('notifications', 1)->where('notifications.0.read', false)->where('unreadNotifications', 1));
        $this->post(route('notifications.read-all'))->assertRedirect(route('notifications.index'));
        $this->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page->where('notifications.0.read', true)->where('unreadNotifications', 0));
        $this->actingAs($n['bruno']->user)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page->has('notifications', 0));
    }
}
