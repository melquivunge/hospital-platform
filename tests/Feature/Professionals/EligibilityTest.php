<?php

namespace Tests\Feature\Professionals;

use App\Models\JobFunction;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\Unit;
use App\Professionals\Eligibility;
use App\Professionals\EligibilityReason;
use App\Professionals\QualificationStatus;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Unit $hospitalA;

    private Unit $hospitalB;

    private Sector $icuA;

    private Sector $wardA;

    private Sector $icuB;

    private JobFunction $doctor;

    private Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant);

        $this->hospitalA = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->hospitalB = Unit::factory()->for($tenant)->create(['uf' => 'SP', 'timezone' => 'America/Sao_Paulo']);
        $this->icuA = Sector::factory()->for($this->hospitalA)->create(['name' => 'UTI']);
        $this->wardA = Sector::factory()->for($this->hospitalA)->create(['name' => 'Enfermaria']);
        $this->icuB = Sector::factory()->for($this->hospitalB)->create(['name' => 'UTI']);
        $this->doctor = JobFunction::factory()->for($tenant)->create();
        $this->professional = Professional::factory()->create(['base_unit_id' => $this->hospitalA->id]);
    }

    private function qualify(Unit $unit, Sector $sector, QualificationStatus $status = QualificationStatus::Approved, string $from = '2026-01-01', ?string $until = null): Qualification
    {
        $qualification = Qualification::request($this->professional, $unit, ['job_function_id' => $this->doctor->id, 'valid_from' => $from, 'valid_until' => $until], [$sector->id]);

        if ($status !== QualificationStatus::Pending) {
            $qualification->transitionTo(QualificationStatus::Approved, null);
        }

        if (in_array($status, [QualificationStatus::Suspended, QualificationStatus::Revoked], true)) {
            $qualification->transitionTo($status, 'Motivo sintético');
        }

        return $qualification;
    }

    /**
     * Local shift times in the unit's timezone.
     */
    private function check(Sector $sector, string $start = '2026-10-20 07:00', string $end = '2026-10-20 19:00', ?string $referenceUf = null): Eligibility
    {
        $timezone = 'America/Sao_Paulo';

        return Eligibility::check(
            $this->professional->fresh() ?? $this->professional,
            $sector,
            $this->doctor,
            CarbonImmutable::parse($start, $timezone),
            CarbonImmutable::parse($end, $timezone),
            $referenceUf,
        );
    }

    public function test_approved_qualification_in_unit_and_sector_is_eligible(): void
    {
        $qualification = $this->qualify($this->hospitalA, $this->icuA);

        $result = $this->check($this->icuA);

        $this->assertTrue($result->eligible());
        $this->assertSame($qualification->id, $result->qualificationId);
    }

    public function test_approval_in_one_unit_does_not_qualify_for_another(): void
    {
        $this->qualify($this->hospitalA, $this->icuA);

        $this->assertSame([EligibilityReason::NoQualification], $this->check($this->icuB)->reasons);
    }

    public function test_sector_must_be_authorized(): void
    {
        $this->qualify($this->hospitalA, $this->icuA);

        $this->assertSame([EligibilityReason::SectorNotAuthorized], $this->check($this->wardA)->reasons);
    }

    public function test_pending_suspended_and_revoked_block(): void
    {
        $this->qualify($this->hospitalA, $this->icuA, QualificationStatus::Pending);
        $this->assertSame([EligibilityReason::QualificationPending], $this->check($this->icuA)->reasons);

        $qualification = Qualification::query()->firstOrFail();
        $qualification->transitionTo(QualificationStatus::Approved, null);
        $qualification->transitionTo(QualificationStatus::Suspended, 'Motivo sintético');
        $this->assertSame([EligibilityReason::QualificationSuspended], $this->check($this->icuA)->reasons);

        $qualification->transitionTo(QualificationStatus::Revoked, 'Motivo sintético');
        $this->assertSame([EligibilityReason::QualificationRevoked], $this->check($this->icuA)->reasons);
    }

    public function test_validity_must_cover_the_whole_shift_including_midnight_crossing(): void
    {
        $this->qualify($this->hospitalA, $this->icuA, until: '2026-10-31');

        // Night shift 31/10 19:00 → 01/11 07:00 local: ends after the last valid day.
        $this->assertSame([EligibilityReason::OutsideValidity], $this->check($this->icuA, '2026-10-31 19:00', '2026-11-01 07:00')->reasons);
        // Ending exactly at midnight is still inside the last valid day.
        $this->assertTrue($this->check($this->icuA, '2026-10-31 12:00', '2026-11-01 00:00')->eligible());
        // Before the first valid day.
        $this->assertSame([EligibilityReason::OutsideValidity], $this->check($this->icuA, '2025-12-31 19:00', '2026-01-01 07:00')->reasons);
    }

    public function test_validity_uses_the_units_local_dates(): void
    {
        $manaus = Unit::factory()->create(['tenant_id' => $this->hospitalA->tenant_id, 'uf' => 'SP', 'timezone' => 'America/Manaus']);
        $sector = Sector::factory()->for($manaus)->create();
        $this->qualify($manaus, $sector, until: '2026-10-31');

        // 31/10 23:30 in Manaus is 01/11 00:30 in São Paulo, still the last valid day in Manaus.
        $start = CarbonImmutable::parse('2026-10-31 22:00', 'America/Manaus');
        $end = CarbonImmutable::parse('2026-10-31 23:30', 'America/Manaus');

        $this->assertTrue(Eligibility::check($this->professional, $sector, $this->doctor, $start, $end)->eligible());
    }

    public function test_shift_in_another_uf_than_the_base_unit_is_blocked(): void
    {
        $rio = Unit::factory()->create(['tenant_id' => $this->hospitalA->tenant_id, 'uf' => 'RJ', 'timezone' => 'America/Sao_Paulo']);
        $sector = Sector::factory()->for($rio)->create();
        $this->qualify($rio, $sector);

        $this->assertSame([EligibilityReason::DifferentUf], $this->check($sector)->reasons);
        // A swap passes the other shift's UF as reference.
        $this->assertTrue($this->check($sector, referenceUf: 'RJ')->eligible());
    }

    public function test_incomplete_unit_and_invalid_period_are_reported(): void
    {
        $this->qualify($this->hospitalA, $this->icuA);

        $this->assertSame([EligibilityReason::InvalidPeriod], $this->check($this->icuA, '2026-10-20 19:00', '2026-10-20 07:00')->reasons);

        $this->hospitalA->update(['timezone' => null]);
        $this->assertSame([EligibilityReason::UnitIncomplete], $this->check($this->icuA)->reasons);
    }

    public function test_every_qualification_change_bumps_the_eligibility_version(): void
    {
        $qualification = $this->qualify($this->hospitalA, $this->icuA, QualificationStatus::Pending);
        $this->assertSame(1, $this->check($this->icuA)->eligibilityVersion);

        $qualification->transitionTo(QualificationStatus::Approved, null);
        $this->assertSame(2, $this->check($this->icuA)->eligibilityVersion);
    }

    public function test_revoked_qualification_can_be_requested_again(): void
    {
        $this->qualify($this->hospitalA, $this->icuA, QualificationStatus::Revoked);
        $this->qualify($this->hospitalA, $this->icuA);

        $this->assertTrue($this->check($this->icuA)->eligible());
    }
}
