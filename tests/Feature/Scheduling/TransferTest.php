<?php

namespace Tests\Feature\Scheduling;

use App\Models\AuditEvent;
use App\Models\JobFunction;
use App\Models\Membership;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Shift;
use App\Models\ShiftApplication;
use App\Models\ShiftRequest;
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Scheduling\ApplicationStatus;
use App\Scheduling\RequestStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    private Membership $coordinator;

    private Unit $unit;

    private Sector $icu;

    private JobFunction $doctor;

    private Professional $ana;

    private Membership $anaAccess;

    private Professional $bruno;

    private Membership $brunoAccess;

    private Shift $shift;

    private Membership $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->icu = Sector::factory()->for($this->unit)->create(['name' => 'UTI']);
        $this->doctor = JobFunction::factory()->for($tenant)->create(['name' => 'Médico']);
        $this->coordinator = Membership::factory()->for($tenant)->create();
        $this->coordinator->grant(Role::ShiftCoordinator, $this->unit);

        app(CurrentTenant::class)->set($tenant);
        [$this->ana, $this->anaAccess] = $this->linkedProfessional('Ana Sintética');
        [$this->bruno, $this->brunoAccess] = $this->linkedProfessional('Bruno Sintético');
        $this->shift = $this->assignedShift($this->ana, '2026-11-12');
    }

    /**
     * @return array{Professional, Membership}
     */
    private function linkedProfessional(string $name, bool $qualified = true): array
    {
        $access = Membership::factory()->for($this->unit->tenant)->create();
        $access->grant(Role::Professional, $this->unit);
        $professional = Professional::factory()->create(['base_unit_id' => $this->unit->id, 'name' => $name, 'membership_id' => $access->id]);

        if ($qualified) {
            Qualification::request($professional, $this->unit, ['job_function_id' => $this->doctor->id, 'valid_from' => '2026-01-01', 'valid_until' => null], [$this->icu->id])
                ->transitionTo(QualificationStatus::Approved, null);
        }

        return [$professional, $access];
    }

    private function assignedShift(Professional $professional, string $day): Shift
    {
        $this->actingAs($this->coordinator->user)->post(route('units.shifts.store', $this->unit), [
            'sector_id' => $this->icu->id,
            'job_function_id' => $this->doctor->id,
            'professional_id' => $professional->id,
            'starts_at' => "{$day}T07:00",
            'ends_at' => "{$day}T19:00",
        ])->assertSessionHasNoErrors();
        $shift = Shift::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('shifts.publish', $shift))->assertSessionHasNoErrors();

        return $shift;
    }

    private function request(): ShiftRequest
    {
        return ShiftRequest::withoutTenantScope()->latest('id')->firstOrFail();
    }

    private function requestToBruno(): void
    {
        $this->actingAs($this->anaAccess->user)->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $this->bruno->id, 'note' => 'Compromisso familiar'])
            ->assertSessionHasNoErrors();
    }

    public function test_transfer_needs_acceptance_and_approval_before_the_shift_changes_hands(): void
    {
        $this->actingAs($this->anaAccess->user)->get(route('shifts.transfer.create', $this->shift))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('colleagues', 1)->where('colleagues.0.name', 'Bruno Sintético'));
        $this->requestToBruno();
        $this->assertSame($this->ana->id, $this->shift->fresh()?->professional_id);
        $this->get(route('shifts.mine'))->assertInertia(fn (Assert $page) => $page->where('shifts.0.requestPending', true));

        $this->actingAs($this->brunoAccess->user)->get(route('requests.index'))
            ->assertInertia(fn (Assert $page) => $page->has('mine', 1)->where('mine.0.canRespond', true)->where('mine.0.direction', 'received')->where('isCoordinator', false));
        $this->post(route('requests.respond', $this->request()), ['accept' => true])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::PendingCoordinator, $this->request()->status);
        $this->assertSame($this->ana->id, $this->shift->fresh()?->professional_id);

        $this->actingAs($this->coordinator->user)->get(route('requests.index'))
            ->assertInertia(fn (Assert $page) => $page->has('toDecide', 1)->where('toDecide.0.canDecide', true)->where('isCoordinator', true));
        $this->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasNoErrors();

        $this->assertSame($this->bruno->id, $this->shift->fresh()?->professional_id);
        $this->assertSame(RequestStatus::Completed, $this->request()->status);
        $this->assertSame(
            ['shift.transfer_requested', 'shift.transfer_accepted', 'shift.transferred'],
            AuditEvent::where('action', 'like', 'shift.transfer%')->orderBy('id')->pluck('action')->all(),
        );
        $this->actingAs($this->brunoAccess->user)->get(route('shifts.mine'))->assertInertia(fn (Assert $page) => $page->has('shifts', 1));
        // A completed request is final.
        $this->actingAs($this->coordinator->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasErrors('request');
    }

    public function test_refusals_and_cancellation_are_terminal_and_keep_the_schedule(): void
    {
        $this->requestToBruno();
        $this->actingAs($this->brunoAccess->user)->post(route('requests.respond', $this->request()), ['accept' => false])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Refused, $this->request()->status);
        $this->post(route('requests.respond', $this->request()), ['accept' => true])->assertSessionHasErrors('request');

        $this->requestToBruno();
        $this->actingAs($this->brunoAccess->user)->post(route('requests.cancel', $this->request()))->assertNotFound();
        $this->actingAs($this->anaAccess->user)->post(route('requests.cancel', $this->request()))->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Cancelled, $this->request()->status);

        $this->requestToBruno();
        $this->actingAs($this->brunoAccess->user)->post(route('requests.respond', $this->request()), ['accept' => true]);
        $this->actingAs($this->coordinator->user)->post(route('requests.decide', $this->request()), ['approve' => false])->assertSessionHasErrors('reason');
        $this->post(route('requests.decide', $this->request()), ['approve' => false, 'reason' => 'Equipe incompleta'])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Refused, $this->request()->status);
        $this->assertSame('Equipe incompleta', $this->request()->decision_reason);
        $this->assertSame($this->ana->id, $this->shift->fresh()?->professional_id);
    }

    public function test_one_pending_request_per_shift_and_no_mixing_with_announcements(): void
    {
        $this->requestToBruno();
        $this->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $this->bruno->id])
            ->assertSessionHasErrors(['shift' => 'Já existe uma solicitação pendente para este plantão.']);
        $this->post(route('shifts.announce', $this->shift))->assertSessionHasErrors(['shift' => 'Há uma solicitação pendente para este plantão.']);

        $this->post(route('requests.cancel', $this->request()));
        $this->post(route('shifts.announce', $this->shift))->assertSessionHasNoErrors();
        $this->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $this->bruno->id])
            ->assertSessionHasErrors(['shift' => 'Cancele o anúncio antes de transferir o plantão.']);
    }

    public function test_only_the_responsible_transfers_to_an_eligible_colleague_with_access(): void
    {
        [$caio] = $this->linkedProfessional('Caio Sem Habilitação', qualified: false);
        $noAccess = Professional::factory()->create(['base_unit_id' => $this->unit->id]);

        $this->actingAs($this->brunoAccess->user)->get(route('shifts.transfer.create', $this->shift))->assertForbidden();
        $this->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $this->ana->id])->assertForbidden();
        // Someone else's request is "not found", not a validation hint.
        $this->actingAs($this->anaAccess->user)->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $this->bruno->id]);
        $this->actingAs($this->brunoAccess->user)->post(route('requests.cancel', $this->request()))->assertNotFound();
        $this->actingAs($this->anaAccess->user)->post(route('requests.respond', $this->request()), ['accept' => true])->assertNotFound();
        $this->post(route('requests.cancel', $this->request()));

        $this->actingAs($this->anaAccess->user);
        $this->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $this->ana->id])->assertSessionHasErrors('recipient_id');
        $this->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $noAccess->id])
            ->assertSessionHasErrors(['recipient_id' => 'Este profissional ainda não tem acesso à plataforma para aceitar.']);
        $this->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $caio->id])
            ->assertSessionHasErrors(['professional_id' => 'Sem habilitação para esta função nesta unidade.']);
        $this->assertSame(1, ShiftRequest::withoutTenantScope()->count());
    }

    public function test_approval_revalidates_eligibility_and_conflicts(): void
    {
        $this->requestToBruno();
        $this->actingAs($this->brunoAccess->user)->post(route('requests.respond', $this->request()), ['accept' => true]);

        $other = $this->assignedShift($this->bruno, '2026-11-12');
        $this->actingAs($this->coordinator->user)->post(route('requests.decide', $this->request()), ['approve' => true])
            ->assertSessionHasErrors(['professional_id' => 'O profissional já tem outro plantão nesse horário.']);

        $this->post(route('shifts.cancel', $other), ['reason' => 'Ajuste']);
        Qualification::query()->where('professional_id', $this->bruno->id)->firstOrFail()->transitionTo(QualificationStatus::Suspended, 'Motivo sintético');
        $this->post(route('requests.decide', $this->request()), ['approve' => true])
            ->assertSessionHasErrors(['professional_id' => 'A habilitação está suspensa.']);

        $this->assertSame($this->ana->id, $this->shift->fresh()?->professional_id);
        $this->assertSame(RequestStatus::PendingCoordinator, $this->request()->status);
    }

    public function test_participants_and_outsiders_do_not_decide(): void
    {
        $this->requestToBruno();
        $this->actingAs($this->brunoAccess->user)->post(route('requests.respond', $this->request()), ['accept' => true]);

        // A coordinator who is the recipient cannot approve their own transfer.
        $this->brunoAccess->grant(Role::ShiftCoordinator, $this->unit);
        $this->actingAs($this->brunoAccess->user)->post(route('requests.decide', $this->request()), ['approve' => true])
            ->assertSessionHasErrors(['request' => 'Você participa desta transferência e não pode decidir. Outro coordenador precisa decidir.']);

        $otherUnit = Unit::factory()->for($this->unit->tenant)->create();
        $otherCoordinator = Membership::factory()->for($this->unit->tenant)->create();
        $otherCoordinator->grant(Role::ShiftCoordinator, $otherUnit);
        $this->actingAs($otherCoordinator->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertForbidden();
        $this->get(route('requests.index'))->assertInertia(fn (Assert $page) => $page->has('toDecide', 0));
        $this->actingAs($this->anaAccess->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertForbidden();

        app(CurrentTenant::class)->set(null);
        $outsider = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->actingAs($outsider->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertNotFound();
        $this->assertSame($this->ana->id, $this->shift->fresh()?->professional_id);
    }

    public function test_pending_requests_expire_at_the_start_and_close_when_the_shift_is_cancelled(): void
    {
        $this->requestToBruno();
        $this->travelTo($this->shift->starts_at->addMinute());
        $this->actingAs($this->brunoAccess->user)->post(route('requests.respond', $this->request()), ['accept' => true])->assertSessionHasErrors('request');
        $this->assertSame(RequestStatus::Expired, $this->request()->status);
        $this->travelBack();

        $later = $this->assignedShift($this->ana, '2026-11-20');
        $this->actingAs($this->anaAccess->user)->post(route('shifts.transfer.store', $later), ['recipient_id' => $this->bruno->id])->assertSessionHasNoErrors();
        $this->actingAs($this->coordinator->user)->post(route('shifts.cancel', $later), ['reason' => 'Setor fechado'])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Cancelled, $this->request()->status);
        $this->assertSame('Plantão cancelado.', $this->request()->decision_reason);
    }

    public function test_requests_page_needs_a_linked_professional_or_a_coordinator(): void
    {
        $auditor = Membership::factory()->for($this->unit->tenant)->withRole(Role::Auditor)->create();
        $this->actingAs($auditor->user)->get(route('requests.index'))->assertForbidden();
    }

    public function test_acceptance_revalidates_and_revoked_colleagues_are_not_offered(): void
    {
        [$caio, $caioAccess] = $this->linkedProfessional('Caio Sintético');
        $caioAccess->revoke();
        $this->actingAs($this->anaAccess->user)->get(route('shifts.transfer.create', $this->shift))
            ->assertInertia(fn (Assert $page) => $page->has('colleagues', 1)->where('colleagues.0.name', 'Bruno Sintético'));
        $this->post(route('shifts.transfer.store', $this->shift), ['recipient_id' => $caio->id])
            ->assertSessionHasErrors(['recipient_id' => 'Este profissional ainda não tem acesso à plataforma para aceitar.']);

        $this->requestToBruno();
        Qualification::query()->where('professional_id', $this->bruno->id)->firstOrFail()->transitionTo(QualificationStatus::Suspended, 'Motivo sintético');
        $this->actingAs($this->brunoAccess->user)->post(route('requests.respond', $this->request()), ['accept' => true])
            ->assertSessionHasErrors(['professional_id' => 'A habilitação está suspensa.']);
        $this->assertSame(RequestStatus::PendingRecipient, $this->request()->status);
    }

    public function test_requests_awaiting_the_coordinator_also_expire_with_reason_and_audit(): void
    {
        $this->requestToBruno();
        $this->actingAs($this->brunoAccess->user)->post(route('requests.respond', $this->request()), ['accept' => true]);
        $this->travelTo($this->shift->starts_at->addMinute());

        $this->actingAs($this->anaAccess->user)->get(route('shifts.mine'))->assertInertia(fn (Assert $page) => $page->where('shifts.0.requestPending', false));
        $this->assertSame(RequestStatus::Expired, $this->request()->status);
        $this->assertSame('O plantão começou antes da conclusão.', $this->request()->decision_reason);
        $this->assertTrue(AuditEvent::where('action', 'shift.transfer_expired')->exists());
        $this->actingAs($this->admin->user)->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasErrors('request');
        $this->assertSame($this->ana->id, $this->shift->fresh()?->professional_id);
    }

    public function test_network_admin_approves_and_an_earlier_vacancy_winner_is_marked_replaced(): void
    {
        $this->actingAs($this->coordinator->user)->post(route('units.shifts.store', $this->unit), [
            'sector_id' => $this->icu->id, 'job_function_id' => $this->doctor->id, 'starts_at' => '2026-11-14T07:00', 'ends_at' => '2026-11-14T19:00',
        ]);
        $vacancy = Shift::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('shifts.publish', $vacancy));
        $this->actingAs($this->anaAccess->user)->post(route('vacancies.apply', $vacancy));
        $application = ShiftApplication::withoutTenantScope()->latest('id')->firstOrFail();
        $this->actingAs($this->coordinator->user)->post(route('shift-applications.select', $application), ['reason' => 'Escolha'])->assertSessionHasNoErrors();

        $this->actingAs($this->anaAccess->user)->post(route('shifts.transfer.store', $vacancy), ['recipient_id' => $this->bruno->id])->assertSessionHasNoErrors();
        $this->actingAs($this->brunoAccess->user)->post(route('requests.respond', $this->request()), ['accept' => true]);
        $this->actingAs($this->admin->user)->get(route('requests.index'))->assertInertia(fn (Assert $page) => $page->has('toDecide', 1));
        $this->post(route('requests.decide', $this->request()), ['approve' => true])->assertSessionHasNoErrors();

        $this->assertSame($this->bruno->id, $vacancy->fresh()?->professional_id);
        $this->assertSame(ApplicationStatus::Replaced, $application->fresh()?->status);
    }

    public function test_database_allows_one_pending_request_per_shift(): void
    {
        $this->requestToBruno();
        $this->expectException(QueryException::class);
        ShiftRequest::withoutTenantScope()->insert([
            'tenant_id' => $this->shift->tenant_id, 'type' => 'transfer', 'shift_id' => $this->shift->id,
            'requester_professional_id' => $this->ana->id, 'recipient_professional_id' => $this->bruno->id,
            'status' => 'pending_coordinator', 'requested_by_user_id' => $this->anaAccess->user_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
