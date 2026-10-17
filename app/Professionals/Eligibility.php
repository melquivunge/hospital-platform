<?php

namespace App\Professionals;

use App\Models\JobFunction;
use App\Models\Professional;
use App\Models\Qualification;
use App\Models\Sector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Eligibility of a professional for a shift (PRODUCT.md, "Elegibilidade por unidade e estado"):
 * same network, same UF, qualification approved for the unit and function, valid for the whole
 * shift in the unit's local dates, and the sector authorized. Nothing is inherited between units.
 *
 * Callers that assign must run this inside the transaction that holds the professional lock and
 * record eligibilityVersion with the decision.
 */
final readonly class Eligibility
{
    /**
     * @param  list<EligibilityReason>  $reasons
     */
    private function __construct(
        public array $reasons,
        public int $eligibilityVersion,
        public ?int $qualificationId,
    ) {}

    public function eligible(): bool
    {
        return $this->reasons === [];
    }

    /**
     * $referenceUf is the UF the shift must match: the base unit's UF by default (vacancies and
     * transfers); a swap passes the UF of the other shift.
     */
    public static function check(
        Professional $professional,
        Sector $sector,
        JobFunction $function,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $referenceUf = null,
    ): self {
        $unit = $sector->unit()->firstOrFail();
        // Read from the database: an in-memory model may be stale or lack the column default.
        $version = (int) Professional::withoutTenantScope()->whereKey($professional->id)->value('eligibility_version');

        if ($start >= $end) {
            return new self([EligibilityReason::InvalidPeriod], $version, null);
        }

        if ($professional->tenant_id !== $unit->tenant_id || $function->tenant_id !== $unit->tenant_id) {
            return new self([EligibilityReason::OtherNetwork], $version, null);
        }

        if (! $unit->isComplete()) {
            return new self([EligibilityReason::UnitIncomplete], $version, null);
        }

        $reasons = [];

        if ($unit->uf !== ($referenceUf ?? $professional->baseUnit()->value('uf'))) {
            $reasons[] = EligibilityReason::DifferentUf;
        }

        $qualification = Qualification::query()
            ->where('professional_id', $professional->id)
            ->where('unit_id', $unit->id)
            ->where('job_function_id', $function->id)
            ->orderByRaw("status = 'revoked'")
            ->orderByDesc('id')
            ->first();

        if ($qualification === null) {
            return new self([...$reasons, EligibilityReason::NoQualification], $version, null);
        }

        $statusReason = match ($qualification->status) {
            QualificationStatus::Approved => null,
            QualificationStatus::Pending => EligibilityReason::QualificationPending,
            QualificationStatus::Suspended => EligibilityReason::QualificationSuspended,
            QualificationStatus::Revoked => EligibilityReason::QualificationRevoked,
        };

        if ($statusReason !== null) {
            $reasons[] = $statusReason;
        }

        // Validity dates are local to the unit: [valid_from 00:00, day after valid_until 00:00).
        $timezone = (string) $unit->timezone;
        $validFrom = CarbonImmutable::parse($qualification->valid_from->toDateString(), $timezone);
        $validUntil = $qualification->valid_until === null
            ? null
            : CarbonImmutable::parse($qualification->valid_until->toDateString(), $timezone)->addDay();

        if ($start < $validFrom || ($validUntil !== null && $end > $validUntil)) {
            $reasons[] = EligibilityReason::OutsideValidity;
        }

        if (! $qualification->sectors()->whereKey($sector->id)->exists()) {
            $reasons[] = EligibilityReason::SectorNotAuthorized;
        }

        return new self($reasons, $version, $qualification->id);
    }
}
