<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * Someone typed a two-step confirmation code (N-6), right (`confirmed`) or wrong. Audited with the number masked.
 */
final readonly class StepUpChecked
{
    public function __construct(
        public int $actorId,
        public string $maskedNumber,
        public bool $confirmed,
    ) {
    }
}
