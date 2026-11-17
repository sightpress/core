<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

use DateTimeImmutable;

/**
 * A code that was just made: the six digits to send (never stored) and when it stops working (UTC).
 */
final readonly class IssuedCode
{
    /**
     * @param non-empty-string $code six digits, for example `042917`
     */
    public function __construct(
        #[\SensitiveParameter] public string $code,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * The code the way people read it: `042 917`.
     */
    public function readable(): string
    {
        return substr($this->code, 0, 3) . ' ' . substr($this->code, 3);
    }
}
