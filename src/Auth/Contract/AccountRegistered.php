<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * Someone who signed up themselves typed the code we emailed them: their account can sign in now, with the site's
 * sign-up role. The audit log records it.
 */
final readonly class AccountRegistered
{
    /**
     * @param string  $role        the role the account got
     * @param ?string $refusedRole the stored sign-up role when it wasn't safe for people who sign up (or no longer
     *                             exists), so the account got `member` instead; null when it was used
     */
    public function __construct(
        public int $userId,
        public string $role,
        public ?string $refusedRole = null,
    ) {
    }
}
