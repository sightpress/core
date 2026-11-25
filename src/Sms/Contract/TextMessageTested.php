<?php

declare(strict_types=1);

namespace SightPress\Core\Sms\Contract;

/**
 * Someone sent the site's test text message (N-2), from the console (`actorId` null) or the Text messages screen.
 * Audited with the number masked.
 */
final readonly class TextMessageTested
{
    public function __construct(
        public ?int $actorId,
        public string $maskedNumber,
        public string $provider,
        public bool $sent,
        public ?string $failure = null,
    ) {
    }
}
