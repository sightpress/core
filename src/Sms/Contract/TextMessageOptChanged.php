<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Contract;

use SightPress\Module\Sms\PhoneNumber;

/**
 * A number replied STOP or START to the site's text messages (N-2), as the provider's signed webhook reported it.
 * Audited with the number masked; `numberHash` is {@see \SightPress\Core\Sms\SmsNumberHash} of it. The number
 * itself is only for finding the account that has it (N-3): never write it anywhere.
 */
final readonly class TextMessageOptChanged
{
    public function __construct(
        public PhoneNumber $number,
        public string $maskedNumber,
        public string $numberHash,
        public bool $stopped,
    ) {
    }
}
