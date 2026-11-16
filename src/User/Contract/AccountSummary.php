<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

/**
 * What the admin's list of people shows about an account.
 */
final readonly class AccountSummary
{
    public const string ACTIVE = 'active';
    public const string PENDING = 'pending';
    public const string DEACTIVATED = 'deactivated';

    /**
     * @param string  $status    {@see ACTIVE}, {@see PENDING} (no password set yet) or {@see DEACTIVATED}
     * @param ?string $avatarUrl the 64 px avatar, or null when there is none
     */
    public function __construct(
        public int $id,
        public string $email,
        public string $effectiveName,
        public string $fullName,
        public string $status,
        public ?string $avatarUrl,
    ) {
    }

    public function isDeactivated(): bool
    {
        return $this->status === self::DEACTIVATED;
    }
}
