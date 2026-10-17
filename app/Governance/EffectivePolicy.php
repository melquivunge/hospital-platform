<?php

namespace App\Governance;

use App\Models\PolicyVersion;

/**
 * Value in force for a unit. Callers that decide an operation record both version ids.
 */
final readonly class EffectivePolicy
{
    public function __construct(
        public int $value,
        public PolicyVersion $network,
        public ?PolicyVersion $local,
        public bool $localIgnored,
    ) {}

    public function origin(): string
    {
        return $this->local === null ? 'network' : 'unit';
    }
}
