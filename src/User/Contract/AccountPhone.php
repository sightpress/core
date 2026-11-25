<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

use DateTimeImmutable;

/**
 * An account's mobile number and text message choices (N-3), for other modules. The number is personal data: show it
 * only to its owner and to people who manage users, and masked everywhere else.
 */
final readonly class AccountPhone
{
    /**
     * @param non-empty-string|null $e164          the confirmed number, or null
     * @param list<string>          $notifications the notifications that go by text
     */
    public function __construct(
        public ?string $e164,
        public ?DateTimeImmutable $verifiedAt,
        public bool $wantsTexts,
        public array $notifications,
        public ?DateTimeImmutable $optedOutAt,
    ) {
    }

    public function hasNumber(): bool
    {
        return $this->e164 !== null;
    }

    /**
     * Whether this notification goes to the person by text.
     */
    public function wants(string $notification): bool
    {
        return $this->wantsTexts && in_array($notification, $this->notifications, true);
    }
}
