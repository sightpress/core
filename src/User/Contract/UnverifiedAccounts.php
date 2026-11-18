<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

use DateTimeImmutable;

/**
 * Accounts people make for themselves (self-registration, U-1). Such an account can't sign in until the person types
 * the code we emailed them ({@see confirm()}); it gets its avatar then. One that is never confirmed is removed after a
 * week ({@see forgetUnconfirmed()}).
 */
interface UnverifiedAccounts
{
    /**
     * Makes an account that waits for its email address to be confirmed. When the address already has such an
     * account, that one gets the new details and password instead (whoever proves the address keeps it). Null when
     * the address belongs to any other account: the caller tells its owner, never the visitor.
     *
     * @param string  $timezone     IANA time zone
     * @param ?string $locale       the person's language, or null for the site's
     * @param string  $passwordHash argon2id hash made by the caller
     *
     * @throws AccountException when a name, the time zone or the language isn't valid
     */
    public function startUnverified(
        string $email,
        string $firstName,
        string $lastName,
        ?string $displayName,
        string $timezone,
        ?string $locale,
        #[\SensitiveParameter] string $passwordHash,
    ): ?Account;

    /**
     * The account was confirmed: it can sign in and gets its avatar. Null when there is no account with this id
     * waiting to be confirmed.
     */
    public function confirm(int $id): ?Account;

    /**
     * Removes the accounts still waiting to be confirmed that were made before this time.
     *
     * @return int how many were removed
     */
    public function forgetUnconfirmed(DateTimeImmutable $madeBefore): int;
}
