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
use App\Models\Unit;
use App\Professionals\QualificationStatus;
use App\Scheduling\ApplicationStatus;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Role;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VacancyTest extends TestCase
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

    private Shift $vacancy;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $tenant = $admin->tenant;
        $this->unit = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->icu = Sector::factory()->for($this->unit)->create(['name' => 'UTI']);
        $this->doctor = JobFunction::factory()->for($tenant)->create(['name' => 'Médico']);
        $this->coordinator = Membership::factory()->for($tenant)->create();
        $this->coordinator->grant(Role::ShiftCoordinator, $this->unit);

        app(CurrentTenant::class)->set($tenant);
        [$this->ana, $this->anaAccess] = $this->linkedProfessional('Ana Sintética');
        [$this->bruno, $this->brunoAccess] = $this->linkedProfessional('Bruno Sintético');

        $this->actingAs($this->coordinator->user)->post(route('units.shifts.store', $this->unit), [
            'sector_id' => $this->icu->id,
            'job_function_id' => $this->doctor->id,
            'starts_at' => '2026-11-10T07:00',
            'ends_at' => '2026-11-10T19:00',
        ])->assertSessionHasNoErrors();
        $this->vacancy = Shift::withoutTenantScope()->latest('id')->firstOrFail();
        $this->post(route('shifts.publish', $this->vacancy))->assertSessionHasNoErrors();
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

    private function application(Professional $professional): ShiftApplication
    {
        return ShiftApplication::withoutTenantScope()->where('professional_id', $professional->id)->latest('id')->firstOrFail();
    }

    public function test_professional_applies_and_withdraws_without_becoming_responsible(): void
    {
        $this->actingAs($this->anaAccess->user)->get(route('vacancies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('vacancies', 1)->where('vacancies.0.sector', 'UTI')->where('vacancies.0.start', '07:00'));

        $this->post(route('vacancies.apply', $this->vacancy))->assertSessionHasNoErrors();
        $this->assertNull($this->vacancy->fresh()?->professional_id);
        $this->post(route('vacancies.apply', $this->vacancy))->assertSessionHasErrors(['application' => 'Você já se candidatou a esta vaga.']);

        $this->get(route('vacancies.show', $this->vacancy))
            ->assertInertia(fn (Assert $page) => $page->where('canWithdraw', true)->where('canApply', false)->where('vacancy.application.status', 'active'));

        $this->post(route('vacancies.withdraw', $this->vacancy))->assertSessionHasNoErrors();
        $this->assertSame(ApplicationStatus::Withdrawn, $this->application($this->ana)->status);

        // Applying again after withdrawing starts a new application and keeps the history.
        $this->post(route('vacancies.apply', $this->vacancy))->assertSessionHasNoErrors();
        $this->assertSame(2, ShiftApplication::withoutTenantScope()->where('professional_id', $this->ana->id)->count());
        $this->assertSame(['shift.application_created', 'shift.application_withdrawn', 'shift.application_created'], AuditEvent::where('action', 'like', 'shift.application%')->orderBy('id')->pluck('action')->all());
    }

    public function test_ineligible_professional_neither_sees_nor_applies(): void
    {
        [, $access] = $this->linkedProfessional('Caio Sem Habilitação', qualified: false);

        $this->actingAs($access->user)->get(route('vacancies.index'))->assertInertia(fn (Assert $page) => $page->has('vacancies', 0));
        $this->post(route('vacancies.apply', $this->vacancy))->assertSessionHasErrors(['professional_id' => 'Sem habilitação para esta função nesta unidade.']);
        $this->assertSame(0, ShiftApplication::withoutTenantScope()->count());
    }

    public function test_coordinator_selects_with_reason_and_the_other_candidates_are_closed(): void
    {
        $this->actingAs($this->anaAccess->user)->post(route('vacancies.apply', $this->vacancy));
        $this->actingAs($this->brunoAccess->user)->post(route('vacancies.apply', $this->vacancy));
        $ana = $this->application($this->ana);

        $this->actingAs($this->coordinator->user)->get(route('shifts.show', $this->vacancy))
            ->assertInertia(fn (Assert $page) => $page->where('canSelect', true)->has('applications', 2)->where('applications.0.blocks', []));

        $this->post(route('shift-applications.select', $ana), ['reason' => ' '])->assertSessionHasErrors('reason');
        $this->post(route('shift-applications.select', $ana), ['reason' => 'Experiência em UTI.'])->assertSessionHasNoErrors();

        $shift = $this->vacancy->fresh();
        $this->assertSame($this->ana->id, $shift?->professional_id);
        $this->assertNotNull($shift?->qualification_id);
        $this->assertSame(ApplicationStatus::Selected, $ana->fresh()?->status);
        $this->assertSame('Experiência em UTI.', $ana->fresh()?->decision_reason);
        $this->assertSame(ApplicationStatus::NotSelected, $this->application($this->bruno)->status);
        $this->assertSame('Experiência em UTI.', AuditEvent::where('action', 'shift.assigned')->firstOrFail()->context['reason'] ?? null);

        // A second (stale) choice finds the vacancy filled and does not overwrite the first one.
        $this->post(route('shift-applications.select', $this->application($this->bruno)), ['reason' => 'Outra escolha'])
            ->assertSessionHasErrors(['shift' => 'Esta vaga já foi preenchida.']);
        $this->assertSame($this->ana->id, $this->vacancy->fresh()?->professional_id);

        $this->actingAs($this->anaAccess->user)->get(route('shifts.mine'))->assertInertia(fn (Assert $page) => $page->has('shifts', 1));
        $this->post(route('vacancies.withdraw', $this->vacancy))->assertSessionHasErrors('application');
    }

    public function test_withdrawn_or_no_longer_eligible_candidate_is_not_selected_nor_replaced(): void
    {
        $this->actingAs($this->anaAccess->user)->post(route('vacancies.apply', $this->vacancy));
        $this->post(route('vacancies.withdraw', $this->vacancy));
        $this->actingAs($this->brunoAccess->user)->post(route('vacancies.apply', $this->vacancy));

        $this->actingAs($this->coordinator->user)->post(route('shift-applications.select', $this->application($this->ana)), ['reason' => 'Motivo'])
            ->assertSessionHasErrors('application');

        Qualification::query()->where('professional_id', $this->bruno->id)->firstOrFail()->transitionTo(QualificationStatus::Suspended, 'Motivo sintético');
        $this->post(route('shift-applications.select', $this->application($this->bruno)), ['reason' => 'Motivo'])
            ->assertSessionHasErrors(['professional_id' => 'A habilitação está suspensa.']);

        $this->assertNull($this->vacancy->fresh()?->professional_id);
        $this->assertSame(ApplicationStatus::Active, $this->application($this->bruno)->status);
    }

    public function test_selection_rejects_a_conflicting_shift(): void
    {
        $this->actingAs($this->anaAccess->user)->post(route('vacancies.apply', $this->vacancy));
        $this->actingAs($this->coordinator->user)->post(route('units.shifts.store', $this->unit), [
            'sector_id' => $this->icu->id,
            'job_function_id' => $this->doctor->id,
            'professional_id' => $this->ana->id,
            'starts_at' => '2026-11-10T12:00',
            'ends_at' => '2026-11-10T20:00',
        ])->assertSessionHasNoErrors();

        $this->post(route('shift-applications.select', $this->application($this->ana)), ['reason' => 'Motivo'])
            ->assertSessionHasErrors(['professional_id' => 'O profissional já tem outro plantão nesse horário.']);
        $this->assertNull($this->vacancy->fresh()?->professional_id);
        $this->assertSame(ApplicationStatus::Active, $this->application($this->ana)->status);
    }

    public function test_nobody_selects_themselves(): void
    {
        $this->coordinator->grant(Role::Professional, $this->unit);
        Professional::query()->whereKey($this->ana->id)->update(['membership_id' => null]);
        Professional::query()->whereKey($this->ana->id)->update(['membership_id' => $this->coordinator->id]);

        $this->actingAs($this->coordinator->user)->post(route('vacancies.apply', $this->vacancy))->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('shift-applications.select', $this->application($this->ana)), ['reason' => 'Motivo'])
            ->assertSessionHasErrors(['application' => 'Você não pode escolher a si mesmo. Outro coordenador precisa decidir.']);
        $this->assertNull($this->vacancy->fresh()?->professional_id);
    }

    public function test_access_is_limited_to_linked_professionals_and_the_units_coordinators(): void
    {
        $this->actingAs($this->anaAccess->user)->post(route('vacancies.apply', $this->vacancy));
        $application = $this->application($this->ana);

        $otherUnit = Unit::factory()->for($this->unit->tenant)->create();
        $otherCoordinator = Membership::factory()->for($this->unit->tenant)->create();
        $otherCoordinator->grant(Role::ShiftCoordinator, $otherUnit);
        $this->actingAs($otherCoordinator->user)->post(route('shift-applications.select', $application), ['reason' => 'Motivo'])->assertForbidden();
        $this->get(route('vacancies.index'))->assertForbidden();

        // A candidate cannot select their own application through the coordinator route.
        $this->actingAs($this->anaAccess->user)->post(route('shift-applications.select', $application), ['reason' => 'Motivo'])->assertForbidden();

        // Another client's user does not find the vacancy at all.
        app(CurrentTenant::class)->set(null);
        $outsider = Membership::factory()->withRole(Role::NetworkAdmin)->create();
        $this->actingAs($outsider->user)->post(route('shift-applications.select', $application), ['reason' => 'Motivo'])->assertNotFound();
        $this->get(route('shifts.show', $this->vacancy))->assertNotFound();
    }

    public function test_drafts_are_not_vacancies_and_cancellation_closes_applications(): void
    {
        $this->actingAs($this->coordinator->user)->post(route('units.shifts.store', $this->unit), [
            'sector_id' => $this->icu->id,
            'job_function_id' => $this->doctor->id,
            'starts_at' => '2026-11-12T07:00',
            'ends_at' => '2026-11-12T19:00',
        ]);
        $draft = Shift::withoutTenantScope()->latest('id')->firstOrFail();

        $this->actingAs($this->anaAccess->user)->get(route('vacancies.show', $draft))->assertNotFound();
        $this->post(route('vacancies.apply', $draft))->assertSessionHasErrors('shift');
        $this->post(route('vacancies.apply', $this->vacancy));

        $this->actingAs($this->coordinator->user)->post(route('shifts.cancel', $this->vacancy), ['reason' => 'Setor fechado'])->assertSessionHasNoErrors();
        $this->assertSame(ApplicationStatus::NotSelected, $this->application($this->ana)->status);
        $this->post(route('shift-applications.select', $this->application($this->ana)), ['reason' => 'Motivo'])->assertSessionHasErrors('shift');
        $this->assertNull($this->vacancy->fresh()?->professional_id);

        // Unqualified professionals do not open vacancies by id either.
        [, $access] = $this->linkedProfessional('Caio Sem Habilitação', qualified: false);
        $this->actingAs($access->user)->get(route('vacancies.show', $this->vacancy))->assertNotFound();

        $this->actingAs($this->anaAccess->user)->get(route('vacancies.show', $this->vacancy))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canApply', false)->where('vacancy.application.status', 'not_selected'));
    }

    public function test_database_allows_a_single_winner_per_vacancy(): void
    {
        $this->actingAs($this->anaAccess->user)->post(route('vacancies.apply', $this->vacancy));
        $this->actingAs($this->brunoAccess->user)->post(route('vacancies.apply', $this->vacancy));
        $selected = ['status' => 'selected', 'decided_by_user_id' => $this->coordinator->user_id, 'decision_reason' => 'Motivo', 'decided_at' => now()];
        ShiftApplication::withoutTenantScope()->whereKey($this->application($this->ana)->id)->update($selected);

        $this->expectException(QueryException::class);
        ShiftApplication::withoutTenantScope()->whereKey($this->application($this->bruno)->id)->update($selected);
    }
}
