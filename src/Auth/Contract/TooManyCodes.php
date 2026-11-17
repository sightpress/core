<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

use RuntimeException;

/**
 * A new code was asked for too soon (a minute after the last one), too often for the address, or too often from
 * the client. {@see $retryAfter} says how many seconds to wait; {@see $tooSoon} is true when the only reason is
 * the minute between two codes (the last code still works).
 */
final class TooManyCodes extends RuntimeException
{
    /**
     * @param positive-int $retryAfter
     */
    public function __construct(public readonly int $retryAfter, public readonly bool $tooSoon = false)
    {
        parent::__construct(sprintf('Too many verification codes: wait %d seconds.', $retryAfter));
    }
}
