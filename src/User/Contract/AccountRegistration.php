<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

/**
 * Creates accounts for every way of joining a site (an accepted invitation now; registration later). Each new
 * account is active, has both names, a time zone and a generated avatar.
 */
interface AccountRegistration
{
    /**
     * @param string $timezone     IANA time zone
     * @param string $passwordHash argon2id hash made by the caller
     *
     * @throws AccountException when the email address is already used, or a name or the time zone isn't valid
     */
    public function register(
        string $email,
        string $firstName,
        string $lastName,
        ?string $displayName,
        string $timezone,
        #[\SensitiveParameter] string $passwordHash,
    ): Account;
}
