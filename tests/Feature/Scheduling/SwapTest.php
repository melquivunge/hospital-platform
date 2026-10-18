<?php

namespace Tests\Feature\Scheduling;

use App\Models\AuditEvent;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Shift;
use App\Models\ShiftRequest;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Scheduling\RequestStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SwapTest extends TestCase
{
    use RefreshDatabase;

    private Membership $admin;

    private JobFunction $doctor;

    /** @var array<string, Unit> */
    private array $units = [];

    /** @var array<string, Sector> */
    private array $sectors = [];

    /** @var array<string, Membership> */
    private array $coordinators = [];

    private Professional $ana;

    private Membership $anaAccess;

    private Professional $bruno;

    private Membership $brunoAccess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $this->admin->tenant;
        $this->doctor = JobFunction::factory()->for($tenant)->create(['name' => 'Médico']);

        foreach (['A' => 'SP', 'B' => 'SP', 'C' => 'RJ'] as $key => $uf) {
            $this->units[$key] = Unit::factory()->for($tenant)->create(['name' => "Hospital {$key}", 'uf' => $uf, 'timezone' => 'America/Sao_Paulo']);
            $this->sectors[$key] = Sector::factory()->for($this->units[$key])->create(['name' => "UTI {$key}"]);
            $this->coordinators[$key] = Membership::factory()->for($tenant)->create();
            $this->coordinators[$key]->grant(Role::ShiftCoordinator, $this->units[$key]);
        }

        app(CurrentTenant::class)->set($tenant);
        [$this->ana, $this->anaAccess] = $this->linkedProfessional('Ana Sintética', 'A');
        [$this->bruno, $this->brunoAccess] = $this->linkedProfessional('Bruno Sintético', 'B');
    }

    /**
     * Linked professional with base unit $base and approved qualifications in every unit.
     *
     * @return array{Professional, Membership}
     */
    private function linkedProfessional(string $name, string $base): array
    {
        $access = Membership::factory()->for($this->admin->tenant)->create();
        $access->grant(Role::Professional, $this->units[$base]);
        $professional = Professional::factory()->create(['base_unit_id' => $this->units[$base]->id, 'name' => $name, 'membership_id' => $access->id]);

        foreach ($this->units as $key => $unit) {
            Qualification::request($professional, $unit, ['job_function_id' => $this->doctor->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$this->sectors[$key]->id])
                ->transitionTo(QualificationStatus::Approved, null);
        }

        return [$professional, $access];
    }

    private function shift(Professional $professional, string $unit, string $start, string $end): Shift
    {
        $this->actingAs($this->coordinators[$unit]->user)->post(route('units.shifts.store', $this->units[$unit]), [
            'sector_id' => $this->sectors[$unit]->id,
            'job_function_id' => $this->doctor->id,
            'professional_id' => $professional->id,
            'starts_at' => $start,
            'ends_at' => $end,
        ])->assertSessionHasNoErrors();
        $shift = Shift::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('shifts.publish', $shift))->assertSessionHasNoErrors();

        return $shift;
    }

    private function request(): ShiftRequest
    {
        return ShiftRequest::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function requestSwap(Shift $mine, Shift $theirs): void
    {
        $this->actingAs($this->anaAccess->user)->post(route('shifts.swap.store', $mine), ['counterpart_shift_id' => $theirs->id])->assertSessionHasNoErrors();
        $this->actingAs($this->brunoAccess->user)->post(route('requests.respond', $this->request()), ['accept' => true])->assertSessionHasNoErrors();
    }

    public function test_swap_in_one_unit_needs_one_approval_and_moves_both_shifts(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $theirs = $this->shift($this->bruno, 'A', '2026-11-13T07:00', '2026-11-13T19:00');

        $this->actingAs($this->anaAccess->user)->get(route('shifts.swap.create', $mine))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('options', 1)->where('options.0.id', $theirs->id)->where('options.0.colleague', 'Bruno Sintético')->missing('options.0.valueCents'));
        $this->requestSwap($mine, $theirs);
        $this->assertSame($this->ana->id, $mine->fresh()?->professional_id);

        $this->actingAs($this->coordinators['A']->user)->get(route('requests.index'))
            ->assertInertia(fn (Assert $page) => $page->has('toDecide', 1)->where('toDecide.0.type', 'swap')->where('toDecide.0.canDecide', true)->has('toDecide.0.approvals', 1));
        $this->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasNoErrors();

        $this->assertSame($this->bruno->id, $mine->fresh()?->professional_id);
        $this->assertSame($this->ana->id, $theirs->fresh()?->professional_id);
        $this->assertSame(RequestStatus::Completed, $this->request()->status);
        $this->assertSame(
            ['shift.swap_requested', 'shift.swap_accepted', 'shift.swapped'],
            AuditEvent::where('action', 'like', 'shift.swap%')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_swap_between_units_needs_both_units_and_applies_only_at_the_end(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $theirs = $this->shift($this->bruno, 'B', '2026-11-13T07:00', '2026-11-13T19:00');
        $this->requestSwap($mine, $theirs);

        $this->actingAs($this->coordinators['A']->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::PendingCoordinator, $this->request()->status);
        $this->assertNotNull($this->request()->origin_approved_at);
        $this->assertSame($this->ana->id, $mine->fresh()?->professional_id);
        $this->assertSame($this->bruno->id, $theirs->fresh()?->professional_id);

        // Unit A already decided: nothing left for it.
        $this->get(route('requests.index'))->assertInertia(fn (Assert $page) => $page->has('toDecide', 0));
        $this->post(route('requests.decide', $this->request()), ['approve' => true])
            ->assertSessionHasErrors(['request' => 'Esta solicitação não aguarda decisão da sua unidade.']);
        $this->actingAs($this->coordinators['C']->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertForbidden();

        $this->actingAs($this->coordinators['B']->user)->get(route('requests.index'))
            ->assertInertia(fn (Assert $page) => $page->has('toDecide', 1)->where('toDecide.0.approvals.0.approved', true)->where('toDecide.0.approvals.1.approved', false));
        $this->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasNoErrors();

        $this->assertSame($this->bruno->id, $mine->fresh()?->professional_id);
        $this->assertSame($this->ana->id, $theirs->fresh()?->professional_id);
        $this->assertSame(RequestStatus::Completed, $this->request()->status);
    }

    public function test_someone_managing_both_units_records_both_approvals(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $theirs = $this->shift($this->bruno, 'B', '2026-11-13T07:00', '2026-11-13T19:00');
        $this->requestSwap($mine, $theirs);

        $this->actingAs($this->admin->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Completed, $this->request()->status);
        $this->assertSame($this->ana->id, $theirs->fresh()?->professional_id);
    }

    public function test_one_refusal_ends_the_swap_and_participants_never_decide(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $theirs = $this->shift($this->bruno, 'B', '2026-11-13T07:00', '2026-11-13T19:00');
        $this->requestSwap($mine, $theirs);

        $this->anaAccess->grant(Role::ShiftCoordinator, $this->units['A']);
        $this->actingAs($this->anaAccess->user)->post(route('requests.decide', $this->request()), ['approve' => true])
            ->assertSessionHasErrors(['request' => 'Você participa desta troca e não pode decidir. Outro coordenador precisa decidir.']);

        $this->actingAs($this->coordinators['A']->user)->post(route('requests.decide', $this->request()), ['approve' => true]);
        $this->actingAs($this->coordinators['B']->user)->post(route('requests.decide', $this->request()), ['approve' => false, 'reason' => 'Equipe incompleta'])->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::Refused, $this->request()->status);
        $this->assertSame($this->ana->id, $mine->fresh()?->professional_id);
        $this->assertSame($this->bruno->id, $theirs->fresh()?->professional_id);
    }

    public function test_each_side_is_checked_against_the_uf_of_the_other_shift(): void
    {
        // Caio works in RJ; Ana's shift is in SP.
        [$caio] = $this->linkedProfessional('Caio Sintético', 'C');
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $theirs = $this->shift($caio, 'C', '2026-11-13T07:00', '2026-11-13T19:00');

        $this->actingAs($this->anaAccess->user)->post(route('shifts.swap.store', $mine), ['counterpart_shift_id' => $theirs->id])
            ->assertSessionHasErrors(['professional_id' => 'O plantão é em outra UF.']);
        $this->assertSame(0, ShiftRequest::withoutTenantScope()->count());
    }

    public function test_overlapping_shifts_can_be_swapped_but_a_third_conflict_blocks_both_sides(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $theirs = $this->shift($this->bruno, 'A', '2026-11-12T13:00', '2026-11-13T01:00');
        $this->requestSwap($mine, $theirs);
        $this->actingAs($this->coordinators['A']->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasNoErrors();
        $this->assertSame($this->bruno->id, $mine->fresh()?->professional_id);
        $this->assertSame($this->ana->id, $theirs->fresh()?->professional_id);

        // Ana now holds $theirs; swapping it for Bruno's night shift would overlap her extra shift.
        // The conflict is reported when the swap is requested, before anyone accepts or approves.
        $night = $this->shift($this->bruno, 'A', '2026-11-14T19:00', '2026-11-15T07:00');
        $this->shift($this->ana, 'A', '2026-11-15T01:00', '2026-11-15T05:00');
        $this->actingAs($this->anaAccess->user)->post(route('shifts.swap.store', $theirs), ['counterpart_shift_id' => $night->id])
            ->assertSessionHasErrors(['professional_id' => 'O profissional já tem outro plantão nesse horário.']);
        $this->assertSame(1, ShiftRequest::withoutTenantScope()->count());
    }

    public function test_the_colleagues_shift_is_locked_by_the_pending_swap(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $theirs = $this->shift($this->bruno, 'B', '2026-11-13T07:00', '2026-11-13T19:00');
        $this->actingAs($this->anaAccess->user)->post(route('shifts.swap.store', $mine), ['counterpart_shift_id' => $theirs->id])->assertSessionHasNoErrors();

        $this->actingAs($this->brunoAccess->user)->post(route('shifts.announce', $theirs))->assertSessionHasErrors(['shift' => 'Há uma solicitação pendente para este plantão.']);
        $this->post(route('shifts.transfer.store', $theirs), ['recipient_id' => $this->ana->id])->assertSessionHasErrors(['shift' => 'Já existe uma solicitação pendente para este plantão.']);
        $this->get(route('shifts.mine'))->assertInertia(fn (Assert $page) => $page->where('shifts.0.requestPending', true));

        $this->actingAs($this->coordinators['B']->user)->post(route('shifts.cancel', $theirs), ['reason' => 'Setor fechado'])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Cancelled, $this->request()->status);
    }

    public function test_swap_expires_when_either_shift_starts(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-20T07:00', '2026-11-20T19:00');
        $theirs = $this->shift($this->bruno, 'B', '2026-11-12T07:00', '2026-11-12T19:00');
        $this->requestSwap($mine, $theirs);

        $this->travelTo($theirs->starts_at->addMinute());
        $this->actingAs($this->coordinators['A']->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasErrors('request');
        $this->assertSame(RequestStatus::Expired, $this->request()->status);
        $this->assertTrue(AuditEvent::where('action', 'shift.swap_expired')->exists());
    }

    public function test_a_conflict_after_the_first_unit_approved_keeps_both_sides_and_that_approval(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $theirs = $this->shift($this->bruno, 'B', '2026-11-13T07:00', '2026-11-13T19:00');
        $this->requestSwap($mine, $theirs);
        $this->actingAs($this->coordinators['A']->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasNoErrors();

        // Ana gets another shift overlapping the one she would receive.
        $this->shift($this->ana, 'A', '2026-11-13T10:00', '2026-11-13T12:00');
        $this->actingAs($this->coordinators['B']->user)->post(route('requests.decide', $this->request()), ['approve' => true])
            ->assertSessionHasErrors(['professional_id' => 'O profissional já tem outro plantão nesse horário.']);

        $this->assertSame($this->ana->id, $mine->fresh()?->professional_id);
        $this->assertSame($this->bruno->id, $theirs->fresh()?->professional_id);
        $this->assertSame(RequestStatus::PendingCoordinator, $this->request()->status);
        $this->assertNotNull($this->request()->origin_approved_at);
        $this->assertNull($this->request()->counterpart_approved_at);
    }

    public function test_cancelling_a_shift_after_a_partial_approval_ends_the_swap(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $theirs = $this->shift($this->bruno, 'B', '2026-11-13T07:00', '2026-11-13T19:00');
        $this->requestSwap($mine, $theirs);
        $this->actingAs($this->coordinators['A']->user)->post(route('requests.decide', $this->request()), ['approve' => true]);

        $this->actingAs($this->coordinators['B']->user)->post(route('shifts.cancel', $theirs), ['reason' => 'Setor fechado'])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Cancelled, $this->request()->status);
        $this->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasErrors('request');
        $this->assertSame($this->ana->id, $mine->fresh()?->professional_id);
    }

    public function test_drafts_vacancies_and_other_clients_shifts_get_the_same_answer(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $this->actingAs($this->coordinators['B']->user)->post(route('units.shifts.store', $this->units['B']), [
            'sector_id' => $this->sectors['B']->id, 'job_function_id' => $this->doctor->id, 'professional_id' => $this->bruno->id,
            'starts_at' => '2026-11-13T07:00', 'ends_at' => '2026-11-13T19:00',
        ]);
        $draft = Shift::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('units.shifts.store', $this->units['B']), [
            'sector_id' => $this->sectors['B']->id, 'job_function_id' => $this->doctor->id,
            'starts_at' => '2026-11-14T07:00', 'ends_at' => '2026-11-14T19:00',
        ]);
        $vacancy = Shift::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('shifts.publish', $vacancy));

        $this->actingAs($this->anaAccess->user);
        foreach ([$draft->id, $vacancy->id] as $id) {
            $this->post(route('shifts.swap.store', $mine), ['counterpart_shift_id' => $id])
                ->assertSessionHasErrors(['counterpart_shift_id' => 'Escolha um plantão publicado de um colega.']);
        }
        $this->post(route('shifts.swap.store', $mine), ['counterpart_shift_id' => 999999])
            ->assertSessionHasErrors(['counterpart_shift_id' => 'Plantão inválido.']);
        $this->assertSame(0, ShiftRequest::withoutTenantScope()->count());
    }

    public function test_the_option_list_only_offers_shifts_in_the_same_uf(): void
    {
        [$caio] = $this->linkedProfessional('Caio Sintético', 'C');
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $this->shift($caio, 'C', '2026-11-13T07:00', '2026-11-13T19:00');
        $same = $this->shift($this->bruno, 'B', '2026-11-14T07:00', '2026-11-14T19:00');

        $this->actingAs($this->anaAccess->user)->get(route('shifts.swap.create', $mine))
            ->assertInertia(fn (Assert $page) => $page->has('options', 1)->where('options.0.id', $same->id));
    }

    public function test_only_the_responsible_offers_a_swap_and_never_for_own_or_open_shifts(): void
    {
        $mine = $this->shift($this->ana, 'A', '2026-11-12T07:00', '2026-11-12T19:00');
        $other = $this->shift($this->ana, 'A', '2026-11-13T07:00', '2026-11-13T19:00');

        $this->actingAs($this->brunoAccess->user)->get(route('shifts.swap.create', $mine))->assertForbidden();
        $this->post(route('shifts.swap.store', $mine), ['counterpart_shift_id' => $other->id])->assertForbidden();
        $this->actingAs($this->anaAccess->user)->post(route('shifts.swap.store', $mine), ['counterpart_shift_id' => $other->id])
            ->assertSessionHasErrors(['counterpart_shift_id' => 'Escolha um plantão publicado de um colega.']);
        $this->post(route('shifts.swap.store', $mine), ['counterpart_shift_id' => $mine->id])->assertSessionHasErrors('counterpart_shift_id');
    }
}
