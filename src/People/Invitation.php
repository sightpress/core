<?php

declare(strict_types=1);

namespace SightPress\Core\People;

use DateTimeImmutable;

/**
 * An invitation to join the site with a role. The names are what the inviter knew, if anything; the person can
 * change them when they accept. Times are UTC.
 */
final readonly class Invitation
{
    public function __construct(
        public int $id,
        public string $email,
        public string $role,
        public ?string $firstName,
        public ?string $lastName,
        public ?int $invitedBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $acceptedAt = null,
    ) {
    }
}
