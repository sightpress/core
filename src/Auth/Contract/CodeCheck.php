<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * What {@see VerificationCodes::check()} found: the outcome, the account the code was made for (when it was valid
 * and named one) and, when the client was limited, how many seconds it must wait.
 */
final readonly class CodeCheck
{
    /**
     * @param positive-int|null $retryAfter
     */
    public function __construct(
        public CodeStatus $status,
        public ?int $accountId = null,
        public ?int $retryAfter = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->status === CodeStatus::Valid;
    }
}
