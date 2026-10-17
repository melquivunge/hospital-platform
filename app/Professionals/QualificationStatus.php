<?php

namespace App\Professionals;

/**
 * pending → approved ⇄ suspended; any non-final → revoked (final). Same list as qualifications_status_check.
 */
enum QualificationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Revoked],
            self::Approved => [self::Suspended, self::Revoked],
            self::Suspended => [self::Approved, self::Revoked],
            self::Revoked => [],
        };
    }
}
