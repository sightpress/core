<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Contract;

/**
 * A number replied STOP or START to the site's text messages (N-2), as the provider's signed webhook reported it.
 * Audited with the number masked; `numberHash` is {@see \SightPress\Core\Sms\SmsNumberHash} of it, so accounts with
 * that number can be found without the number (N-3).
 */
final readonly class TextMessageOptChanged
{
    public function __construct(
        public string $maskedNumber,
        public string $numberHash,
        public bool $stopped,
    ) {
    }
}
